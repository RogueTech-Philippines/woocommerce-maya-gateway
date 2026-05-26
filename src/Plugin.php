<?php

/**
 * Plugin bootstrap.
 *
 * @package TaniKyuun\MayaGateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway;

use TaniKyuun\MayaGateway\Admin\AdminAssets;
use TaniKyuun\MayaGateway\Admin\Ajax\TestConnection;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Webhook\WebhookHandler;

/**
 * Plugin entry point — wires services and exits.
 *
 * Each subsystem owns its own hook registration via a static `register()`
 * method so this file stays a one-screen map of the plugin's surface.
 */
class Plugin
{
    public static function init(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [ self::class, 'missing_woocommerce_notice' ]);
            return;
        }

        add_filter('woocommerce_payment_gateways', [ self::class, 'register_gateway' ]);
        add_action('woocommerce_api_maya_webhook', [ WebhookHandler::class, 'handle' ]);

        AdminAssets::register();
        TestConnection::register();
    }

    /**
     * @param array<int,string> $gateways
     * @return array<int,string>
     */
    public static function register_gateway(array $gateways): array
    {
        $gateways[] = MayaGateway::class;
        return $gateways;
    }

    public static function missing_woocommerce_notice(): void
    {
        echo '<div class="error"><p>'
            . esc_html__('WooCommerce Maya Gateway requires WooCommerce to be installed and active.', 'wc-maya-gateway')
            . '</p></div>';
    }
}
