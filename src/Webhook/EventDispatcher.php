<?php

/**
 * Translates a verified webhook event into a WC order state change.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\WebhookEvent;
use WC_Order;

/**
 * Verified-event → order-state machine.
 *
 * Called from {@see WebhookHandler::process()} once signature/timestamp/IP
 * checks have passed. Idempotent: the only state mutation for a paid order
 * is logging that we skipped it.
 *
 * Phase 4 mappings (only the non-manual-capture branch — Phase 5 layers in
 * the authorize/capture branch on top):
 *
 *  - `PAYMENT_SUCCESS` with matching amount → `$order->payment_complete($id)`.
 *  - `PAYMENT_SUCCESS` with amount mismatch → log error + order note; no
 *    state change (the merchant decides).
 *  - `PAYMENT_FAILED` / `PAYMENT_EXPIRED` / `AUTH_FAILED` → `update_status('failed')`.
 *  - Anything else (CHECKOUT_*, AUTHORIZED, …) → log + skip; later phases
 *    extend the match.
 */
class EventDispatcher
{
    /**
     * Currency-safe amount tolerance. Maya sends decimals (e.g. 199.5);
     * floating-point round-trips can leave a sub-cent difference. We accept
     * anything inside half a cent as "matching".
     */
    public const AMOUNT_TOLERANCE = 0.005;

    public function __construct(private readonly Logger $logger) {}

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

        if (WebhookEvent::PaymentSuccess === $event) {
            return $this->complete_payment($order, $payload);
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
