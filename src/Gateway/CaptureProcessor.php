<?php

/**
 * Validates + executes a Maya capture against an authorized payment.
 *
 * @package TaniKyuun\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Gateway;

use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Util\IdempotencyKey;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use WC_Order;
use WP_Error;

/**
 * The capture business logic, separated from the AJAX handler so it can be
 * unit-tested without `wp_send_json_*` exits.
 *
 * Contract:
 *
 *  1. Look up payments for the order (via `Payments::get_by_rrn` against
 *     the order's RRN).
 *  2. Pick the first `AUTHORIZED` payment that also has `canCapture: true`
 *     (Maya marks an authorization non-capturable once it expires or the
 *     remaining balance hits zero).
 *  3. Validate `$amount` is positive and ≤ remaining (authorized − already-captured).
 *  4. POST the capture to Maya with `requestReferenceNumber` set to the order id.
 *  5. Return the updated balance trio so the UI can re-render without re-fetching.
 *
 * The processor never mutates the order itself — the merchant sees the
 * capture happen, but the order's WC status flip waits for the
 * `PAYMENT_SUCCESS` webhook the capture triggers. Keeps the source of truth
 * single (the webhook) and avoids races between the API response and the
 * webhook arriving.
 */
class CaptureProcessor
{
    public function __construct(
        private readonly Payments $endpoint,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array{
     *     action: 'captured',
     *     order_id: int,
     *     payment_id: string,
     *     amount_authorized: float,
     *     amount_captured: float,
     *     amount_remaining: float,
     *     currency: string,
     * }|WP_Error
     */
    public function capture(WC_Order $order, float $amount): array|WP_Error
    {
        if ($amount <= 0) {
            return new WP_Error(
                'wc_maya_capture_invalid_amount',
                __('Capture amount must be greater than zero.', 'wc-maya-gateway'),
            );
        }

        $rrn      = IdempotencyKey::for_order((int) $order->get_id());
        $payments = $this->endpoint->get_by_rrn($rrn);

        if ($payments instanceof WP_Error) {
            $this->logger->error('CaptureProcessor: get_by_rrn failed.', [
                'order_id' => $order->get_id(),
                'message'  => $payments->get_error_message(),
            ]);
            return $payments;
        }

        $authorized = self::find_capturable_payment($payments);
        if (null === $authorized) {
            return new WP_Error(
                'wc_maya_capture_no_authorized_payment',
                __('No AUTHORIZED payment with remaining capturable balance was found for this order.', 'wc-maya-gateway'),
            );
        }

        $already_captured = null !== $authorized->captured_amount ? $authorized->captured_amount->value : 0.0;
        $remaining        = $authorized->amount->value - $already_captured;

        // 0.005 tolerance matches EventDispatcher: floating-point noise should
        // not block an honest "capture the remaining cents" request.
        if ($amount - $remaining > 0.005) {
            return new WP_Error(
                'wc_maya_capture_exceeds_remaining',
                sprintf(
                    /* translators: 1: requested amount, 2: remaining capturable amount. */
                    __('Capture amount %1$s exceeds the remaining capturable balance %2$s.', 'wc-maya-gateway'),
                    $amount,
                    $remaining,
                ),
            );
        }

        $response = $this->endpoint->capture(
            $authorized->id,
            [
                'requestReferenceNumber' => $rrn,
                'captureAmount'          => [
                    'amount'   => $amount,
                    'currency' => (string) $order->get_currency(),
                ],
            ],
        );

        if ($response instanceof WP_Error) {
            $this->logger->error('CaptureProcessor: capture failed.', [
                'order_id'   => $order->get_id(),
                'payment_id' => $authorized->id,
                'amount'     => $amount,
                'message'    => $response->get_error_message(),
            ]);
            return $response;
        }

        // Maya's capture response always echoes capturedAmount; if it doesn't,
        // refuse to compute a fallback (silent client-side math hides state
        // drift). The merchant should refresh and re-fetch from Maya.
        if (null === $response->captured_amount) {
            $this->logger->error('CaptureProcessor: capture response missing capturedAmount.', [
                'order_id'   => $order->get_id(),
                'payment_id' => $authorized->id,
                'amount'     => $amount,
            ]);
            return new WP_Error(
                'wc_maya_capture_response_invalid',
                __('Maya capture response did not include capturedAmount. Refresh the order page to re-fetch the current balance from Maya.', 'wc-maya-gateway'),
            );
        }

        $new_captured  = $response->captured_amount->value;
        $new_remaining = $response->amount->value - $new_captured;

        $order->add_order_note(sprintf(
            /* translators: 1: captured amount, 2: cumulative captured, 3: authorized total. */
            __('Maya capture: %1$s captured (total captured %2$s of %3$s authorized).', 'wc-maya-gateway'),
            $amount,
            $new_captured,
            $response->amount->value,
        ));

        $this->logger->info('CaptureProcessor: capture succeeded.', [
            'order_id'         => $order->get_id(),
            'payment_id'       => $authorized->id,
            'amount'           => $amount,
            'amount_captured'  => $new_captured,
            'amount_remaining' => $new_remaining,
        ]);

        return [
            'action'            => 'captured',
            'order_id'          => (int) $order->get_id(),
            'payment_id'        => $authorized->id,
            'amount_authorized' => $response->amount->value,
            'amount_captured'   => $new_captured,
            'amount_remaining'  => $new_remaining,
            'currency'          => $response->amount->currency,
        ];
    }

    /**
     * Find the first `AUTHORIZED` payment for which Maya still permits a
     * capture. Returns null when no such payment exists (e.g. authorization
     * expired or already fully captured).
     *
     * @param list<PaymentRecord> $payments
     */
    public static function find_capturable_payment(array $payments): ?PaymentRecord
    {
        foreach ($payments as $payment) {
            if ('AUTHORIZED' === $payment->status && $payment->can_capture) {
                return $payment;
            }
        }
        return null;
    }
}
