<?php

/**
 * Unit tests for IdempotencyKey.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Util
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Util;

use Brain\Monkey\Functions;
use TaniKyuun\MayaGateway\Util\IdempotencyKey;

beforeEach(function (): void {
    Functions\when('wp_generate_uuid4')->justReturn('00000000-1111-2222-3333-444444444444');
});

test('uuid delegates to wp_generate_uuid4', function (): void {
    expect(IdempotencyKey::uuid())->toBe('00000000-1111-2222-3333-444444444444');
});

test('for_order returns the order id as a string', function (): void {
    expect(IdempotencyKey::for_order(42))->toBe('42');
});

test('for_test_connection prefixes the dehyphenated UUID and fills the 36-char budget', function (): void {
    // 13-char prefix + 23 hex chars sliced off the UUID = 36 chars total.
    expect(IdempotencyKey::for_test_connection())
        ->toBe('wc-maya-test-00000000111122223333444');
});

test('for_test_connection starts with the documented prefix constant', function (): void {
    expect(IdempotencyKey::for_test_connection())->toStartWith(IdempotencyKey::TEST_PREFIX);
});

test('for_test_connection fits inside Maya\'s requestReferenceNumber limit', function (): void {
    expect(strlen(IdempotencyKey::for_test_connection()))
        ->toBeLessThanOrEqual(IdempotencyKey::MAX_REFERENCE_LENGTH)
        ->and(IdempotencyKey::MAX_REFERENCE_LENGTH)->toBe(36);
});
