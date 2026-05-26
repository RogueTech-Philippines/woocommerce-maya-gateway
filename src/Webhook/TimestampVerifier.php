<?php

/**
 * Webhook timestamp freshness check.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

/**
 * Rejects webhooks whose `X-Maya-Webhook-Timestamp` is too old or too far in
 * the future to be from a live Maya callback. The tolerance window matches
 * the legacy plugin (±300 seconds, expressed in milliseconds because Maya
 * sends epoch-ms).
 *
 * The injected clock lets tests pin "now" without freezing the global clock.
 */
class TimestampVerifier
{
    public const TOLERANCE_MS = 300_000;

    public static function within_tolerance(string $timestamp_ms, ?int $now_ms = null): bool
    {
        if ('' === $timestamp_ms || ! ctype_digit($timestamp_ms)) {
            return false;
        }

        $now  = $now_ms ?? (int) floor(microtime(true) * 1000);
        $diff = abs($now - (int) $timestamp_ms);

        return $diff <= self::TOLERANCE_MS;
    }
}
