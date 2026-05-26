<?php

/**
 * Unit tests for the settings helper.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Settings
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use WC_Payment_Gateway;

/**
 * @param array<string,mixed> $settings
 */
function fake_gateway(array $settings): WC_Payment_Gateway
{
    return new class ($settings) extends WC_Payment_Gateway {
        /** @param array<string,mixed> $values */
        public function __construct(array $values)
        {
            $this->settings = $values;
        }
    };
}

beforeEach(function (): void {
    Functions\when('home_url')->alias(
        static fn(string $path = ''): string => 'https://example.test' . $path,
    );
});

test('webhook_url falls back to home_url when no override is set', function (): void {
    $helper = new SettingsHelper(fake_gateway([ 'local_dev_webhook_url' => '' ]));

    expect($helper->webhook_url())->toBe('https://example.test/?wc-api=maya_webhook');
});

test('webhook_url appends the wc-api path when a bare host override is set', function (): void {
    $helper = new SettingsHelper(fake_gateway([ 'local_dev_webhook_url' => 'https://stork.example.com' ]));

    expect($helper->webhook_url())->toBe('https://stork.example.com/?wc-api=maya_webhook');
});

test('webhook_url strips trailing slashes from the override before composing', function (): void {
    $helper = new SettingsHelper(fake_gateway([ 'local_dev_webhook_url' => 'https://stork.example.com/' ]));

    expect($helper->webhook_url())->toBe('https://stork.example.com/?wc-api=maya_webhook');
});

test('webhook_url returns the override verbatim when it already contains wc-api', function (): void {
    $full   = 'https://stork.example.com/?wc-api=maya_webhook';
    $helper = new SettingsHelper(fake_gateway([ 'local_dev_webhook_url' => $full ]));

    expect($helper->webhook_url())->toBe($full);
});

test('return_url always uses home_url even when a local-dev override is set', function (): void {
    $helper = new SettingsHelper(fake_gateway([ 'local_dev_webhook_url' => 'https://tunnel.example.test' ]));

    // The override is for Maya's webhook server, but the customer's browser
    // is pointed at this site directly — return URL must be home_url-based.
    expect($helper->return_url(42))->toBe('https://example.test/?wc-api=maya_return&order=42');
});
