<?php

/**
 * Unit tests for the Action Scheduler-backed webhook retry queue.
 *
 * The pure policy (`should_schedule`, `plan_delay`) is exercised
 * directly; the AS interaction (`schedule`, `maybe_schedule`) is
 * Brain-Monkey-stubbed so the test suite never touches an actual
 * scheduler.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Webhook\RetryQueue;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

test('should_schedule is true for order_not_found before MAX_ATTEMPTS', function (): void {
    expect(RetryQueue::should_schedule([ 'action' => 'order_not_found' ], 1))->toBeTrue();
    expect(RetryQueue::should_schedule([ 'action' => 'order_not_found' ], 3))->toBeTrue();
});

test('should_schedule is true for manual_capture_lookup_failed', function (): void {
    expect(RetryQueue::should_schedule([ 'action' => 'manual_capture_lookup_failed' ], 1))->toBeTrue();
});

test('should_schedule is true for manual_capture_lookup_unavailable', function (): void {
    expect(RetryQueue::should_schedule([ 'action' => 'manual_capture_lookup_unavailable' ], 1))->toBeTrue();
});

test('should_schedule is false for terminal/non-retryable dispatch actions', function (): void {
    expect(RetryQueue::should_schedule([ 'action' => 'payment_complete' ], 1))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'failed' ], 1))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'already_paid' ], 1))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'amount_mismatch' ], 1))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'ignored' ], 1))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'partial_capture_note' ], 1))->toBeFalse();
});

test('should_schedule is false at or beyond the attempt cap', function (): void {
    expect(RetryQueue::should_schedule([ 'action' => 'order_not_found' ], RetryQueue::MAX_ATTEMPTS))->toBeFalse();
    expect(RetryQueue::should_schedule([ 'action' => 'order_not_found' ], RetryQueue::MAX_ATTEMPTS + 1))->toBeFalse();
});

test('should_schedule is false when no action key is present', function (): void {
    expect(RetryQueue::should_schedule([], 1))->toBeFalse();
});

test('plan_delay follows the documented exponential schedule', function (): void {
    expect(RetryQueue::plan_delay(1))->toBe(60);     // first retry: 1 minute
    expect(RetryQueue::plan_delay(2))->toBe(240);    // 4 minutes
    expect(RetryQueue::plan_delay(3))->toBe(960);    // 16 minutes
    expect(RetryQueue::plan_delay(4))->toBe(3840);   // 64 minutes
});

test('plan_delay floors at 60 seconds even for attempt 0', function (): void {
    expect(RetryQueue::plan_delay(0))->toBe(60);
});

test('MAX_ATTEMPTS is 4 (matches Maya retry budget on their side)', function (): void {
    expect(RetryQueue::MAX_ATTEMPTS)->toBe(4);
});

test('RETRYABLE_ACTIONS lists exactly the three transient dispatch actions', function (): void {
    expect(RetryQueue::RETRYABLE_ACTIONS)->toEqualCanonicalizing([
        'order_not_found',
        'manual_capture_lookup_failed',
        'manual_capture_lookup_unavailable',
    ]);
});

test('maybe_schedule returns 0 when the dispatch action is non-retryable', function (): void {
    $logger = new Logger(false);
    Functions\expect('as_schedule_single_action')->never();

    $result = RetryQueue::maybe_schedule(
        [ 'action' => 'payment_complete' ],
        [ 'requestReferenceNumber' => '42' ],
        1,
        $logger,
    );

    expect($result)->toBe(0);
});

test('maybe_schedule returns 0 at the attempt cap', function (): void {
    $logger = new Logger(false);
    Functions\expect('as_schedule_single_action')->never();

    $result = RetryQueue::maybe_schedule(
        [ 'action' => 'order_not_found' ],
        [ 'requestReferenceNumber' => '42' ],
        RetryQueue::MAX_ATTEMPTS,
        $logger,
    );

    expect($result)->toBe(0);
});

test('maybe_schedule schedules a follow-up action for a retryable dispatch', function (): void {
    $logger = new Logger(false);

    Functions\expect('as_schedule_single_action')
        ->once()
        ->andReturnUsing(function ($when, $hook, $args, $group): int {
            expect($hook)->toBe(RetryQueue::ACTION_HOOK);
            expect($group)->toBe(RetryQueue::GROUP);
            expect($args)->toBe([
                [
                    'payload' => [ 'requestReferenceNumber' => '42' ],
                    'attempt' => 2,
                ],
            ]);
            expect($when)->toBeGreaterThan(time());
            return 4242;
        });

    $result = RetryQueue::maybe_schedule(
        [ 'action' => 'order_not_found' ],
        [ 'requestReferenceNumber' => '42' ],
        1,
        $logger,
    );

    expect($result)->toBe(4242);
});

test('schedule clamps the delay to a minimum of 1 second', function (): void {
    $logger        = new Logger(false);
    $captured_when = 0;

    Functions\expect('as_schedule_single_action')
        ->once()
        ->andReturnUsing(function ($when) use (&$captured_when): int {
            $captured_when = (int) $when;
            return 99;
        });

    RetryQueue::schedule([ 'requestReferenceNumber' => '42' ], 1, -100, $logger);

    expect($captured_when)->toBeGreaterThanOrEqual(time());
});

test('ACTION_HOOK / GROUP constants are the documented values', function (): void {
    expect(RetryQueue::ACTION_HOOK)->toBe('wc_maya_replay_webhook');
    expect(RetryQueue::GROUP)->toBe('wc-maya-gateway');
});
