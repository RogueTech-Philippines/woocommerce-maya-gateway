<?php

/**
 * Unit tests for the WC Blocks payment-method integration.
 *
 * Exercises the pure-static surface (data shape + enabled rule) plus the
 * registration callback. We rely on the `AbstractPaymentMethodType` stub
 * in `tests/stubs.php`; the real class lives in WC core, but its public
 * shape (`get_setting`, `$settings`, etc.) is faithfully reproduced there.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Blocks
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Blocks;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TaniKyuun\MayaGateway\Blocks\MayaBlocksPaymentMethod;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

test('build_payment_method_data returns the four keys the JS bundle reads', function (): void {
    $data = MayaBlocksPaymentMethod::build_payment_method_data(
        'Maya',
        'Pay securely via Maya.',
        'https://example.test/icon.svg',
        [ 'products', 'refunds' ],
    );

    expect($data)->toBe([
        'title'       => 'Maya',
        'description' => 'Pay securely via Maya.',
        'icon'        => 'https://example.test/icon.svg',
        'supports'    => [ 'products', 'refunds' ],
    ]);
});

test('build_payment_method_data drops non-string entries from supports', function (): void {
    $data = MayaBlocksPaymentMethod::build_payment_method_data(
        'Maya',
        '',
        '',
        // Defensive: callers might pass a polluted array.
        [ 'products', 42, null, 'refunds', false ],
    );

    expect($data['supports'])->toBe([ 'products', 'refunds' ]);
});

test('build_payment_method_data preserves empty title/description/icon strings as-is', function (): void {
    $data = MayaBlocksPaymentMethod::build_payment_method_data('', '', '', [ 'products' ]);

    expect($data['title'])->toBe('')
        ->and($data['description'])->toBe('')
        ->and($data['icon'])->toBe('');
});

test('is_enabled is true when settings have enabled=yes', function (): void {
    expect(MayaBlocksPaymentMethod::is_enabled([ 'enabled' => 'yes' ]))->toBeTrue();
});

test('is_enabled is false when settings have enabled=no', function (): void {
    expect(MayaBlocksPaymentMethod::is_enabled([ 'enabled' => 'no' ]))->toBeFalse();
});

test('is_enabled is false when enabled key is missing', function (): void {
    expect(MayaBlocksPaymentMethod::is_enabled([]))->toBeFalse();
    expect(MayaBlocksPaymentMethod::is_enabled([ 'title' => 'Maya' ]))->toBeFalse();
});

test('is_enabled treats any non-yes value as disabled', function (): void {
    expect(MayaBlocksPaymentMethod::is_enabled([ 'enabled' => '1' ]))->toBeFalse();
    expect(MayaBlocksPaymentMethod::is_enabled([ 'enabled' => 'true' ]))->toBeFalse();
    expect(MayaBlocksPaymentMethod::is_enabled([ 'enabled' => '' ]))->toBeFalse();
});

test('get_name returns the same id the classic gateway uses', function (): void {
    $method = new MayaBlocksPaymentMethod();
    expect($method->get_name())->toBe(MayaGateway::ID)
        ->and(MayaGateway::ID)->toBe('maya_checkout');
});

test('register hooks the registration callback onto woocommerce_blocks_payment_method_type_registration', function (): void {
    Actions\expectAdded('woocommerce_blocks_payment_method_type_registration')
        ->once()
        ->with([ MayaBlocksPaymentMethod::class, 'register_payment_method' ]);

    MayaBlocksPaymentMethod::register();
});

test('register_payment_method hands a fresh MayaBlocksPaymentMethod to the registry', function (): void {
    $registry = new PaymentMethodRegistry();

    MayaBlocksPaymentMethod::register_payment_method($registry);

    expect($registry->registered)->toHaveCount(1)
        ->and($registry->registered[0])->toBeInstanceOf(MayaBlocksPaymentMethod::class)
        ->and($registry->registered[0]->get_name())->toBe(MayaGateway::ID);
});

test('initialize pulls settings out of the woocommerce_maya_checkout_settings option', function (): void {
    Functions\expect('get_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings', [])
        ->andReturn([
            'enabled'     => 'yes',
            'title'       => 'Maya (sandbox)',
            'description' => 'Pay with Maya.',
        ]);

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->is_active())->toBeTrue();
});

test('initialize coerces a non-array option payload into an empty array', function (): void {
    Functions\expect('get_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings', [])
        ->andReturn(false);

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->is_active())->toBeFalse();
});

test('is_active flips with the gateway enabled flag', function (): void {
    Functions\expect('get_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings', [])
        ->andReturn([ 'enabled' => 'no' ]);

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->is_active())->toBeFalse();
});

test('get_payment_method_data wires through the saved title and description', function (): void {
    Functions\expect('get_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings', [])
        ->andReturn([
            'enabled'     => 'yes',
            'title'       => 'Pay with Maya',
            'description' => 'Redirected to Maya.',
        ]);
    Functions\when('WC')->justReturn(null);
    Filters\expectApplied('wc_maya_blocks_icon_url')
        ->once()
        ->with('')
        ->andReturn('');

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->get_payment_method_data())->toBe([
        'title'       => 'Pay with Maya',
        'description' => 'Redirected to Maya.',
        'icon'        => '',
        'supports'    => [ 'products' ],
    ]);
});

test('get_payment_method_data lets the wc_maya_blocks_icon_url filter inject an icon', function (): void {
    Functions\expect('get_option')
        ->once()
        ->andReturn([ 'enabled' => 'yes', 'title' => 'Maya', 'description' => '' ]);
    Functions\when('WC')->justReturn(null);
    Filters\expectApplied('wc_maya_blocks_icon_url')
        ->once()
        ->with('')
        ->andReturn('https://example.test/maya.svg');

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->get_payment_method_data()['icon'])->toBe('https://example.test/maya.svg');
});

test('get_payment_method_data falls back to "Maya" when the title setting is missing', function (): void {
    Functions\expect('get_option')
        ->once()
        ->andReturn([ 'enabled' => 'yes' ]);
    Functions\when('WC')->justReturn(null);
    Functions\when('apply_filters')->returnArg(2);

    $method = new MayaBlocksPaymentMethod();
    $method->initialize();

    expect($method->get_payment_method_data()['title'])->toBe('Maya');
});

test('get_payment_method_script_handles registers wc-maya-blocks once with the right deps', function (): void {
    if (! defined('WC_MAYA_PLUGIN_FILE')) {
        define('WC_MAYA_PLUGIN_FILE', __FILE__);
    }

    Functions\expect('wp_script_is')
        ->once()
        ->with('wc-maya-blocks', 'registered')
        ->andReturn(false);

    Functions\expect('plugins_url')
        ->once()
        ->with('assets/js/maya-blocks.js', WC_MAYA_PLUGIN_FILE)
        ->andReturn('https://example.test/wp-content/plugins/wcm/assets/js/maya-blocks.js');

    $registered = [];
    Functions\expect('wp_register_script')
        ->once()
        ->andReturnUsing(static function ($handle, $src, $deps, $ver, $in_footer) use (&$registered): bool {
            $registered = compact('handle', 'src', 'deps', 'ver', 'in_footer');
            return true;
        });

    Functions\expect('wp_set_script_translations')
        ->once()
        ->with('wc-maya-blocks', 'wc-maya-gateway');

    $method  = new MayaBlocksPaymentMethod();
    $handles = $method->get_payment_method_script_handles();

    expect($handles)->toBe([ 'wc-maya-blocks' ]);
    expect($registered['handle'])->toBe('wc-maya-blocks');
    expect($registered['deps'])->toBe([
        'wc-blocks-registry',
        'wp-element',
        'wp-html-entities',
        'wp-i18n',
    ]);
    expect($registered['in_footer'])->toBeTrue();
});

test('get_payment_method_script_handles is idempotent when the handle is already registered', function (): void {
    if (! defined('WC_MAYA_PLUGIN_FILE')) {
        define('WC_MAYA_PLUGIN_FILE', __FILE__);
    }

    Functions\expect('wp_script_is')
        ->once()
        ->with('wc-maya-blocks', 'registered')
        ->andReturn(true);

    Functions\expect('wp_register_script')->never();
    Functions\expect('wp_set_script_translations')->never();

    $method = new MayaBlocksPaymentMethod();
    expect($method->get_payment_method_script_handles())->toBe([ 'wc-maya-blocks' ]);
});

test('SCRIPT_HANDLE constant is the namespaced wc-maya-blocks handle', function (): void {
    expect(MayaBlocksPaymentMethod::SCRIPT_HANDLE)->toBe('wc-maya-blocks');
});
