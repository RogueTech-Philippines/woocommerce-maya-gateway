<?php

/**
 * Unit tests for the webhook Registrar.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Mockery;
use RogueTechPhilippines\MayaGateway\Api\Endpoints\Webhooks;
use RogueTechPhilippines\MayaGateway\Util\Logger;
use RogueTechPhilippines\MayaGateway\Value\WebhookRecord;
use RogueTechPhilippines\MayaGateway\Webhook\Registrar;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_existing_record(string $id, string $name): WebhookRecord
{
    return WebhookRecord::from_array([
        'id'          => $id,
        'name'        => $name,
        'callbackUrl' => 'https://old.example.test/cb',
        'createdAt'   => '2026-01-01T00:00:00Z',
        'updatedAt'   => '2026-01-01T00:00:00Z',
    ]);
}

test('managed_names covers exactly the five events from the rebuild plan', function (): void {
    expect(Registrar::managed_names())->toEqualCanonicalizing([
        'CHECKOUT_SUCCESS',
        'CHECKOUT_FAILURE',
        'PAYMENT_SUCCESS',
        'PAYMENT_FAILED',
        'PAYMENT_EXPIRED',
    ]);
});

test('is_managed reports correctly for in-set and out-of-set names', function (): void {
    expect(Registrar::is_managed('PAYMENT_SUCCESS'))->toBeTrue();
    expect(Registrar::is_managed('CHECKOUT_DROPOUT'))->toBeFalse();
    expect(Registrar::is_managed('unrelated'))->toBeFalse();
});

test('reconcile rejects an empty callback URL', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);
    $endpoint->shouldNotReceive('all');

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('   ');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_registrar_empty_url');
});

test('reconcile bubbles list errors as WP_Error', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);
    $endpoint->expects('all')->andReturn(new WP_Error('wc_maya_http_401', 'Unauthorized'));

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://example.test/cb');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_http_401');
});

test('reconcile deletes only managed webhooks then creates five fresh ones', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);

    $endpoint->expects('all')->andReturn([
        wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'),      // managed → delete
        wc_maya_existing_record('wh-2', 'PAYMENT_FAILED'),       // managed → delete
        wc_maya_existing_record('wh-3', 'CHECKOUT_DROPOUT'),     // not managed → skip
        wc_maya_existing_record('wh-4', 'merchant_custom_hook'), // not managed → skip
    ]);

    // Both managed deletes succeed.
    $endpoint->expects('delete')->with('wh-1')->andReturn(wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'));
    $endpoint->expects('delete')->with('wh-2')->andReturn(wc_maya_existing_record('wh-2', 'PAYMENT_FAILED'));

    // All five managed events get created at the new URL.
    foreach (Registrar::managed_names() as $event) {
        $endpoint->expects('create')->with($event, 'https://new.example.test/cb')
            ->andReturn(WebhookRecord::from_array([
                'id'          => 'new-' . $event,
                'name'        => $event,
                'callbackUrl' => 'https://new.example.test/cb',
            ]));
    }

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://new.example.test/cb');

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['deleted'])->toEqualCanonicalizing([ 'PAYMENT_SUCCESS', 'PAYMENT_FAILED' ]);
    expect($result['skipped'])->toEqualCanonicalizing([ 'CHECKOUT_DROPOUT', 'merchant_custom_hook' ]);
    expect($result['errors'])->toBe([]);
    expect($result['created'])->toHaveCount(5);
});

test('reconcile records per-step errors without aborting the rest of the run', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);

    $endpoint->expects('all')->andReturn([
        wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'),
    ]);

    // Delete of the existing webhook fails.
    $endpoint->expects('delete')->with('wh-1')->andReturn(new WP_Error('wc_maya_http_500', 'oops'));

    // One create fails, four succeed.
    foreach (Registrar::managed_names() as $event) {
        if ('PAYMENT_FAILED' === $event) {
            $endpoint->expects('create')->with($event, 'https://x.test/cb')->andReturn(new WP_Error('wc_maya_http_409', 'dup'));
        } else {
            $endpoint->expects('create')->with($event, 'https://x.test/cb')->andReturn(WebhookRecord::from_array([
                'id'          => 'new-' . $event,
                'name'        => $event,
                'callbackUrl' => 'https://x.test/cb',
            ]));
        }
    }

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://x.test/cb');

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['deleted'])->toBe([]);
    expect($result['created'])->toHaveCount(4);
    expect($result['errors'])->toHaveCount(2);
    expect($result['errors'][0])->toContain('Delete PAYMENT_SUCCESS');
    expect($result['errors'][1])->toContain('Create PAYMENT_FAILED');
});
