<?php

/**
 * Per-order webhook event ledger.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;
use WC_Order;

/**
 * Records a normalized snapshot of every *terminally-processed* Maya webhook on
 * the order it affected, and answers "have we already terminally handled this
 * exact event?".
 *
 * Purpose (defense-in-depth, not the correctness mechanism — that is the
 * monotonic "paid is a floor" guard in {@see EventDispatcher::dispatch}):
 *
 *  - **Replay de-dup:** a signed webhook replayed inside the ±300s window (or
 *    redelivered by Maya) won't re-run a terminal action and produce duplicate
 *    order notes. Keyed on the Maya *payment id* (`payload['id']`) — the thing
 *    that identifies "this money moved" — falling back to `event:rrn`.
 *  - **Audit trail:** a human answering "customer paid but sees no order" can
 *    read the exact events this order received and when.
 *  - **Reconciliation seed:** the stored `{amount, currency, status}` per event
 *    is the data a future "WC paid vs Maya settlement" view will read.
 *
 * Only *terminal* outcomes are recorded, so the {@see RetryQueue} can still
 * replay non-terminal transients (order_not_found, lookup failures) — those
 * never enter the ledger and therefore never read as duplicates.
 *
 * The log is capped at {@see MAX_ENTRIES} newest entries so a pathological
 * replay storm can't grow order meta without bound.
 */
final class WebhookLedger
{
    public const MAX_ENTRIES = 50;

    /**
     * Dispatch `action` values that represent a completed, non-retryable state
     * change worth recording (and de-duplicating on).
     *
     * @var list<string>
     */
    public const TERMINAL_ACTIONS = [
        'payment_complete',
        'payment_complete_full_capture',
        'failed',
    ];

    /**
     * Stable idempotency key for an event: prefer the Maya payment id, fall
     * back to the reference number so terminal failures (which may omit `id`)
     * are still de-duplicated per order.
     *
     * @param array<string,mixed> $payload
     */
    public static function entry_key(WebhookEvent $event, array $payload): string
    {
        $id = isset($payload['id']) && is_string($payload['id']) ? trim($payload['id']) : '';
        if ('' !== $id) {
            return $event->value . ':' . $id;
        }

        $rrn = isset($payload['requestReferenceNumber']) && (is_string($payload['requestReferenceNumber']) || is_int($payload['requestReferenceNumber']))
            ? (string) $payload['requestReferenceNumber']
            : '';

        return $event->value . ':rrn:' . $rrn;
    }

    public static function is_terminal_action(string $action): bool
    {
        return in_array($action, self::TERMINAL_ACTIONS, true);
    }

    /**
     * Has this exact event already been terminally processed for this order?
     */
    public static function has(WC_Order $order, string $key): bool
    {
        foreach (self::read($order) as $entry) {
            if (isset($entry['key']) && $entry['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Append a normalized snapshot for a terminal outcome and persist it.
     *
     * @param array<string,mixed> $payload
     */
    public static function record(WC_Order $order, WebhookEvent $event, array $payload, string $action): void
    {
        if (! self::is_terminal_action($action)) {
            return;
        }

        $log   = self::read($order);
        $log[] = [
            'key'         => self::entry_key($event, $payload),
            'event'       => $event->value,
            'action'      => $action,
            'payment_id'  => isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : null,
            'amount'      => isset($payload['amount']) && is_numeric($payload['amount']) ? (float) $payload['amount'] : null,
            'currency'    => isset($payload['currency']) && is_string($payload['currency']) ? strtoupper($payload['currency']) : null,
            'received_at' => gmdate('c'),
        ];

        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -self::MAX_ENTRIES);
        }

        $order->update_meta_data(MayaGateway::META_WEBHOOK_LOG, (string) wp_json_encode($log));
        $order->save();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function read(WC_Order $order): array
    {
        $raw = (string) $order->get_meta(MayaGateway::META_WEBHOOK_LOG);
        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
