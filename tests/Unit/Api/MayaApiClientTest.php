<?php

/**
 * Unit tests for the Maya API client.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit;

use TaniKyuun\MayaGateway\Api\MayaApiClient;

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
