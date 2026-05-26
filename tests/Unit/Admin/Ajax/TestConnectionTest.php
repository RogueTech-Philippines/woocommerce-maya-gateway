<?php

/**
 * Unit tests for the Test Connection AJAX handler — pure-function pieces.
 *
 * The probe methods themselves are exercised through integration tests once
 * Phase 4 lands; this file covers the testable surface: the createCheckout
 * payload builder.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Admin\Ajax
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Admin\Ajax;

use TaniKyuun\MayaGateway\Admin\Ajax\TestConnection;

test('build_test_checkout_payload renders totalAmount as a Money array', function (): void {
    $payload = TestConnection::build_test_checkout_payload('ref-abc', 'https://example.test/');

    expect($payload['totalAmount'])->toBe([
        'value'    => TestConnection::TEST_AMOUNT,
        'currency' => TestConnection::TEST_CURRENCY,
    ]);
});

test('build_test_checkout_payload mirrors the return URL across success, failure, and cancel', function (): void {
    $payload = TestConnection::build_test_checkout_payload('ref', 'https://example.test/return');

    expect($payload['redirectUrl'])->toBe([
        'success' => 'https://example.test/return',
        'failure' => 'https://example.test/return',
        'cancel'  => 'https://example.test/return',
    ]);
});

test('build_test_checkout_payload tags the session with the diagnostic metadata source', function (): void {
    $payload = TestConnection::build_test_checkout_payload('ref', 'https://example.test/');

    expect($payload['metadata']['source'])->toBe(TestConnection::TEST_METADATA_SOURCE)
        ->and(TestConnection::TEST_METADATA_SOURCE)->toBe('wc-maya-gateway-test-connection');
});

test('build_test_checkout_payload echoes the request reference number back', function (): void {
    $payload = TestConnection::build_test_checkout_payload('my-custom-reference', 'https://example.test/');

    expect($payload['requestReferenceNumber'])->toBe('my-custom-reference');
});

test('default test amount is PHP 100.00', function (): void {
    expect(TestConnection::TEST_AMOUNT)->toBe(100.0)
        ->and(TestConnection::TEST_CURRENCY)->toBe('PHP');
});
