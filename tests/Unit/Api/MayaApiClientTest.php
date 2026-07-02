<?php

/**
 * Unit tests for the Maya API client.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit;

use RogueTechPhilippines\MayaGateway\Api\MayaApiClient;

test(
    'sandbox uses pg-sandbox.paymaya.com',
    function (): void {
        $client = new MayaApiClient('pk-test', 'sk-test', true);

        expect($client->get_base_url())->toBe('https://pg-sandbox.paymaya.com');
    },
);

test(
    'production uses pg.maya.ph',
    function (): void {
        $client = new MayaApiClient('pk-test', 'sk-test', false);

        expect($client->get_base_url())->toBe('https://pg.maya.ph');
    },
);

test('format_parameter_details composes a "(field: description)" suffix', function (): void {
    $suffix = MayaApiClient::format_parameter_details([
        'message'    => 'Missing/invalid parameters.',
        'parameters' => [
            [ 'field' => 'requestReferenceNumber', 'description' => 'length must be at most 36' ],
        ],
    ]);

    expect($suffix)->toBe(' (requestReferenceNumber: length must be at most 36)');
});

test('format_parameter_details joins multiple field errors with semicolons', function (): void {
    $suffix = MayaApiClient::format_parameter_details([
        'parameters' => [
            [ 'field' => 'totalAmount',            'description' => 'is required' ],
            [ 'field' => 'requestReferenceNumber', 'description' => 'length must be at most 36' ],
        ],
    ]);

    expect($suffix)->toBe(' (totalAmount: is required; requestReferenceNumber: length must be at most 36)');
});

test('format_parameter_details returns empty when parameters is missing or empty', function (): void {
    expect(MayaApiClient::format_parameter_details([]))->toBe('')
        ->and(MayaApiClient::format_parameter_details([ 'parameters' => [] ]))->toBe('')
        ->and(MayaApiClient::format_parameter_details([ 'parameters' => 'not-an-array' ]))->toBe('');
});

test('format_parameter_details skips entries without both field and description', function (): void {
    $suffix = MayaApiClient::format_parameter_details([
        'parameters' => [
            [ 'field' => 'only_field' ],
            [ 'description' => 'orphan description' ],
            [ 'field'       => 'good', 'description' => 'value' ],
        ],
    ]);

    expect($suffix)->toBe(' (good: value)');
});
