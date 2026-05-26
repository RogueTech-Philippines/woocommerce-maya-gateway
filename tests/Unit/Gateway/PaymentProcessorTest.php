<?php

/**
 * Unit tests for the PaymentProcessor.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use Mockery;
use TaniKyuun\MayaGateway\Api\Endpoints\Checkouts;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Gateway\PaymentProcessor;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\CheckoutSession;
use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Gateway;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
    Functions\when('home_url')->alias(static fn(string $path = ''): string => 'https://example.test' . $path);
    Functions\when('wc_add_notice')->justReturn(null);
});

function wc_maya_make_order(array $overrides = []): WC_Order
{
    $defaults = [
        'id'                   => 42,
        'total'                => 199.50,
        'currency'             => 'PHP',
        'discount_total'       => 0.0,
        'shipping_total'       => 0.0,
        'subtotal'             => 199.50,
        'billing_first_name'   => 'Juan',
        'billing_last_name'    => 'Dela Cruz',
        'billing_phone'        => '+639170000000',
        'billing_email'        => 'juan@example.test',
        'billing_address_1'    => '123 Rizal',
        'billing_address_2'    => '',
        'billing_city'         => 'Manila',
        'billing_state'        => 'MM',
        'billing_postcode'     => '1000',
        'billing_country'      => 'PH',
        'shipping_first_name'  => '',
        'shipping_last_name'   => '',
        'shipping_address_1'   => '',
        'shipping_address_2'   => '',
        'shipping_city'        => '',
        'shipping_state'       => '',
        'shipping_postcode'    => '',
        'shipping_country'     => '',
        'checkout_payment_url' => 'https://example.test/checkout/pay',
        'items'                => [],
    ];
    $values = array_merge($defaults, $overrides);

    $order = Mockery::mock(WC_Order::class);
    foreach ($values as $key => $val) {
        $order->shouldReceive('get_' . $key)->andReturn($val);
    }
    return $order;
}

function wc_maya_make_line_item(string $name, int $quantity, float $total, int $product_id = 1): WC_Order_Item_Product
{
    $item = Mockery::mock(WC_Order_Item_Product::class);
    $item->shouldReceive('get_name')->andReturn($name);
    $item->shouldReceive('get_quantity')->andReturn($quantity);
    $item->shouldReceive('get_total')->andReturn($total);
    $item->shouldReceive('get_product_id')->andReturn($product_id);
    return $item;
}

function wc_maya_make_settings_helper(): SettingsHelper
{
    $gateway = new WC_Payment_Gateway();
    return new SettingsHelper($gateway);
}

test('build_payload composes Maya-shaped totalAmount, buyer, items, and redirects', function (): void {
    $order = wc_maya_make_order([
        'items' => [
            wc_maya_make_line_item('Widget', 2, 99.75, 5),
        ],
    ]);

    $payload = PaymentProcessor::build_payload(
        $order,
        '42',
        'https://example.test/?wc-api=maya_return&order=42',
    );

    expect($payload['totalAmount'])->toMatchArray([
        'value'    => 199.5,
        'currency' => 'PHP',
    ]);
    expect($payload['totalAmount']['details'])->toMatchArray([
        'discount'    => 0.0,
        'shippingFee' => 0.0,
        'subtotal'    => 199.5,
    ]);
    expect($payload['buyer']['firstName'])->toBe('Juan');
    expect($payload['buyer']['contact']['email'])->toBe('juan@example.test');
    expect($payload['items'])->toHaveCount(1);
    expect($payload['items'][0])->toMatchArray([
        'name'        => 'Widget',
        'description' => 'Widget',
        'quantity'    => 2,
        'code'        => '5',
    ]);
    expect($payload['items'][0]['totalAmount'])->toBe([ 'value' => 99.75 ]);
    expect($payload['requestReferenceNumber'])->toBe('42');
    expect($payload['redirectUrl'])->toMatchArray([
        'success' => 'https://example.test/?wc-api=maya_return&order=42&status=success',
        'failure' => 'https://example.test/?wc-api=maya_return&order=42&status=failed',
        'cancel'  => 'https://example.test/checkout/pay',
    ]);
});

test('build_payload falls back to billing fields when shipping is blank', function (): void {
    $order = wc_maya_make_order();

    $payload = PaymentProcessor::build_payload($order, '42', 'https://example.test/r');

    expect($payload['buyer']['shippingAddress'])->toMatchArray([
        'firstName'    => 'Juan',
        'lastName'     => 'Dela Cruz',
        'line1'        => '123 Rizal',
        'city'         => 'Manila',
        'zipCode'      => '1000',
        'countryCode'  => 'PH',
        'shippingType' => 'ST',
    ]);
});

test('build_payload prefers shipping address over billing when present', function (): void {
    $order = wc_maya_make_order([
        'shipping_first_name' => 'Maria',
        'shipping_last_name'  => 'Santos',
        'shipping_address_1'  => '456 Bonifacio',
        'shipping_city'       => 'Cebu',
        'shipping_postcode'   => '6000',
        'shipping_country'    => 'PH',
    ]);

    $payload = PaymentProcessor::build_payload($order, '42', 'https://example.test/r');

    expect($payload['buyer']['shippingAddress']['firstName'])->toBe('Maria');
    expect($payload['buyer']['shippingAddress']['line1'])->toBe('456 Bonifacio');
    expect($payload['buyer']['shippingAddress']['city'])->toBe('Cebu');
});

test('process persists meta and returns success on a happy-path createCheckout', function (): void {
    $order = wc_maya_make_order();
    $order->shouldReceive('update_meta_data')->with(MayaGateway::META_CHECKOUT_ID, 'chk_abc')->once();
    $order->shouldReceive('update_meta_data')->with(MayaGateway::META_IDEMPOTENCY_KEY, '42')->once();
    $order->shouldReceive('save')->once();

    $endpoint = Mockery::mock(Checkouts::class);
    $endpoint->expects('create')
        ->andReturn(new CheckoutSession('chk_abc', 'https://maya.test/c/abc'));

    $processor = new PaymentProcessor($endpoint, wc_maya_make_settings_helper(), new Logger(false));
    $result    = $processor->process($order);

    expect($result)->toBe([
        'result'   => 'success',
        'redirect' => 'https://maya.test/c/abc',
    ]);
});

test('process returns failure and adds a notice when createCheckout errors', function (): void {
    $order = wc_maya_make_order();
    $order->shouldNotReceive('update_meta_data');
    $order->shouldNotReceive('save');

    $endpoint = Mockery::mock(Checkouts::class);
    $endpoint->expects('create')->andReturn(new WP_Error('wc_maya_http_400', 'Missing parameter'));

    $processor = new PaymentProcessor($endpoint, wc_maya_make_settings_helper(), new Logger(false));
    $result    = $processor->process($order);

    expect($result)->toBe([ 'result' => 'failure' ]);
});
