<?php

/**
 * Plugin bootstrap.
 *
 * @package TaniKyuun\MayaGateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway;

use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Webhook\WebhookHandler;

/**
 * Plugin entry point and service registration.
 *
 * Placeholder scaffold — wires the gateway and the webhook endpoint so the
 * plugin shows up in WooCommerce and the webhook URL responds. Textdomain
 * loading and other lifecycle plumbing is added incrementally.
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
