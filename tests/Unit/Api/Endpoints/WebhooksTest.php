<?php

/**
 * Unit tests for the Webhooks endpoint wrapper.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Api\Endpoints;

use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Webhooks;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use WP_Error;

test('all GETs /checkout/v1/webhooks with the secret key and no body', function (): void {
    $items = [
        [ 'id' => 'wh-1', 'name' => 'PAYMENT_SUCCESS', 'callbackUrl' => 'https://example.test/?wc-api=maya_webhook' ],
    ];

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('GET', '/checkout/v1/webhooks', null, MayaApiClient::KEY_SECRET)
        ->andReturn($items);

    expect((new Webhooks($client))->all())->toBe($items);
});

test('all bubbles WP_Error from the transport', function (): void {
    $error = new WP_Error('wc_maya_http_401', 'Unauthorized');

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Webhooks($client))->all())->toBe($error);
});
