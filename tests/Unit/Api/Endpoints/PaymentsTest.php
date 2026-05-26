<?php

/**
 * Unit tests for the Payments endpoint wrapper.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints;

use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use WP_Error;

test('get_by_rrn GETs /payments/v1/payment-rrns/{rrn} with the secret key and decodes records', function (): void {
    $items = [
        [
            'id'                     => 'pay_1',
            'status'                 => 'AUTHORIZED',
            'amount'                 => 199.5,
            'currency'               => 'PHP',
            'capturedAmount'         => 0,
            'canCapture'             => true,
            'requestReferenceNumber' => '42',
        ],
    ];

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('GET', '/payments/v1/payment-rrns/42', null, MayaApiClient::KEY_SECRET)
        ->andReturn($items);

    $records = (new Payments($client))->get_by_rrn('42');

    expect($records)->toHaveCount(1);
    expect($records[0])->toBeInstanceOf(PaymentRecord::class);
    expect($records[0]->status)->toBe('AUTHORIZED');
    expect($records[0]->can_capture)->toBeTrue();
});

test('get_by_rrn URL-encodes the RRN', function (): void {
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('GET', '/payments/v1/payment-rrns/wc%2F42', null, MayaApiClient::KEY_SECRET)
        ->andReturn([]);

    expect((new Payments($client))->get_by_rrn('wc/42'))->toBe([]);
});

test('get_by_rrn bubbles WP_Error from the transport', function (): void {
    $error  = new WP_Error('wc_maya_http_404', 'Not Found');
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Payments($client))->get_by_rrn('42'))->toBe($error);
});

test('capture POSTs to /payments/v1/payments/{id}/capture with the secret key', function (): void {
    $payload = [
        'requestReferenceNumber' => '42',
        'captureAmount'          => [ 'amount' => 50.0, 'currency' => 'PHP' ],
    ];

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('POST', '/payments/v1/payments/pay_abc/capture', $payload, MayaApiClient::KEY_SECRET)
        ->andReturn([
            'id'             => 'pay_abc',
            'status'         => 'AUTHORIZED',
            'amount'         => 199.5,
            'currency'       => 'PHP',
            'capturedAmount' => 50.0,
            'canCapture'     => true,
        ]);

    $record = (new Payments($client))->capture('pay_abc', $payload);

    expect($record)->toBeInstanceOf(PaymentRecord::class);
    expect($record->captured_amount?->value)->toBe(50.0);
});

test('capture bubbles WP_Error from the transport', function (): void {
    $error  = new WP_Error('wc_maya_http_400', 'Invalid amount');
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Payments($client))->capture('pay_x', []))->toBe($error);
});
