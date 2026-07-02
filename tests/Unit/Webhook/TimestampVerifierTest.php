<?php

/**
 * Unit tests for TimestampVerifier.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use RogueTechPhilippines\MayaGateway\Webhook\TimestampVerifier;

test('accepts a timestamp inside the ±300s window', function (): void {
    $now    = 1_700_000_000_000;
    $recent = (string) ($now - 200_000); // 200s ago

    expect(TimestampVerifier::within_tolerance($recent, $now))->toBeTrue();
});

test('rejects a timestamp older than 300s', function (): void {
    $now = 1_700_000_000_000;
    $old = (string) ($now - 400_000);

    expect(TimestampVerifier::within_tolerance($old, $now))->toBeFalse();
});

test('rejects a timestamp from too far in the future', function (): void {
    $now    = 1_700_000_000_000;
    $future = (string) ($now + 400_000);

    expect(TimestampVerifier::within_tolerance($future, $now))->toBeFalse();
});

test('rejects an empty or non-numeric timestamp', function (): void {
    expect(TimestampVerifier::within_tolerance('', 1_700_000_000_000))->toBeFalse();
    expect(TimestampVerifier::within_tolerance('abc', 1_700_000_000_000))->toBeFalse();
    expect(TimestampVerifier::within_tolerance('-100', 1_700_000_000_000))->toBeFalse();
});

test('defaults to the wall clock when no $now is provided', function (): void {
    $now_ms = (string) (int) floor(microtime(true) * 1000);

    expect(TimestampVerifier::within_tolerance($now_ms))->toBeTrue();
});

test('the tolerance filter can widen the window for clock-skewed hosts', function (): void {
    \Brain\Monkey\Filters\expectApplied('wc_maya_webhook_timestamp_tolerance_ms')->andReturn(600_000);

    $now  = 1_700_000_000_000;
    $skew = (string) ($now - 500_000); // outside the default 300s, inside 600s

    expect(TimestampVerifier::within_tolerance($skew, $now))->toBeTrue();
});

test('the tolerance filter cannot narrow the window below the default', function (): void {
    \Brain\Monkey\Filters\expectApplied('wc_maya_webhook_timestamp_tolerance_ms')->andReturn(1_000);

    expect(TimestampVerifier::tolerance_ms())->toBe(TimestampVerifier::TOLERANCE_MS);
});
