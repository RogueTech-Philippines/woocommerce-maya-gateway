<?php

/**
 * AJAX handler for the "Capture" order-actions button.
 *
 * @package TaniKyuun\MayaGateway\Admin\Ajax
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin\Ajax;

use TaniKyuun\MayaGateway\Admin\AdminAssets;
use TaniKyuun\MayaGateway\Api\Endpoints\Payments;
use TaniKyuun\MayaGateway\Gateway\CaptureProcessor;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Util\Logger;
use WC_Order;
use WP_Error;

/**
 * Thin AJAX wrapper: permission + nonce + input validation, then delegates
 * to {@see CaptureProcessor} for the business logic.
 *
 * Returns either the structured success payload (so the JS can re-render
 * authorized/captured/remaining without a second API call) or the WP_Error
 * code+message verbatim (the same shape the SimulateWebhook handler uses).
 */
class CapturePayment
{
    public const ACTION = 'wc_maya_capture_payment';

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
        $amount   = isset($_POST['capture_amount']) ? (float) wp_unslash($_POST['capture_amount']) : 0.0;

        if ($order_id <= 0) {
            wp_send_json_error([ 'message' => __('Order id is required.', 'wc-maya-gateway') ], 400);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (! $order instanceof WC_Order) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %d: order id we couldn't load. */
                        __('Could not load order #%d.', 'wc-maya-gateway'),
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

        $helper    = new SettingsHelper($gateway);
        $processor = new CaptureProcessor(
            new Payments($gateway->build_api_client()),
            new Logger($helper->debug_log_enabled()),
        );

        $result = $processor->capture($order, $amount);

        if ($result instanceof WP_Error) {
            wp_send_json_error(
                [
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ],
                400,
            );
        }

        wp_send_json_success($result);
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
