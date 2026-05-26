<?php

/**
 * Unit tests for the CheckoutSession value object.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Value;

use TaniKyuun\MayaGateway\Value\CheckoutSession;

test('from_array reads checkoutId and redirectUrl from Maya response', function (): void {
    $session = CheckoutSession::from_array([
        'checkoutId'  => 'abc-123',
        'redirectUrl' => 'https://payments-web-sandbox.maya.ph/v2/checkout?id=abc-123',
        'unknown'     => 'ignored',
    ]);

    expect($session->checkout_id)->toBe('abc-123')
        ->and($session->redirect_url)->toBe('https://payments-web-sandbox.maya.ph/v2/checkout?id=abc-123');
});

test('from_array defaults missing fields to empty strings', function (): void {
    $session = CheckoutSession::from_array([]);

    expect($session->checkout_id)->toBe('')
        ->and($session->redirect_url)->toBe('');
});

test('to_array emits Maya-shaped JSON keys', function (): void {
    $original = new CheckoutSession('id-1', 'https://example.test/c/id-1');

    expect($original->to_array())->toBe([
        'checkoutId'  => 'id-1',
        'redirectUrl' => 'https://example.test/c/id-1',
    ]);
});
