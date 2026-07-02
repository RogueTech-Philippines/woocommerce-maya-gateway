<?php

/**
 * AJAX handler for the "Refresh from Maya" button under the registered-
 * webhooks table.
 *
 * @package RogueTechPhilippines\MayaGateway\Admin\Ajax
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Admin\Ajax;

use RogueTechPhilippines\MayaGateway\Admin\AdminAssets;
use RogueTechPhilippines\MayaGateway\Api\Endpoints\Webhooks;
use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Webhook\Registrar;
use WP_Error;

/**
 * Re-reads Maya's webhook list and returns it as JSON for the status table.
 *
 * Also used on page load (the renderer ships an empty table; the JS hits
 * this endpoint once it boots) so the settings page itself never blocks on
 * a synchronous Maya call.
 */
class RefreshWebhooks
{
    public const ACTION = 'wc_maya_refresh_webhooks';

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

        $gateway = self::find_gateway();
        if (null === $gateway) {
            wp_send_json_error([ 'message' => __('Maya gateway not registered.', 'wc-maya-gateway') ], 500);
            return;
        }

        $response = (new Webhooks($gateway->build_api_client()))->all();
        if ($response instanceof WP_Error) {
            wp_send_json_error(
                [
                    'message' => $response->get_error_message(),
                    'code'    => $response->get_error_code(),
                ],
                400,
            );
        }

        $rows = [];
        foreach ($response as $record) {
            $row            = $record->to_array();
            $row['managed'] = Registrar::is_managed($record->name);
            $rows[]         = $row;
        }

        wp_send_json_success([
            'webhooks' => $rows,
        ]);
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
