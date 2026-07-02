<?php

/**
 * Unit tests for PublicKeyBundle.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Filters;
use RogueTechPhilippines\MayaGateway\Webhook\PublicKeyBundle;

test('exposes two PEMs per environment', function (): void {
    expect(PublicKeyBundle::SANDBOX_PEMS)->toHaveCount(2);
    expect(PublicKeyBundle::PRODUCTION_PEMS)->toHaveCount(2);
});

test('for_environment switches by sandbox flag', function (): void {
    expect(PublicKeyBundle::for_environment(true))->toBe(PublicKeyBundle::SANDBOX_PEMS);
    expect(PublicKeyBundle::for_environment(false))->toBe(PublicKeyBundle::PRODUCTION_PEMS);
});

test('every PEM is parseable by OpenSSL', function (): void {
    foreach (array_merge(PublicKeyBundle::SANDBOX_PEMS, PublicKeyBundle::PRODUCTION_PEMS) as $pem) {
        $key = openssl_pkey_get_public($pem);
        expect($key)->not->toBeFalse();
    }
});

test('the public-keys filter can supply a rotated key without a release', function (): void {
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([ 'ROTATED-PEM' ]);

    expect(PublicKeyBundle::for_environment(false))->toBe([ 'ROTATED-PEM' ]);
});

test('an empty/invalid filter result falls back to the bundled keys (never disables verification)', function (): void {
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([]);

    expect(PublicKeyBundle::for_environment(true))->toBe(PublicKeyBundle::SANDBOX_PEMS);
});
