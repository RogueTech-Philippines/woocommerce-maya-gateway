<?php

/**
 * Unit tests for the RefundProcessor — including the four DoD scenarios:
 *
 *   1. Full void                                       (immediate-capture, canVoid + full amount)
 *   2. Full refund                                     (immediate-capture, canRefund)
 *   3. Partial refund — single captured payment       (manual-capture)
 *   4. Partial refund split across two captured payments (manual-capture)
 *
 * Plus exhaustive coverage of the pure-static planner and the
 * remaining_refundable reducer.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Gateway\RefundProcessor;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\Money;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use TaniKyuun\MayaGateway\Value\RefundRecord;
use WC_Order;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_refund_order(int $id = 42, string $currency = 'PHP', string $auth_type = 'none'): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->shouldReceive('get_id')->andReturn($id);
    $order->shouldReceive('get_currency')->andReturn($currency);
    $order->shouldReceive('get_meta')
        ->with(MayaGateway::META_AUTHORIZATION_TYPE)
        ->andReturn($auth_type);
    return $order;
}

function wc_maya_immediate_payment(
    string $id = 'pay_immediate',
    float $amount = 100.0,
    string $status = 'PAYMENT_SUCCESS',
    bool $can_void = false,
    bool $can_refund = true,
): PaymentRecord {
    return new PaymentRecord(
        id: $id,
        status: $status,
        amount: new Money($amount, 'PHP'),
        captured_amount: null,
        request_reference_number: '42',
        receipt_number: 'r1',
        can_void: $can_void,
        can_refund: $can_refund,
        can_capture: false,
        authorization_type: null,
        is_capture: false,
        created_at: '2026-01-01T00:00:00Z',
    );
}

function wc_maya_authorization_payment(string $id = 'auth_1', float $amount = 100.0, bool $can_void = true): PaymentRecord
{
    return new PaymentRecord(
        id: $id,
        status: 'AUTHORIZED',
        amount: new Money($amount, 'PHP'),
        captured_amount: new Money(0.0, 'PHP'),
        request_reference_number: '42',
        receipt_number: 'r1',
        can_void: $can_void,
        can_refund: false,
        can_capture: true,
        authorization_type: 'NORMAL',
        is_capture: false,
        created_at: '2026-01-01T00:00:00Z',
    );
}

function wc_maya_capture_payment(
    string $id,
    float $amount,
    bool $can_void = false,
    bool $can_refund = true,
    string $created_at = '2026-01-02T00:00:00Z',
): PaymentRecord {
    return new PaymentRecord(
        id: $id,
        status: 'PAYMENT_SUCCESS',
        amount: new Money($amount, 'PHP'),
        captured_amount: null,
        request_reference_number: '42',
        receipt_number: 'r-' . $id,
        can_void: $can_void,
        can_refund: $can_refund,
        can_capture: false,
        authorization_type: null,
        is_capture: true,
        created_at: $created_at,
    );
}

/* ---------------------------------------------------------------------------
 * DoD scenario 1: immediate-capture, canVoid + full amount → void
 * ------------------------------------------------------------------------- */

test('DoD #1 — full void: immediate-capture, canVoid + full amount calls Payments::void', function (): void {
    $order = wc_maya_refund_order();
    $order->shouldReceive('add_order_note')->once();

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->with('42')->andReturn([
        wc_maya_immediate_payment(amount: 100.0, can_void: true, can_refund: false),
    ]);
    $endpoint->expects('void')->with('pay_immediate', Mockery::type('string'))
        ->andReturn(wc_maya_immediate_payment(amount: 100.0, can_void: false, can_refund: false));
    $endpoint->shouldNotReceive('refund');

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 100.0, '');

    expect($result)->toBeTrue();
});

test('immediate-capture partial-amount with canVoid but not canRefund errors out (Maya rejects partial voids)', function (): void {
    $order    = wc_maya_refund_order();
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_immediate_payment(amount: 100.0, can_void: true, can_refund: false),
    ]);
    $endpoint->shouldNotReceive('void');
    $endpoint->shouldNotReceive('refund');

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 40.0, '');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_refund_partial_void');
});

/* ---------------------------------------------------------------------------
 * DoD scenario 2: immediate-capture, canRefund → refund
 * ------------------------------------------------------------------------- */

test('DoD #2 — full refund: immediate-capture, canRefund only, full amount calls Payments::refund', function (): void {
    $order = wc_maya_refund_order();
    $order->shouldReceive('add_order_note')->once();

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_immediate_payment(amount: 100.0, can_void: false, can_refund: true),
    ]);
    $endpoint->expects('refund')
        ->with('pay_immediate', Mockery::on(static fn(array $p): bool
            => 100.0 === $p['totalAmount']['amount']
            && 'PHP' === $p['totalAmount']['currency']
            && '' !== $p['reason']))
        ->andReturn(RefundRecord::from_array([ 'id' => 'rfd_1', 'status' => 'SUCCESS', 'amount' => 100.0, 'currency' => 'PHP' ]));
    $endpoint->shouldNotReceive('void');

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 100.0, 'Customer request');

    expect($result)->toBeTrue();
});

/* ---------------------------------------------------------------------------
 * DoD scenario 3: partial refund — single captured payment
 * ------------------------------------------------------------------------- */

test('DoD #3 — partial refund, single capture: refunds the requested slice', function (): void {
    $order = wc_maya_refund_order(auth_type: 'preauthorization');
    $order->shouldReceive('add_order_note')->once();

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_authorization_payment(amount: 100.0, can_void: false),
        wc_maya_capture_payment('cap_1', 100.0, can_void: false, can_refund: true),
    ]);
    $endpoint->expects('get_refunds')->with('cap_1')->andReturn([]); // no prior refunds
    $endpoint->expects('refund')
        ->with('cap_1', Mockery::on(static fn(array $p): bool => 30.0 === $p['totalAmount']['amount']))
        ->andReturn(RefundRecord::from_array([ 'id' => 'rfd_1', 'status' => 'SUCCESS', 'amount' => 30.0, 'currency' => 'PHP' ]));

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 30.0, '');

    expect($result)->toBeTrue();
});

/* ---------------------------------------------------------------------------
 * DoD scenario 4: partial refund split across two captured payments
 * ------------------------------------------------------------------------- */

test('DoD #4 — partial refund split across two captures: consumes first whole, second partial', function (): void {
    $order = wc_maya_refund_order(auth_type: 'preauthorization');
    $order->shouldReceive('add_order_note')->twice(); // two run_refund calls

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_authorization_payment(amount: 200.0, can_void: false),
        wc_maya_capture_payment('cap_1', 80.0, can_void: false, can_refund: true, created_at: '2026-01-02T00:00:00Z'),
        wc_maya_capture_payment('cap_2', 120.0, can_void: false, can_refund: true, created_at: '2026-01-03T00:00:00Z'),
    ]);
    $endpoint->expects('get_refunds')->with('cap_1')->andReturn([]);
    $endpoint->expects('get_refunds')->with('cap_2')->andReturn([]);

    // Asking for 100 → cap_1 contributes 80 in full, cap_2 contributes 20.
    $endpoint->expects('refund')
        ->with('cap_1', Mockery::on(static fn(array $p): bool => 80.0 === $p['totalAmount']['amount']))
        ->andReturn(RefundRecord::from_array([ 'id' => 'rfd_1', 'status' => 'SUCCESS', 'amount' => 80.0 ]));
    $endpoint->expects('refund')
        ->with('cap_2', Mockery::on(static fn(array $p): bool => 20.0 === $p['totalAmount']['amount']))
        ->andReturn(RefundRecord::from_array([ 'id' => 'rfd_2', 'status' => 'SUCCESS', 'amount' => 20.0 ]));

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 100.0, 'Goodwill');

    expect($result)->toBeTrue();
});

/* ---------------------------------------------------------------------------
 * Manual-capture, no captures yet → only-full void
 * ------------------------------------------------------------------------- */

test('manual-capture, no captures yet: voids the authorization when amount matches', function (): void {
    $order = wc_maya_refund_order(auth_type: 'normal');
    $order->shouldReceive('add_order_note')->once();

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_authorization_payment(amount: 150.0, can_void: true),
    ]);
    $endpoint->expects('void')->with('auth_1', Mockery::type('string'))
        ->andReturn(wc_maya_authorization_payment(amount: 150.0, can_void: false));

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 150.0, '');

    expect($result)->toBeTrue();
});

test('manual-capture, no captures yet: partial amount errors (Maya rejects partial voids)', function (): void {
    $order = wc_maya_refund_order(auth_type: 'normal');

    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_authorization_payment(amount: 150.0, can_void: true),
    ]);
    $endpoint->shouldNotReceive('void');

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process($order, 50.0, '');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_refund_partial_void');
});

/* ---------------------------------------------------------------------------
 * Top-level validation & error paths
 * ------------------------------------------------------------------------- */

test('rejects a non-positive refund amount', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->shouldNotReceive('get_by_rrn');

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process(wc_maya_refund_order(), 0.0, '');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_refund_invalid_amount');
});

test('bubbles get_by_rrn errors from the transport', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn(new WP_Error('wc_maya_http_500', 'Boom'));

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process(wc_maya_refund_order(), 50.0, '');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_http_500');
});

test('immediate-capture: errors when no PAYMENT_SUCCESS or REFUNDED payment is found', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([]);

    $result = (new RefundProcessor($endpoint, new Logger(false)))->process(wc_maya_refund_order(), 50.0, '');

    expect($result->get_error_code())->toBe('wc_maya_refund_no_payment');
});

/* ---------------------------------------------------------------------------
 * Pure planner — exhaustive coverage of the split algorithm
 * ------------------------------------------------------------------------- */

test('planner: empty available list errors when amount > 0', function (): void {
    $result = RefundProcessor::plan_capture_actions([], 50.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_refund_insufficient_balance');
});

test('planner: void action whose amount exceeds remaining errors as partial-void', function (): void {
    $available = [
        [ 'action' => 'void', 'payment_id' => 'cap_1', 'amount' => 80.0, 'currency' => 'PHP' ],
    ];

    $result = RefundProcessor::plan_capture_actions($available, 50.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_refund_partial_void');
});

test('planner: stops walking once the amount is covered', function (): void {
    $available = [
        [ 'action' => 'refund', 'payment_id' => 'cap_1', 'amount' => 30.0, 'currency' => 'PHP' ],
        [ 'action' => 'refund', 'payment_id' => 'cap_2', 'amount' => 50.0, 'currency' => 'PHP' ],
    ];

    $plan = RefundProcessor::plan_capture_actions($available, 20.0);

    expect($plan)->toHaveCount(1);
    expect($plan[0])->toMatchArray([ 'payment_id' => 'cap_1', 'amount' => 20.0 ]);
});

test('planner: chains void → refund when the void exactly fits', function (): void {
    $available = [
        [ 'action' => 'void',   'payment_id' => 'cap_1', 'amount' => 50.0, 'currency' => 'PHP' ],
        [ 'action' => 'refund', 'payment_id' => 'cap_2', 'amount' => 100.0, 'currency' => 'PHP' ],
    ];

    $plan = RefundProcessor::plan_capture_actions($available, 75.0);

    expect($plan)->toHaveCount(2);
    expect($plan[0]['action'])->toBe('void');
    expect($plan[1])->toMatchArray([ 'action' => 'refund', 'payment_id' => 'cap_2', 'amount' => 25.0 ]);
});

/* ---------------------------------------------------------------------------
 * Pure reducer
 * ------------------------------------------------------------------------- */

test('remaining_refundable subtracts SUCCESS refunds and ignores others', function (): void {
    $capture = wc_maya_capture_payment('cap_1', 100.0);
    $refunds = [
        RefundRecord::from_array([ 'status' => 'SUCCESS', 'amount' => 30.0 ]),
        RefundRecord::from_array([ 'status' => 'PENDING', 'amount' => 25.0 ]), // ignored
        RefundRecord::from_array([ 'status' => 'SUCCESS', 'amount' => 20.0 ]),
        RefundRecord::from_array([ 'status' => 'FAILED',  'amount' => 99.0 ]), // ignored
    ];

    expect(RefundProcessor::remaining_refundable($capture, $refunds))->toBe(50.0);
});

test('remaining_refundable clamps at zero when refunds exceed the capture', function (): void {
    $capture = wc_maya_capture_payment('cap_1', 100.0);
    $refunds = [
        RefundRecord::from_array([ 'status' => 'SUCCESS', 'amount' => 150.0 ]),
    ];

    expect(RefundProcessor::remaining_refundable($capture, $refunds))->toBe(0.0);
});
