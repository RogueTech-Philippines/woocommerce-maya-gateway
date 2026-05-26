<?php

/**
 * Translates a verified webhook event into a WC order state change.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Util\IdempotencyKey;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\AuthorizationType;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use TaniKyuun\MayaGateway\Value\WebhookEvent;
use WC_Order;
use WP_Error;

/**
 * Verified-event → order-state machine.
 *
 * Called from {@see WebhookHandler::process()} once signature/timestamp/IP
 * checks have passed. Idempotent: the only state mutation for a paid order
 * is logging that we skipped it.
 *
 * Mappings (Phase 5):
 *
 *  - `PAYMENT_SUCCESS` on an immediate-capture order (auth type `none`):
 *    matching amount → `payment_complete($id)`; mismatch → log + order note
 *    and leave the order alone.
 *  - `PAYMENT_SUCCESS` on a *manual-capture* order: complete only when the
 *    payment's `capturedAmount` equals `amount` (full capture reached);
 *    otherwise add a partial-capture note and keep the order in
 *    `processing`. Each successive capture re-fires this branch until the
 *    last one promotes the order.
 *  - `AUTHORIZED` on a manual-capture order: add a "authorized, awaiting
 *    capture" note (no state change — the order's already in `processing`
 *    via the ReturnHandler).
 *  - `PAYMENT_FAILED` / `PAYMENT_EXPIRED` / `AUTH_FAILED` →
 *    `update_status('failed')`.
 *  - Anything else (CHECKOUT_*) → log + skip.
 */
class EventDispatcher
{
    /**
     * Currency-safe amount tolerance. Maya sends decimals (e.g. 199.5);
     * floating-point round-trips can leave a sub-cent difference. We accept
     * anything inside half a cent as "matching".
     */
    public const AMOUNT_TOLERANCE = 0.005;

    /**
     * Payments endpoint is optional: only the manual-capture branch needs it.
     * Immediate-capture orders (the most common case) never call it.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?Payments $payments = null,
    ) {}

    /**
     * @param array<string,mixed> $payload Verified webhook payload.
     *
     * @return array{action: string, order_id?: int, payment_id?: string, reference?: string, event?: string, expected?: float, received?: float}
     */
    public function dispatch(WebhookEvent $event, array $payload): array
    {
        $reference = $payload['requestReferenceNumber'] ?? '';
        $order     = self::find_order($reference);

        if (! $order instanceof WC_Order) {
            $this->logger->warning('EventDispatcher: order not found.', [
                'reference' => (string) $reference,
                'event'     => $event->value,
            ]);
            return [ 'action' => 'order_not_found', 'reference' => (string) $reference ];
        }

        // Idempotency: webhooks retry. Once an order is paid we don't want a
        // second PAYMENT_SUCCESS retry to re-trigger payment_complete (which
        // would fire `woocommerce_payment_complete` again and produce
        // duplicate side effects in other plugins).
        if ($order->is_paid()) {
            $this->logger->info('EventDispatcher: order already paid; skipping.', [
                'order_id' => $order->get_id(),
                'event'    => $event->value,
            ]);
            return [ 'action' => 'already_paid', 'order_id' => (int) $order->get_id() ];
        }

        $auth_type = AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE));

        if (WebhookEvent::PaymentSuccess === $event) {
            return $auth_type->is_manual_capture()
                ? $this->complete_manual_capture($order, $payload)
                : $this->complete_payment($order, $payload);
        }

        if (WebhookEvent::Authorized === $event && $auth_type->is_manual_capture()) {
            return $this->note_authorized($order, $payload);
        }

        if (in_array($event, [ WebhookEvent::PaymentFailed, WebhookEvent::PaymentExpired, WebhookEvent::AuthFailed ], true)) {
            return $this->mark_failed($order, $event);
        }

        $this->logger->info('EventDispatcher: event ignored at this phase.', [
            'order_id' => $order->get_id(),
            'event'    => $event->value,
        ]);
        return [ 'action' => 'ignored', 'order_id' => (int) $order->get_id(), 'event' => $event->value ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{action: string, order_id: int, payment_id?: string, expected?: float, received?: float}
     */
    private function complete_payment(WC_Order $order, array $payload): array
    {
        $expected = (float) $order->get_total();
        $received = (float) ($payload['amount'] ?? 0);

        if (abs($expected - $received) >= self::AMOUNT_TOLERANCE) {
            $this->logger->error('EventDispatcher: amount mismatch — leaving order alone.', [
                'order_id' => $order->get_id(),
                'expected' => $expected,
                'received' => $received,
            ]);
            $order->add_order_note(sprintf(
                /* translators: 1: expected amount, 2: received amount. */
                __('Maya PAYMENT_SUCCESS webhook arrived with a mismatched amount (expected %1$s, received %2$s). Order state left unchanged for manual review.', 'wc-maya-gateway'),
                $expected,
                $received,
            ));
            return [
                'action'   => 'amount_mismatch',
                'order_id' => (int) $order->get_id(),
                'expected' => $expected,
                'received' => $received,
            ];
        }

        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        $order->payment_complete($payment_id);

        $this->logger->info('EventDispatcher: payment_complete().', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
        ]);

        return [
            'action'     => 'payment_complete',
            'order_id'   => (int) $order->get_id(),
            'payment_id' => $payment_id,
        ];
    }

    /**
     * Manual-capture branch: only promote to `completed` when the
     * authorization's cumulative captured amount has caught up to its
     * authorized total. Until then, leave the order in `processing` and
     * record the partial in a note.
     *
     * The webhook payload describes only *this* capture event — its
     * `amount`/`id` are the capture's, not the authorization's cumulative
     * state. So we re-fetch the AUTHORIZED record via `Payments::get_by_rrn`
     * (the legacy plugin did the same) and compare its `amount` vs
     * `capturedAmount`. When the Payments endpoint isn't injected (only
     * unit tests skip it), we fail closed with a `lookup_unavailable`
     * action so a missing wiring is loud rather than silent.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{action: string, order_id: int, payment_id?: string, authorized?: float, captured?: float}
     */
    private function complete_manual_capture(WC_Order $order, array $payload): array
    {
        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';

        if (null === $this->payments) {
            $this->logger->error('EventDispatcher: manual-capture branch reached without a Payments endpoint.', [
                'order_id'   => $order->get_id(),
                'payment_id' => $payment_id,
            ]);
            return [
                'action'     => 'manual_capture_lookup_unavailable',
                'order_id'   => (int) $order->get_id(),
                'payment_id' => $payment_id,
            ];
        }

        $records = $this->payments->get_by_rrn(IdempotencyKey::for_order((int) $order->get_id()));
        if ($records instanceof WP_Error) {
            $this->logger->error('EventDispatcher: payment lookup failed during manual-capture check.', [
                'order_id'   => $order->get_id(),
                'payment_id' => $payment_id,
                'code'       => $records->get_error_code(),
                'message'    => $records->get_error_message(),
            ]);
            return [
                'action'     => 'manual_capture_lookup_failed',
                'order_id'   => (int) $order->get_id(),
                'payment_id' => $payment_id,
            ];
        }

        $authorization = self::find_authorization_record($records);
        if (! $authorization instanceof PaymentRecord) {
            $this->logger->warning('EventDispatcher: no AUTHORIZED record on a manual-capture order.', [
                'order_id'   => $order->get_id(),
                'payment_id' => $payment_id,
            ]);
            return [
                'action'     => 'manual_capture_no_authorization',
                'order_id'   => (int) $order->get_id(),
                'payment_id' => $payment_id,
            ];
        }

        $authorized = $authorization->amount->value;
        $captured   = null !== $authorization->captured_amount ? $authorization->captured_amount->value : 0.0;

        if (abs($authorized - $captured) < self::AMOUNT_TOLERANCE) {
            $order->payment_complete($payment_id);
            $this->logger->info('EventDispatcher: manual-capture full → payment_complete().', [
                'order_id'   => $order->get_id(),
                'payment_id' => $payment_id,
                'authorized' => $authorized,
                'captured'   => $captured,
            ]);
            return [
                'action'     => 'payment_complete_full_capture',
                'order_id'   => (int) $order->get_id(),
                'payment_id' => $payment_id,
                'authorized' => $authorized,
                'captured'   => $captured,
            ];
        }

        $order->add_order_note(sprintf(
            /* translators: 1: cumulative captured amount, 2: authorized total. */
            __('Maya partial capture confirmed: %1$s of %2$s captured. Order will complete when the remaining balance is captured.', 'wc-maya-gateway'),
            $captured,
            $authorized,
        ));

        $this->logger->info('EventDispatcher: manual-capture partial — note added, no state change.', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'authorized' => $authorized,
            'captured'   => $captured,
        ]);

        return [
            'action'     => 'partial_capture_note',
            'order_id'   => (int) $order->get_id(),
            'payment_id' => $payment_id,
            'authorized' => $authorized,
            'captured'   => $captured,
        ];
    }

    /**
     * @param list<PaymentRecord> $records
     */
    private static function find_authorization_record(array $records): ?PaymentRecord
    {
        foreach ($records as $record) {
            if ('AUTHORIZED' === $record->status) {
                return $record;
            }
        }
        return null;
    }

    /**
     * AUTHORIZED webhook on a manual-capture order: just record the note so
     * the merchant sees the auth landed. The order is already in
     * `processing` from the ReturnHandler.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{action: string, order_id: int, payment_id?: string}
     */
    private function note_authorized(WC_Order $order, array $payload): array
    {
        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';

        $order->add_order_note(
            __('Maya authorized the payment. Use the Capture panel on this order to capture funds.', 'wc-maya-gateway'),
        );

        $this->logger->info('EventDispatcher: authorized note added.', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
        ]);

        return [
            'action'     => 'authorized_note',
            'order_id'   => (int) $order->get_id(),
            'payment_id' => $payment_id,
        ];
    }

    /**
     * @return array{action: string, order_id: int, event: string}
     */
    private function mark_failed(WC_Order $order, WebhookEvent $event): array
    {
        $note = match ($event) {
            WebhookEvent::PaymentExpired => __('Maya payment expired.', 'wc-maya-gateway'),
            WebhookEvent::AuthFailed     => __('Maya authorization failed.', 'wc-maya-gateway'),
            default                      => __('Maya payment failed.', 'wc-maya-gateway'),
        };

        $order->update_status('failed', $note);

        $this->logger->info('EventDispatcher: order failed.', [
            'order_id' => $order->get_id(),
            'event'    => $event->value,
        ]);

        return [
            'action'   => 'failed',
            'order_id' => (int) $order->get_id(),
            'event'    => $event->value,
        ];
    }

    private static function find_order(mixed $reference): ?WC_Order
    {
        if (! function_exists('wc_get_order')) {
            return null;
        }

        $id = is_numeric($reference) ? (int) $reference : 0;
        if ($id <= 0) {
            return null;
        }

        $order = wc_get_order($id);
        return $order instanceof WC_Order ? $order : null;
    }
}
