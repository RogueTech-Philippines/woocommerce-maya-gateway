<?php

/**
 * Unit tests for the WebhookLedger pure helpers.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;
use RogueTechPhilippines\MayaGateway\Webhook\WebhookLedger;

test('entry_key prefers the Maya payment id', function (): void {
    $key = WebhookLedger::entry_key(
        WebhookEvent::PaymentSuccess,
        [ 'id' => 'pay_123', 'requestReferenceNumber' => '42' ],
    );

    expect($key)->toBe(WebhookEvent::PaymentSuccess->value . ':pay_123');
});

test('entry_key falls back to the reference number when no id is present', function (): void {
    $key = WebhookLedger::entry_key(
        WebhookEvent::PaymentFailed,
        [ 'requestReferenceNumber' => 42 ],
    );

    expect($key)->toBe(WebhookEvent::PaymentFailed->value . ':rrn:42');
});

test('entry_key distinguishes different events for the same payment', function (): void {
    $payload = [ 'id' => 'pay_1' ];

    expect(WebhookLedger::entry_key(WebhookEvent::PaymentSuccess, $payload))
        ->not->toBe(WebhookLedger::entry_key(WebhookEvent::PaymentFailed, $payload));
});

test('only terminal actions are recordable', function (): void {
    expect(WebhookLedger::is_terminal_action('payment_complete'))->toBeTrue();
    expect(WebhookLedger::is_terminal_action('payment_complete_full_capture'))->toBeTrue();
    expect(WebhookLedger::is_terminal_action('failed'))->toBeTrue();

    // Retryable / informational outcomes must never enter the ledger, or
    // RetryQueue replays would be wrongly skipped as duplicates.
    expect(WebhookLedger::is_terminal_action('order_not_found'))->toBeFalse();
    expect(WebhookLedger::is_terminal_action('manual_capture_lookup_failed'))->toBeFalse();
    expect(WebhookLedger::is_terminal_action('partial_capture_note'))->toBeFalse();
    expect(WebhookLedger::is_terminal_action('already_paid'))->toBeFalse();
});
