<?php

/**
 * Unit tests for the local-dev webhook Simulator.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Mockery;
use RogueTechPhilippines\MayaGateway\Settings\SettingsHelper;
use RogueTechPhilippines\MayaGateway\Webhook\Simulator;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

function wc_maya_fake_order(int $id = 42, float $total = 100.5, string $currency = 'PHP'): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->shouldReceive('get_id')->andReturn($id);
    $order->shouldReceive('get_total')->andReturn($total);
    $order->shouldReceive('get_currency')->andReturn($currency);
    return $order;
}

function wc_maya_fake_helper(bool $is_sandbox = true): SettingsHelper
{
    $gateway    = new WC_Payment_Gateway();
    $reflection = new \ReflectionProperty(WC_Payment_Gateway::class, 'settings');
    $reflection->setAccessible(true);
    $reflection->setValue($gateway, [
        'is_sandbox'            => $is_sandbox ? 'yes' : 'no',
        'local_dev_webhook_url' => 'https://tunnel.example.test',
    ]);

    // Keep a non-empty URL to prove simulate() no longer depends on transport.
    return new SettingsHelper($gateway);
}

beforeEach(function (): void {
    Functions\when('wp_json_encode')->alias(static fn(mixed $value): string => (string) json_encode($value));
    Functions\when('home_url')->alias(static fn(string $path = ''): string => 'https://example.test' . $path);
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
    Functions\when('get_option')->alias(static fn(string $name, mixed $default = null): mixed => $default);
});

test('build_payload returns a Maya-shaped record with simulated marker', function (): void {
    $payload = Simulator::build_payload(
        wc_maya_fake_order(99, 250.0, 'PHP'),
        'PAYMENT_SUCCESS',
    );

    expect($payload['status'])->toBe('PAYMENT_SUCCESS');
    expect($payload['isPaid'])->toBeTrue();
    expect($payload['canRefund'])->toBeTrue();
    expect($payload['canCapture'])->toBeFalse();
    expect($payload['amount'])->toBe(250.0);
    expect($payload['currency'])->toBe('PHP');
    expect($payload['requestReferenceNumber'])->toBe('99');
    expect($payload['metadata'])->toMatchArray([
        'simulated'   => true,
        'environment' => 'local_development',
    ]);
    expect($payload['id'])->toStartWith('simulated_');
});

test('build_payload flips canRefund/isPaid off for failure-class statuses', function (): void {
    $payload = Simulator::build_payload(
        wc_maya_fake_order(1, 10.0, 'PHP'),
        'PAYMENT_FAILED',
    );

    expect($payload['isPaid'])->toBeFalse();
    expect($payload['canRefund'])->toBeFalse();
});

test('simulate rejects statuses outside the allowed set', function (): void {
    $simulator = new Simulator(wc_maya_fake_helper());
    $result    = $simulator->simulate(wc_maya_fake_order(), 'PAYMENT_REFUNDED');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_simulator_invalid_status');
});

test('simulate refuses to run when the gateway is not in sandbox mode', function (): void {
    $simulator = new Simulator(wc_maya_fake_helper(is_sandbox: false));
    $result    = $simulator->simulate(wc_maya_fake_order(), 'PAYMENT_SUCCESS');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_simulator_not_sandbox');
});

test('simulate dispatches internally as trusted simulation', function (): void {
    $order = wc_maya_fake_order(42);
    $order->shouldReceive('is_paid')->andReturn(false);
    $order->shouldReceive('update_status')->with('failed', Mockery::type('string'))->once();

    Functions\when('wc_get_order')->alias(static fn(int $id): ?WC_Order => 42 === $id ? $order : null);

    $simulator = new Simulator(wc_maya_fake_helper());
    $result    = $simulator->simulate($order, 'PAYMENT_FAILED');

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['status'])->toBe(200);
    expect($result['body'])->toMatchArray([
        'received'  => true,
        'simulated' => true,
        'event'     => 'PAYMENT_FAILED',
    ]);
});
