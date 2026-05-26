<?php

/**
 * Unit tests for the Checkouts endpoint wrapper.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints;

use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Checkouts;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Value\CheckoutSession;
use WP_Error;

test('create POSTs to /checkout/v1/checkouts with the public key', function (): void {
    $payload = [ 'totalAmount' => [ 'value' => 100, 'currency' => 'PHP' ] ];

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('POST', '/checkout/v1/checkouts', $payload, MayaApiClient::KEY_PUBLIC)
        ->andReturn([ 'checkoutId' => 'abc', 'redirectUrl' => 'https://example.test/c/abc' ]);

    $session = (new Checkouts($client))->create($payload);

    expect($session)->toBeInstanceOf(CheckoutSession::class)
        ->and($session->checkout_id)->toBe('abc')
        ->and($session->redirect_url)->toBe('https://example.test/c/abc');
});

test('create returns WP_Error from the transport untouched', function (): void {
    $error = new WP_Error('wc_maya_http_401', 'Unauthorized');

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    $result = (new Checkouts($client))->create([]);

    expect($result)->toBe($error);
});
