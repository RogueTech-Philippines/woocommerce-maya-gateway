<?php

/**
 * Customer-return handler: where Maya redirects the browser after checkout.
 *
 * @package RogueTechPhilippines\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Gateway;

use RogueTechPhilippines\MayaGateway\Settings\SettingsHelper;
use WC_Order;

/**
 * Handles `wc-api=maya_return&order=<id>&status=success|failed`.
 *
 * The customer's browser cannot be trusted to report payment outcome — Maya
 * controls the signed webhook for the authoritative signal — so this handler
 * never mutates the order status. A success return only drains the cart and
 * forwards to the order-received page; the webhook dispatcher owns every
 * payment state transition.
 *
 * Failure / cancel returns are also handled: a "failed" status redirects
 * back to the payment page so the customer can retry, without flipping the
 * order's status (which the webhook owns).
 */
class ReturnHandler
{
    public const ROUTE = 'maya_return';

    public static function register(): void
    {
        add_action('woocommerce_api_' . self::ROUTE, [ self::class, 'handle' ]);
    }

    public static function handle(): void
    {
        $order_id = isset($_GET['order']) ? absint(wp_unslash($_GET['order'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status   = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $order = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (! $order instanceof WC_Order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        if ('failed' === $status) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    __('Maya reported the payment did not complete. Please try again.', 'wc-maya-gateway'),
                    'error',
                );
            }
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }


        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    public static function url_base_for_order(int $order_id, SettingsHelper $settings): string
    {
        unset($settings); // reserved for a future per-environment override
        return home_url('/?wc-api=' . self::ROUTE . '&order=' . $order_id);
    }
}
