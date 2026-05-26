<?php

/**
 * AJAX handler for the "Simulate webhook" admin button.
 *
 * @package TaniKyuun\MayaGateway\Admin\Ajax
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin\Ajax;

use TaniKyuun\MayaGateway\Admin\AdminAssets;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Webhook\Simulator;
use WP_Error;

/**
 * Wraps the Simulator service in an admin-only AJAX endpoint.
 *
 * Responsibilities are narrow on purpose: permission + nonce + input
 * validation, then delegate to Simulator. The simulator's response
 * (status + decoded body from our own webhook handler) is returned to the
 * browser intact so the admin sees exactly what the handler said.
 */
class SimulateWebhook
{
    public const ACTION = 'wc_maya_simulate_webhook';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [ self::class, 'handle' ]);
    }

    public static function handle(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error([ 'message' => __('Insufficient permissions.', 'wc-maya-gateway') ], 403);
        }

        check_ajax_referer(AdminAssets::NONCE_ACTION, 'nonce');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $status   = isset($_POST['status']) ? sanitize_text_field((string) wp_unslash($_POST['status'])) : '';

        if (0 === $order_id) {
            wp_send_json_error([ 'message' => __('Provide an order ID to simulate against.', 'wc-maya-gateway') ], 400);
        }

        if (! in_array($status, Simulator::ALLOWED_STATUSES, true)) {
            wp_send_json_error([ 'message' => __('Pick a valid simulator status.', 'wc-maya-gateway') ], 400);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (! $order) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %d: order id the user typed. */
                        __('Order #%d was not found.', 'wc-maya-gateway'),
                        $order_id,
                    ),
                ],
                404,
            );
        }

        $gateway = self::find_gateway();
        if (null === $gateway) {
            wp_send_json_error([ 'message' => __('Maya gateway not registered.', 'wc-maya-gateway') ], 500);
            return;
        }

        $settings = new SettingsHelper($gateway);

        if (! $settings->is_sandbox()) {
            wp_send_json_error(
                [
                    'message' => __('Simulator is sandbox-only. Enable "Sandbox mode" to use it.', 'wc-maya-gateway'),
                ],
                403,
            );
        }

        $simulator = new Simulator($settings);
        $result    = $simulator->simulate($order, $status);

        if ($result instanceof WP_Error) {
            wp_send_json_error(
                [
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ],
                400,
            );
        }

        wp_send_json_success(
            [
                'status' => $result['status'],
                'body'   => $result['body'],
            ],
        );
    }

    private static function find_gateway(): ?MayaGateway
    {
        if (! function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways[ MayaGateway::ID ] ?? null;

        return $gateway instanceof MayaGateway ? $gateway : null;
    }
}
