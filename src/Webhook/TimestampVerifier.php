<?php

/**
 * Webhook timestamp freshness check.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

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

        return $diff <= self::tolerance_ms();
    }

    /**
     * Resolve the freshness window (in milliseconds), filterable so a site with
     * known clock skew on shared hosting can widen it rather than have every
     * webhook silently rejected. Keep the host on NTP first — this is a safety
     * valve, not a substitute. Values are floored at the default to avoid
     * accidentally narrowing the replay window.
     */
    public static function tolerance_ms(): int
    {
        $filtered = apply_filters('wc_maya_webhook_timestamp_tolerance_ms', self::TOLERANCE_MS);

        return is_int($filtered) && $filtered >= self::TOLERANCE_MS ? $filtered : self::TOLERANCE_MS;
    }
}
