<?php

/**
 * Unit tests for the EventDispatcher.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Mockery;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\WebhookEvent;
use TaniKyuun\MayaGateway\Webhook\EventDispatcher;
use WC_Order;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_mock_order(int $id, float $total, bool $is_paid = false): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->shouldReceive('get_id')->andReturn($id);
    $order->shouldReceive('get_total')->andReturn($total);
    $order->shouldReceive('is_paid')->andReturn($is_paid);
    return $order;
}

test('PAYMENT_SUCCESS with matching amount calls payment_complete', function (): void {
    $order = wc_maya_mock_order(42, 199.5);
    $order->shouldReceive('payment_complete')->with('pay_abc')->once();

    Functions\when('wc_get_order')->alias(static fn(int $id): ?WC_Order => 42 === $id ? $order : null);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_abc',
            'amount'                 => 199.5,
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result['action'])->toBe('payment_complete');
    expect($result['order_id'])->toBe(42);
    expect($result['payment_id'])->toBe('pay_abc');
});

test('PAYMENT_SUCCESS with amount mismatch logs + adds note, leaves order alone', function (): void {
    $order = wc_maya_mock_order(42, 199.5);
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');
    $order->shouldReceive('add_order_note')->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_abc',
            'amount'                 => 50.00, // wrong
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result)->toMatchArray([
        'action'   => 'amount_mismatch',
        'order_id' => 42,
        'expected' => 199.5,
        'received' => 50.0,
    ]);
});

test('PAYMENT_FAILED maps to update_status(failed)', function (): void {
    $order = wc_maya_mock_order(42, 199.5);
    $order->shouldReceive('update_status')->with('failed', Mockery::type('string'))->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentFailed,
        [ 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('failed');
    expect($result['event'])->toBe('PAYMENT_FAILED');
});

test('PAYMENT_EXPIRED and AUTH_FAILED also map to update_status(failed)', function (): void {
    foreach ([ WebhookEvent::PaymentExpired, WebhookEvent::AuthFailed ] as $event) {
        $order = wc_maya_mock_order(42, 100.0);
        $order->shouldReceive('update_status')->with('failed', Mockery::type('string'))->once();

        Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

        $result = (new EventDispatcher(new Logger(false)))->dispatch($event, [ 'requestReferenceNumber' => '42' ]);

        expect($result['action'])->toBe('failed');
        expect($result['event'])->toBe($event->value);
    }
});

test('already-paid orders are skipped (idempotency for webhook retries)', function (): void {
    $order = wc_maya_mock_order(42, 199.5, is_paid: true);
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_retry',
            'amount'                 => 199.5,
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result['action'])->toBe('already_paid');
});

test('missing order returns order_not_found without touching anything', function (): void {
    Functions\when('wc_get_order')->alias(static fn(): ?WC_Order => null);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [ 'requestReferenceNumber' => '999', 'amount' => 100.0 ],
    );

    expect($result['action'])->toBe('order_not_found');
    expect($result['reference'])->toBe('999');
});

test('non-payment-class events (CHECKOUT_SUCCESS, AUTHORIZED) are ignored at this phase', function (): void {
    $order = wc_maya_mock_order(42, 100.0);
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    foreach ([ WebhookEvent::CheckoutSuccess, WebhookEvent::Authorized ] as $event) {
        $result = (new EventDispatcher(new Logger(false)))->dispatch(
            $event,
            [ 'requestReferenceNumber' => '42' ],
        );
        expect($result['action'])->toBe('ignored');
        expect($result['event'])->toBe($event->value);
    }
});
