<?php

/**
 * Admin asset loader.
 *
 * @package TaniKyuun\MayaGateway\Admin
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin;

use TaniKyuun\MayaGateway\Admin\Ajax\CapturePayment;
use TaniKyuun\MayaGateway\Admin\Ajax\RefreshWebhooks;
use TaniKyuun\MayaGateway\Admin\Ajax\SimulateWebhook;
use TaniKyuun\MayaGateway\Admin\Ajax\TestConnection;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;

/**
 * Enqueues the JS/CSS used by the gateway settings screen and ships the
 * data bag (`wcMayaAdmin`) the script reads via wp_localize_script.
 *
 * Scoped to WooCommerce → Settings → Payments → Maya Checkout — never loads
 * anywhere else.
 */
class AdminAssets
{
    public const NONCE_ACTION = 'wc_maya_admin';

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [ self::class, 'enqueue' ]);
    }

    public static function enqueue(string $hook): void
    {
        if (! self::is_maya_settings_screen($hook) && ! self::is_order_edit_screen($hook)) {
            return;
        }

        $base = plugins_url('', WC_MAYA_PLUGIN_FILE);
        $ver  = (string) (defined('WP_DEBUG') && WP_DEBUG ? time() : '1.0.0');

        wp_enqueue_style('wc-maya-admin', $base . '/assets/css/maya-admin.css', [], $ver);
        wp_enqueue_script('wc-maya-admin', $base . '/assets/js/maya-admin.js', [ 'jquery' ], $ver, true);

        wp_localize_script(
            'wc-maya-admin',
            'wcMayaAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
                'actions' => [
                    'testConnection'  => TestConnection::ACTION,
                    'simulateWebhook' => SimulateWebhook::ACTION,
                    'refreshWebhooks' => RefreshWebhooks::ACTION,
                    'capturePayment'  => CapturePayment::ACTION,
                ],
                'i18n' => [
                    'show'                  => __('Show', 'wc-maya-gateway'),
                    'hide'                  => __('Hide', 'wc-maya-gateway'),
                    'testing'               => __('Testing…', 'wc-maya-gateway'),
                    'copied'                => __('Copied!', 'wc-maya-gateway'),
                    'copy'                  => __('Copy', 'wc-maya-gateway'),
                    'publicKeyLabel'        => __('Public key', 'wc-maya-gateway'),
                    'secretKeyLabel'        => __('Secret key', 'wc-maya-gateway'),
                    'publicKeyOk'           => __('Checkout session created (id %s) — no payment was taken.', 'wc-maya-gateway'),
                    'secretKeyOk'           => __('%d webhook(s) registered with Maya for this account.', 'wc-maya-gateway'),
                    'envSandbox'            => __('Testing against sandbox (pg-sandbox.paymaya.com).', 'wc-maya-gateway'),
                    'envProduction'         => __('Testing against production (pg.maya.ph).', 'wc-maya-gateway'),
                    'unexpectedResponse'    => __('Unexpected response from the server.', 'wc-maya-gateway'),
                    'simulateAccepted'      => __('handler accepted (would dispatch event)', 'wc-maya-gateway'),
                    'simulateRejected'      => __('handler rejected (see body for reason)', 'wc-maya-gateway'),
                    'webhookStatusEmpty'    => __('No webhooks registered with Maya for this account.', 'wc-maya-gateway'),
                    'webhookStatusManaged'  => __('Managed by this plugin', 'wc-maya-gateway'),
                    'webhookStatusExternal' => __('External — left alone on save', 'wc-maya-gateway'),
                    'captureSubmitting'     => __('Capturing…', 'wc-maya-gateway'),
                    'captureSuccess'        => __('Capture submitted. Webhook will confirm the new balance.', 'wc-maya-gateway'),
                ],
            ],
        );
    }

    private static function is_maya_settings_screen(string $hook): bool
    {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return false;
        }

        $tab     = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset($_GET['section']) ? sanitize_key((string) wp_unslash($_GET['section'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return 'checkout' === $tab && MayaGateway::ID === $section;
    }

    /**
     * Loads on the order edit screen (classic `post.php` for the `shop_order`
     * post type and the HPOS `woocommerce_page_wc-orders` admin page) so the
     * Capture button + panel JS is available wherever a Maya order is
     * edited.
     */
    private static function is_order_edit_screen(string $hook): bool
    {
        if ('woocommerce_page_wc-orders' === $hook) {
            return true; // HPOS order list/edit
        }

        if ('post.php' === $hook) {
            $post_id   = isset($_GET['post']) ? absint(sanitize_text_field(wp_unslash($_GET['post']))) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_type = $post_id > 0 && function_exists('get_post_type')
                ? (string) get_post_type($post_id)
                : '';
            return 'shop_order' === $post_type;
        }

        return false;
    }
}
