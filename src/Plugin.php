<?php

/**
 * Plugin bootstrap.
 *
 * @package RogueTechPhilippines\MayaGateway
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway;

use RogueTechPhilippines\MayaGateway\Admin\AdminAssets;
use RogueTechPhilippines\MayaGateway\Admin\Ajax\CapturePayment;
use RogueTechPhilippines\MayaGateway\Admin\Ajax\RefreshWebhooks;
use RogueTechPhilippines\MayaGateway\Admin\Ajax\SimulateWebhook;
use RogueTechPhilippines\MayaGateway\Admin\Ajax\TestConnection;
use RogueTechPhilippines\MayaGateway\Admin\EventLog\EventLogPage;
use RogueTechPhilippines\MayaGateway\Admin\OrderActions\CaptureButton;
use RogueTechPhilippines\MayaGateway\Admin\OrderActions\CapturePanel;
use RogueTechPhilippines\MayaGateway\Blocks\MayaBlocksPaymentMethod;
use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Gateway\ReturnHandler;
use RogueTechPhilippines\MayaGateway\Webhook\RetryQueue;
use RogueTechPhilippines\MayaGateway\Webhook\WebhookHandler;

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

        AdminAssets::register();
        TestConnection::register();
        SimulateWebhook::register();
        RefreshWebhooks::register();
        CapturePayment::register();
        CaptureButton::register();
        CapturePanel::register();
        WebhookHandler::register();
        ReturnHandler::register();
        MayaBlocksPaymentMethod::register();
        EventLogPage::register();
        RetryQueue::register();

        self::load_textdomain();
    }

    /**
     * Loads the bundled translation files (.mo) shipped under
     * `languages/`. WP applies any user-installed translations from
     * `wp-content/languages/plugins/wc-maya-gateway-*.mo` automatically;
     * the call here is only needed for the bundled-default lookup so
     * that a fresh install without WP.org-hosted translations still
     * picks up our shipped strings.
     */
    private static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'wc-maya-gateway',
            false,
            dirname(plugin_basename(WC_MAYA_PLUGIN_FILE)) . '/languages',
        );
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
