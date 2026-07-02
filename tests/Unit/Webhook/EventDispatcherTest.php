<?php

/**
 * Unit tests for the EventDispatcher.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Mockery;
use RogueTechPhilippines\MayaGateway\Api\Endpoints\Payments;
use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Util\Logger;
use RogueTechPhilippines\MayaGateway\Value\Money;
use RogueTechPhilippines\MayaGateway\Value\PaymentRecord;
use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;
use RogueTechPhilippines\MayaGateway\Webhook\EventDispatcher;
use WC_Order;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_mock_order(int $id, float $total, bool $is_paid = false, string $auth_type = 'none', string $currency = 'PHP'): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->shouldReceive('get_id')->andReturn($id);
    $order->shouldReceive('get_total')->andReturn($total);
    $order->shouldReceive('get_currency')->andReturn($currency);
    $order->shouldReceive('is_paid')->andReturn($is_paid);
    $order->shouldReceive('get_meta')
        ->with(MayaGateway::META_AUTHORIZATION_TYPE)
        ->andReturn($auth_type);
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

test('PAYMENT_SUCCESS with matching amount but mismatched currency leaves order alone', function (): void {
    $order = wc_maya_mock_order(42, 199.5, currency: 'PHP');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');
    $order->shouldReceive('add_order_note')->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_abc',
            'amount'                 => 199.5,
            'currency'               => 'USD', // right number, wrong currency
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result)->toMatchArray([
        'action'            => 'amount_mismatch',
        'order_id'          => 42,
        'expected_currency' => 'PHP',
        'received_currency' => 'USD',
    ]);
});

test('PAYMENT_SUCCESS with matching currency completes normally', function (): void {
    $order = wc_maya_mock_order(42, 199.5, currency: 'PHP');
    $order->shouldReceive('payment_complete')->with('pay_abc')->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_abc',
            'amount'                 => 199.5,
            'currency'               => 'php', // case-insensitive match
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result['action'])->toBe('payment_complete');
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

test('checkout failure, dropout, and cancellation use terminal failure handling', function (): void {
    foreach ([ WebhookEvent::CheckoutFailure, WebhookEvent::CheckoutDropout, WebhookEvent::PaymentCancelled ] as $event) {
        $order = wc_maya_mock_order(42, 100.0);
        $order->shouldReceive('update_status')->with('failed', Mockery::type('string'))->once();

        Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

        $result = (new EventDispatcher(new Logger(false)))->dispatch($event, [ 'requestReferenceNumber' => '42' ]);

        expect($result['action'])->toBe('failed');
        expect($result['event'])->toBe($event->value);
    }
});

test('already-paid success retries are skipped, but terminal failures still fail', function (): void {
    $paid_success = wc_maya_mock_order(42, 199.5, is_paid: true);
    $paid_success->shouldNotReceive('payment_complete');
    $paid_success->shouldNotReceive('update_status');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $paid_success);

    $success_result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_retry',
            'amount'                 => 199.5,
            'requestReferenceNumber' => '42',
        ],
    );

    expect($success_result['action'])->toBe('already_paid');

    $paid_failed = wc_maya_mock_order(42, 199.5, is_paid: true);
    $paid_failed->shouldReceive('update_status')->with('failed', Mockery::type('string'))->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $paid_failed);

    $failed_result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentFailed,
        [ 'requestReferenceNumber' => '42' ],
    );

    expect($failed_result['action'])->toBe('failed');
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

test('CHECKOUT_SUCCESS is ignored for immediate-capture orders', function (): void {
    $order = wc_maya_mock_order(42, 100.0);
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::CheckoutSuccess,
        [ 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('ignored');
    expect($result['event'])->toBe('CHECKOUT_SUCCESS');
});

test('AUTHORIZED on an immediate-capture order falls through to ignored', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'none');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');
    $order->shouldNotReceive('add_order_note');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::Authorized,
        [ 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('ignored');
});

test('AUTHORIZED on a manual-capture order adds a note (no state change)', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'normal');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');
    $order->shouldReceive('add_order_note')->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::Authorized,
        [ 'id' => 'pay_auth_1', 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('authorized_note');
    expect($result['payment_id'])->toBe('pay_auth_1');
});

function wc_maya_authorization_record(float $authorized, float $captured, string $id = 'auth_1', string $status = 'AUTHORIZED'): PaymentRecord
{
    return new PaymentRecord(
        id: $id,
        status: $status,
        amount: new Money($authorized, 'PHP'),
        captured_amount: new Money($captured, 'PHP'),
        request_reference_number: '42',
        receipt_number: 'r1',
        can_void: false,
        can_refund: false,
        can_capture: true,
        authorization_type: 'NORMAL',
    );
}

test('PAYMENT_SUCCESS on manual-capture: full capture promotes to payment_complete', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'normal');
    $order->shouldReceive('payment_complete')->with('pay_full')->once();
    $order->shouldNotReceive('add_order_note');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $payments = Mockery::mock(Payments::class);
    $payments->expects('get_by_rrn')->with('42')->andReturn([
        wc_maya_authorization_record(199.5, 199.5, status: 'CAPTURED'),
    ]);

    $result = (new EventDispatcher(new Logger(false), $payments))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_full',
            'amount'                 => 199.5,
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result['action'])->toBe('payment_complete_full_capture');
    expect($result['order_id'])->toBe(42);
    expect($result['authorized'])->toBe(199.5);
    expect($result['captured'])->toBe(199.5);
});

test('PAYMENT_SUCCESS on manual-capture: partial capture adds a note, no status change', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'preauthorization');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('update_status');
    $order->shouldReceive('add_order_note')->once();

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $payments = Mockery::mock(Payments::class);
    $payments->expects('get_by_rrn')->with('42')->andReturn([
        wc_maya_authorization_record(199.5, 50.0, status: 'CAPTURED'),
    ]);

    $result = (new EventDispatcher(new Logger(false), $payments))->dispatch(
        WebhookEvent::PaymentSuccess,
        [
            'id'                     => 'pay_partial',
            'amount'                 => 50.0, // this capture's amount (ignored by dispatcher — auth state is what counts)
            'requestReferenceNumber' => '42',
        ],
    );

    expect($result['action'])->toBe('partial_capture_note');
    expect($result['captured'])->toBe(50.0);
    expect($result['authorized'])->toBe(199.5);
});

test('PAYMENT_SUCCESS on manual-capture fails closed when Payments endpoint is missing', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'normal');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('add_order_note');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    // No Payments injected — production code always provides it, but a wiring
    // regression would otherwise silently always-partial.
    $result = (new EventDispatcher(new Logger(false)))->dispatch(
        WebhookEvent::PaymentSuccess,
        [ 'id' => 'pay_x', 'amount' => 100.0, 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('manual_capture_lookup_unavailable');
});

test('PAYMENT_SUCCESS on manual-capture surfaces lookup failures without mutating the order', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'normal');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('add_order_note');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $payments = Mockery::mock(Payments::class);
    $payments->expects('get_by_rrn')->andReturn(new WP_Error('wc_maya_http_500', 'Boom'));

    $result = (new EventDispatcher(new Logger(false), $payments))->dispatch(
        WebhookEvent::PaymentSuccess,
        [ 'id' => 'pay_x', 'amount' => 100.0, 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('manual_capture_lookup_failed');
});

test('PAYMENT_SUCCESS on manual-capture flags when no AUTHORIZED record is found', function (): void {
    $order = wc_maya_mock_order(42, 199.5, auth_type: 'normal');
    $order->shouldNotReceive('payment_complete');
    $order->shouldNotReceive('add_order_note');

    Functions\when('wc_get_order')->alias(static fn(): WC_Order => $order);

    $payments = Mockery::mock(Payments::class);
    // Only CAPTURED records — no AUTHORIZED to read amount/capturedAmount from.
    $payments->expects('get_by_rrn')->andReturn([
        new PaymentRecord('cap_only', 'CAPTURED', new Money(100.0, 'PHP'), new Money(100.0, 'PHP'), '42', null, false, true, false, null),
    ]);

    $result = (new EventDispatcher(new Logger(false), $payments))->dispatch(
        WebhookEvent::PaymentSuccess,
        [ 'id' => 'cap_only', 'amount' => 100.0, 'requestReferenceNumber' => '42' ],
    );

    expect($result['action'])->toBe('manual_capture_no_authorization');
});
