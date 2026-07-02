<?php

/**
 * Unit tests for the WebhookEvent enum.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Value;

use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;

test('try_from_string resolves Maya event names', function (): void {
    expect(WebhookEvent::try_from_string('PAYMENT_SUCCESS'))->toBe(WebhookEvent::PaymentSuccess)
        ->and(WebhookEvent::try_from_string('AUTHORIZED'))->toBe(WebhookEvent::Authorized)
        ->and(WebhookEvent::try_from_string('CHECKOUT_DROPOUT'))->toBe(WebhookEvent::CheckoutDropout);
});

test('try_from_string trims whitespace before matching', function (): void {
    expect(WebhookEvent::try_from_string("  PAYMENT_FAILED \n"))->toBe(WebhookEvent::PaymentFailed);
});

test('try_from_string returns null for unknown or non-string input', function (): void {
    expect(WebhookEvent::try_from_string('SOMETHING_NEW'))->toBeNull()
        ->and(WebhookEvent::try_from_string(null))->toBeNull()
        ->and(WebhookEvent::try_from_string(42))->toBeNull();
});

test('is_terminal_failure flags every failure-style event', function (): void {
    expect(WebhookEvent::PaymentFailed->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::PaymentExpired->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::PaymentCancelled->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::CheckoutFailure->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::CheckoutDropout->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::AuthFailed->is_terminal_failure())->toBeTrue()
        ->and(WebhookEvent::PaymentSuccess->is_terminal_failure())->toBeFalse()
        ->and(WebhookEvent::Authorized->is_terminal_failure())->toBeFalse();
});

test('is_terminal_success is only PAYMENT_SUCCESS', function (): void {
    expect(WebhookEvent::PaymentSuccess->is_terminal_success())->toBeTrue()
        ->and(WebhookEvent::Authorized->is_terminal_success())->toBeFalse()
        ->and(WebhookEvent::CheckoutSuccess->is_terminal_success())->toBeFalse();
});
