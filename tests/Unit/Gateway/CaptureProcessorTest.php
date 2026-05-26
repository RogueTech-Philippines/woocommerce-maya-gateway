<?php

/**
 * Unit tests for the CaptureProcessor.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Gateway\CaptureProcessor;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\Money;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use WC_Order;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_capture_order(int $id = 42, string $currency = 'PHP'): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->shouldReceive('get_id')->andReturn($id);
    $order->shouldReceive('get_currency')->andReturn($currency);
    return $order;
}

function wc_maya_payment_record(string $status, float $amount, float $captured = 0.0, bool $can_capture = true, string $id = 'pay_abc'): PaymentRecord
{
    return new PaymentRecord(
        id: $id,
        status: $status,
        amount: new Money($amount, 'PHP'),
        captured_amount: new Money($captured, 'PHP'),
        request_reference_number: '42',
        receipt_number: 'r1',
        can_void: false,
        can_refund: false,
        can_capture: $can_capture,
        authorization_type: 'NORMAL',
    );
}

test('rejects a non-positive amount', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->shouldNotReceive('get_by_rrn');

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture(wc_maya_capture_order(), 0.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_capture_invalid_amount');
});

test('bubbles get_by_rrn errors as WP_Error', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->with('42')->andReturn(new WP_Error('wc_maya_http_500', 'Boom'));

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture(wc_maya_capture_order(), 100.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_http_500');
});

test('rejects when no AUTHORIZED + canCapture payment is found', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_payment_record('CAPTURED', 199.5, 199.5, can_capture: false),
    ]);

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture(wc_maya_capture_order(), 100.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_capture_no_authorized_payment');
});

test('rejects when amount exceeds remaining capturable balance', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_payment_record('AUTHORIZED', 100.0, 60.0), // remaining = 40
    ]);
    $endpoint->shouldNotReceive('capture');

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture(wc_maya_capture_order(), 50.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_capture_exceeds_remaining');
});

test('captures successfully and returns updated balances', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0),
    ]);
    $endpoint->expects('capture')
        ->with('pay_abc', Mockery::on(static function (array $payload): bool {
            return '42'  === $payload['requestReferenceNumber']
                && 40.0  === $payload['captureAmount']['amount']
                && 'PHP' === $payload['captureAmount']['currency'];
        }))
        ->andReturn(wc_maya_payment_record('AUTHORIZED', 100.0, 40.0));

    $order = wc_maya_capture_order();
    $order->shouldReceive('add_order_note')->once();

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture($order, 40.0);

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['action'])->toBe('captured');
    expect($result['payment_id'])->toBe('pay_abc');
    expect($result['amount_authorized'])->toBe(100.0);
    expect($result['amount_captured'])->toBe(40.0);
    expect($result['amount_remaining'])->toBe(60.0);
    expect($result['currency'])->toBe('PHP');
});

test('errors out if Maya capture response omits capturedAmount (no silent fallback)', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0),
    ]);
    // Response has no captured_amount — simulate a malformed Maya response.
    $endpoint->expects('capture')->andReturn(new PaymentRecord(
        id: 'pay_abc',
        status: 'AUTHORIZED',
        amount: new Money(100.0, 'PHP'),
        captured_amount: null,
        request_reference_number: '42',
        receipt_number: null,
        can_void: false,
        can_refund: false,
        can_capture: true,
        authorization_type: 'NORMAL',
    ));

    $order = wc_maya_capture_order();
    $order->shouldNotReceive('add_order_note');

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture($order, 40.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_capture_response_invalid');
});

test('bubbles capture errors from the transport', function (): void {
    $endpoint = Mockery::mock(Payments::class);
    $endpoint->expects('get_by_rrn')->andReturn([
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0),
    ]);
    $endpoint->expects('capture')->andReturn(new WP_Error('wc_maya_http_400', 'Capture rejected'));

    $order = wc_maya_capture_order();
    $order->shouldNotReceive('add_order_note');

    $result = (new CaptureProcessor($endpoint, new Logger(false)))->capture($order, 40.0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_http_400');
});

test('find_capturable_payment picks the first AUTHORIZED + canCapture entry', function (): void {
    $payments = [
        wc_maya_payment_record('CAPTURED', 100.0, 100.0, can_capture: false, id: 'pay_old'),
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0, can_capture: false, id: 'pay_locked'),
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0, can_capture: true, id: 'pay_winner'),
        wc_maya_payment_record('AUTHORIZED', 100.0, 0.0, can_capture: true, id: 'pay_extra'),
    ];

    $found = CaptureProcessor::find_capturable_payment($payments);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe('pay_winner');
});

test('find_capturable_payment returns null when no candidate exists', function (): void {
    expect(CaptureProcessor::find_capturable_payment([]))->toBeNull();
    expect(CaptureProcessor::find_capturable_payment([
        wc_maya_payment_record('CAPTURED', 100.0, 100.0, can_capture: false),
    ]))->toBeNull();
});
