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
use TaniKyuun\MayaGateway\Value\WebhookRecord;
use WP_Error;

test('all GETs /checkout/v1/webhooks with the secret key and decodes records', function (): void {
    $items = [
        [
            'id'          => 'wh-1',
            'name'        => 'PAYMENT_SUCCESS',
            'callbackUrl' => 'https://example.test/?wc-api=maya_webhook',
            'createdAt'   => '2026-05-01T00:00:00Z',
            'updatedAt'   => '2026-05-01T00:00:00Z',
        ],
        [
            'id'          => 'wh-2',
            'name'        => 'CHECKOUT_SUCCESS',
            'callbackUrl' => 'https://example.test/?wc-api=maya_webhook',
            'createdAt'   => '2026-05-02T00:00:00Z',
            'updatedAt'   => '2026-05-02T00:00:00Z',
        ],
    ];

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('GET', '/checkout/v1/webhooks', null, MayaApiClient::KEY_SECRET)
        ->andReturn($items);

    $records = (new Webhooks($client))->all();

    expect($records)->toHaveCount(2);
    expect($records[0])->toBeInstanceOf(WebhookRecord::class);
    expect($records[0]->id)->toBe('wh-1');
    expect($records[1]->name)->toBe('CHECKOUT_SUCCESS');
});

test('all bubbles WP_Error from the transport', function (): void {
    $error = new WP_Error('wc_maya_http_401', 'Unauthorized');

    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Webhooks($client))->all())->toBe($error);
});

test('create POSTs name + callbackUrl with the secret key', function (): void {
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with(
            'POST',
            '/checkout/v1/webhooks',
            [ 'name' => 'PAYMENT_SUCCESS', 'callbackUrl' => 'https://example.test/cb' ],
            MayaApiClient::KEY_SECRET,
        )
        ->andReturn([
            'id'          => 'wh-new',
            'name'        => 'PAYMENT_SUCCESS',
            'callbackUrl' => 'https://example.test/cb',
        ]);

    $record = (new Webhooks($client))->create('PAYMENT_SUCCESS', 'https://example.test/cb');

    expect($record)->toBeInstanceOf(WebhookRecord::class);
    expect($record->id)->toBe('wh-new');
});

test('create bubbles WP_Error from the transport', function (): void {
    $error  = new WP_Error('wc_maya_http_409', 'Conflict');
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Webhooks($client))->create('PAYMENT_SUCCESS', 'https://x.test/'))->toBe($error);
});

test('delete DELETEs /checkout/v1/webhooks/{id} with url-encoded id', function (): void {
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')
        ->with('DELETE', '/checkout/v1/webhooks/wh%2F1', null, MayaApiClient::KEY_SECRET)
        ->andReturn([ 'id' => 'wh/1', 'name' => 'PAYMENT_SUCCESS' ]);

    $record = (new Webhooks($client))->delete('wh/1');

    expect($record)->toBeInstanceOf(WebhookRecord::class);
    expect($record->id)->toBe('wh/1');
});

test('delete bubbles WP_Error from the transport', function (): void {
    $error  = new WP_Error('wc_maya_http_404', 'Not Found');
    $client = Mockery::mock(MayaApiClient::class);
    $client->expects('request')->andReturn($error);

    expect((new Webhooks($client))->delete('missing'))->toBe($error);
});
