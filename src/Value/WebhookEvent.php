<?php

/**
 * Webhook event-name enum.
 *
 * @package TaniKyuun\MayaGateway\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Value;

/**
 * Event names Maya posts to our webhook endpoint.
 *
 * The set is conservative — Maya occasionally introduces new event names
 * (e.g., `AUTH_FAILED` for 3DS rejections). Callers should always go through
 * `try_from()` so an unknown event becomes null instead of an exception, and
 * the dispatcher can decide to log + ignore vs. reject.
 */
enum WebhookEvent: string
{
    case CheckoutSuccess  = 'CHECKOUT_SUCCESS';
    case CheckoutFailure  = 'CHECKOUT_FAILURE';
    case CheckoutDropout  = 'CHECKOUT_DROPOUT';
    case PaymentSuccess   = 'PAYMENT_SUCCESS';
    case PaymentFailed    = 'PAYMENT_FAILED';
    case PaymentExpired   = 'PAYMENT_EXPIRED';
    case PaymentCancelled = 'PAYMENT_CANCELLED';
    case Authorized       = 'AUTHORIZED';
    case AuthFailed       = 'AUTH_FAILED';

    public static function try_from_string(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(trim($value));
    }

    public function is_terminal_failure(): bool
    {
        return match ($this) {
            self::PaymentFailed,
            self::PaymentExpired,
            self::PaymentCancelled,
            self::CheckoutFailure,
            self::CheckoutDropout,
            self::AuthFailed => true,
            default          => false,
        };
    }

    public function is_terminal_success(): bool
    {
        return self::PaymentSuccess === $this;
    }
}
