<?php

/**
 * Smart-picks void vs refund for a WC refund request, with partial-amount
 * splitting across multiple captured payments.
 *
 * @package RogueTechPhilippines\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Gateway;

use RogueTechPhilippines\MayaGateway\Api\Endpoints\Payments;
use RogueTechPhilippines\MayaGateway\Util\IdempotencyKey;
use RogueTechPhilippines\MayaGateway\Util\Logger;
use RogueTechPhilippines\MayaGateway\Value\AuthorizationType;
use RogueTechPhilippines\MayaGateway\Value\PaymentRecord;
use RogueTechPhilippines\MayaGateway\Value\RefundRecord;
use WC_Order;
use WP_Error;

/**
 * Handles `process_refund` for both immediate-capture and manual-capture
 * orders. Two branches:
 *
 *  - **Immediate-capture orders** (no `_maya_authorization_type` or the
 *    setting was `none`): there's a single PAYMENT_SUCCESS payment on the
 *    RRN. If Maya still permits a void *and* the requested amount equals
 *    the full payment, void it (cheaper for the customer's statement —
 *    Maya never shows the charge). Otherwise refund the requested amount
 *    against the PAYMENT_SUCCESS payment.
 *
 *  - **Manual-capture orders** (auth type is normal/final/preauthorization):
 *    if no captures have happened yet (only the AUTHORIZED record exists),
 *    we can only void — and only the full amount. Maya rejects partial
 *    voids. If captures have happened, we walk the captured payments in
 *    chronological order, voiding fully-capturable ones outright and
 *    refunding the rest until the requested amount is exhausted.
 *
 * The planner that picks the action list ({@see plan_capture_actions()}) is a
 * pure static so unit tests can exhaustively cover the void-vs-refund and
 * partial-split branches without booting Maya. The orchestrator calls
 * Maya for `get_refunds` to compute each capture's remaining refundable
 * balance, then hands the typed action list to the planner.
 *
 * Algorithm ported from `wc-maya-payment-gateway/classes/maya-gateway.php`
 * lines 809-1104.
 */
class RefundProcessor
{
    /**
     * Currency tolerance, same as EventDispatcher: 0.005 absorbs FP noise
     * without misclassifying genuine "full vs partial" comparisons.
     */
    public const AMOUNT_TOLERANCE = 0.005;

    public function __construct(
        private readonly Payments $endpoint,
        private readonly Logger $logger,
    ) {}

    /**
     * @return true|WP_Error
     */
    public function process(WC_Order $order, float $amount, string $reason): true|WP_Error
    {
        if ($amount <= 0) {
            return new WP_Error(
                'wc_maya_refund_invalid_amount',
                __('Refund amount must be greater than zero.', 'wc-maya-gateway'),
            );
        }

        $rrn      = IdempotencyKey::for_order((int) $order->get_id());
        $payments = $this->endpoint->get_by_rrn($rrn);
        if ($payments instanceof WP_Error) {
            $this->logger->error('RefundProcessor: payment lookup failed.', [
                'order_id' => $order->get_id(),
                'message'  => $payments->get_error_message(),
            ]);
            return $payments;
        }

        $auth_type = AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE));

        if (! $auth_type->is_manual_capture()) {
            return $this->process_immediate_capture($order, $payments, $amount, $reason);
        }

        return $this->process_manual_capture($order, $payments, $amount, $reason);
    }

    /**
     * @param list<PaymentRecord> $payments
     *
     * @return true|WP_Error
     */
    private function process_immediate_capture(WC_Order $order, array $payments, float $amount, string $reason): true|WP_Error
    {
        $target = null;
        foreach ($payments as $payment) {
            if (in_array($payment->status, [ 'PAYMENT_SUCCESS', 'REFUNDED' ], true)) {
                $target = $payment;
                break;
            }
        }

        if (null === $target) {
            return new WP_Error(
                'wc_maya_refund_no_payment',
                __('No completed Maya payment was found to refund against.', 'wc-maya-gateway'),
            );
        }

        if ($target->can_void) {
            if (abs($amount - $target->amount->value) < self::AMOUNT_TOLERANCE) {
                return $this->run_void($order, $target->id, $reason);
            }
            // canVoid but not the full amount: Maya rejects partial voids.
            // Fall through to the refund branch IF the payment also accepts
            // refunds (rare but possible during the brief canVoid window).
            // Otherwise error out so the merchant knows to wait for capture.
            if (! $target->can_refund) {
                return new WP_Error(
                    'wc_maya_refund_partial_void',
                    __('Maya does not allow partial voids — refund the full amount or wait for capture to enable partial refunds.', 'wc-maya-gateway'),
                );
            }
        }

        if (! $target->can_refund) {
            return new WP_Error(
                'wc_maya_refund_not_refundable',
                __('Maya reports the payment cannot be refunded at this time.', 'wc-maya-gateway'),
            );
        }

        return $this->run_refund($order, $target->id, $amount, $target->amount->currency, $reason);
    }

    /**
     * @param list<PaymentRecord> $payments
     *
     * @return true|WP_Error
     */
    private function process_manual_capture(WC_Order $order, array $payments, float $amount, string $reason): true|WP_Error
    {
        $authorization = null;
        foreach ($payments as $payment) {
            if ($payment->is_authorization()) {
                $authorization = $payment;
                break;
            }
        }

        if (null === $authorization) {
            return new WP_Error(
                'wc_maya_refund_no_authorization',
                __('No AUTHORIZED Maya payment was found for this order.', 'wc-maya-gateway'),
            );
        }

        // Only the authorization exists — no captures have happened yet. Maya
        // only allows full-amount voids in this state.
        if (1 === count($payments)) {
            if (! $authorization->can_void) {
                return new WP_Error(
                    'wc_maya_refund_authorization_locked',
                    __('Maya no longer permits voiding this authorization (it likely expired or was captured).', 'wc-maya-gateway'),
                );
            }
            if (abs($amount - $authorization->amount->value) >= self::AMOUNT_TOLERANCE) {
                return new WP_Error(
                    'wc_maya_refund_partial_void',
                    __('Authorized-but-uncaptured Maya payments can only be voided in full.', 'wc-maya-gateway'),
                );
            }
            return $this->run_void($order, $authorization->id, $reason);
        }

        $captures = array_values(array_filter(
            $payments,
            static fn(PaymentRecord $p): bool => $p->is_capture,
        ));
        usort($captures, static fn(PaymentRecord $a, PaymentRecord $b): int => strcmp($a->created_at, $b->created_at));

        $available = $this->build_available_actions($captures);
        if ($available instanceof WP_Error) {
            return $available;
        }

        $plan = self::plan_capture_actions($available, $amount);
        if ($plan instanceof WP_Error) {
            return $plan;
        }

        return $this->execute_capture_actions($order, $plan, $reason);
    }

    /**
     * Per-capture pre-flight: figure out what kind of action each captured
     * payment supports and how much of it is still refundable.
     *
     * @param list<PaymentRecord> $captures
     *
     * @return list<array{action: string, payment_id: string, amount: float, currency: string}>|WP_Error
     */
    private function build_available_actions(array $captures): array|WP_Error
    {
        $actions = [];
        foreach ($captures as $capture) {
            if ($capture->can_void) {
                $actions[] = [
                    'action'     => 'void',
                    'payment_id' => $capture->id,
                    'amount'     => $capture->amount->value,
                    'currency'   => $capture->amount->currency,
                ];
                continue;
            }
            if (! $capture->can_refund) {
                continue;
            }

            $refunds = $this->endpoint->get_refunds($capture->id);
            if ($refunds instanceof WP_Error) {
                $this->logger->error('RefundProcessor: get_refunds failed.', [
                    'payment_id' => $capture->id,
                    'message'    => $refunds->get_error_message(),
                ]);
                return $refunds;
            }

            $remaining = self::remaining_refundable($capture, $refunds);
            if ($remaining > 0) {
                $actions[] = [
                    'action'     => 'refund',
                    'payment_id' => $capture->id,
                    'amount'     => $remaining,
                    'currency'   => $capture->amount->currency,
                ];
            }
        }
        return $actions;
    }

    /**
     * Pure planner: walk the available actions chronologically, consume the
     * merchant's `$amount` against them, and return the concrete actions to
     * execute. Pulled out as a public static so tests can exhaustively cover
     * the partial-split decision tree without mocking Maya.
     *
     * @param list<array{action: string, payment_id: string, amount: float, currency: string}> $available
     *
     * @return list<array{action: string, payment_id: string, amount: float, currency: string}>|WP_Error
     */
    public static function plan_capture_actions(array $available, float $amount): array|WP_Error
    {
        $remaining = $amount;
        $plan      = [];

        foreach ($available as $action) {
            if ($remaining <= 0) {
                break;
            }

            if ('void' === $action['action']) {
                if ($action['amount'] - $remaining > self::AMOUNT_TOLERANCE) {
                    // The next available action is a void but we don't have
                    // enough remaining to consume it whole. Maya doesn't
                    // allow partial voids — bail out.
                    return new WP_Error(
                        'wc_maya_refund_partial_void',
                        __('Maya does not allow partial voids; cannot split refund across captured payments.', 'wc-maya-gateway'),
                    );
                }
                $plan[]    = $action;
                $remaining = max(0.0, $remaining - $action['amount']);
                continue;
            }

            // Refund branch: take up to the action's available amount.
            if ($remaining >= $action['amount']) {
                $plan[]    = $action;
                $remaining = max(0.0, $remaining - $action['amount']);
            } else {
                $action['amount'] = $remaining;
                $plan[]           = $action;
                $remaining        = 0.0;
            }
        }

        if ($remaining > self::AMOUNT_TOLERANCE) {
            return new WP_Error(
                'wc_maya_refund_insufficient_balance',
                sprintf(
                    /* translators: %s: amount the planner couldn't cover. */
                    __('Refund amount exceeds the available refundable balance across all captured Maya payments (%s remaining unmatched).', 'wc-maya-gateway'),
                    $remaining,
                ),
            );
        }

        return $plan;
    }

    /**
     * Sum every SUCCESS refund and return what's still refundable on a
     * captured payment.
     *
     * @param list<RefundRecord> $refunds
     */
    public static function remaining_refundable(PaymentRecord $capture, array $refunds): float
    {
        $already = 0.0;
        foreach ($refunds as $refund) {
            if ($refund->is_successful()) {
                $already += $refund->amount->value;
            }
        }
        return max(0.0, $capture->amount->value - $already);
    }

    /**
     * @param list<array{action: string, payment_id: string, amount: float, currency: string}> $plan
     *
     * @return true|WP_Error
     */
    private function execute_capture_actions(WC_Order $order, array $plan, string $reason): true|WP_Error
    {
        if ([] === $plan) {
            return new WP_Error(
                'wc_maya_refund_empty_plan',
                __('Refund planner produced no actions to execute.', 'wc-maya-gateway'),
            );
        }

        foreach ($plan as $action) {
            if ('void' === $action['action']) {
                $result = $this->run_void($order, $action['payment_id'], $reason);
            } else {
                $result = $this->run_refund($order, $action['payment_id'], $action['amount'], $action['currency'], $reason);
            }
            if ($result instanceof WP_Error) {
                // Partial-failure surfacing: log + WP_Error so WC marks the
                // refund failed. The merchant should reconcile via the Maya
                // dashboard and any successful actions already wrote order
                // notes so the trail is preserved.
                return new WP_Error(
                    'wc_maya_refund_partial_failure',
                    sprintf(
                        /* translators: 1: action name (void/refund), 2: Maya error. */
                        __('Maya refund partially failed at %1$s: %2$s. Check the Maya dashboard for the authoritative state.', 'wc-maya-gateway'),
                        $action['action'],
                        $result->get_error_message(),
                    ),
                );
            }
        }

        return true;
    }

    /**
     * @return true|WP_Error
     */
    private function run_void(WC_Order $order, string $payment_id, string $reason): true|WP_Error
    {
        $resolved = '' === trim($reason) ? __('Merchant-initiated void via WooCommerce', 'wc-maya-gateway') : $reason;
        $result   = $this->endpoint->void($payment_id, $resolved);
        if ($result instanceof WP_Error) {
            $this->logger->error('RefundProcessor: void failed.', [
                'payment_id' => $payment_id,
                'message'    => $result->get_error_message(),
            ]);
            return $result;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: Maya payment id, 2: void reason. */
            __('Maya void succeeded for payment %1$s (reason: %2$s).', 'wc-maya-gateway'),
            $payment_id,
            $resolved,
        ));
        return true;
    }

    /**
     * @return true|WP_Error
     */
    private function run_refund(WC_Order $order, string $payment_id, float $amount, string $currency, string $reason): true|WP_Error
    {
        $resolved = '' === trim($reason) ? __('Merchant-initiated refund via WooCommerce', 'wc-maya-gateway') : $reason;
        $result   = $this->endpoint->refund(
            $payment_id,
            [
                'totalAmount' => [ 'amount' => $amount, 'currency' => $currency ],
                'reason'      => $resolved,
            ],
        );
        if ($result instanceof WP_Error) {
            $this->logger->error('RefundProcessor: refund failed.', [
                'payment_id' => $payment_id,
                'amount'     => $amount,
                'message'    => $result->get_error_message(),
            ]);
            return $result;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: refunded amount, 2: Maya payment id, 3: Maya refund id, 4: reason. */
            __('Maya refund %1$s on payment %2$s (refund id %3$s, reason: %4$s).', 'wc-maya-gateway'),
            $amount,
            $payment_id,
            $result->id,
            $resolved,
        ));
        return true;
    }
}
