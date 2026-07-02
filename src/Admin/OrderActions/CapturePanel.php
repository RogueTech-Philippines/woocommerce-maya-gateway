<?php

/**
 * "Capture" form panel rendered below the order totals.
 *
 * @package RogueTechPhilippines\MayaGateway\Admin\OrderActions
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Admin\OrderActions;

use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Value\AuthorizationType;
use RogueTechPhilippines\MayaGateway\Value\Money;
use RogueTechPhilippines\MayaGateway\Value\PaymentRecord;
use WC_Order;

/**
 * Renders the capture amount + balances + submit panel.
 *
 * Replaces the legacy plugin's inline `views/manual-capture.php` partial
 * with a proper template file at `templates/admin/capture-panel.php`. The
 * panel is hidden by default (CSS) and toggled by the
 * {@see CaptureButton}'s click handler in `maya-admin.js`.
 *
 * Like CaptureButton, this calls Maya synchronously on render — but only
 * after the gateway-and-auth-type gate, so non-Maya orders pay no cost.
 */
class CapturePanel
{
    public static function register(): void
    {
        add_action('woocommerce_admin_order_totals_after_total', [ self::class, 'render' ]);
    }

    public static function render(int $order_id): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (! $order instanceof WC_Order) {
            return;
        }

        if (MayaGateway::ID !== $order->get_payment_method()) {
            return;
        }

        $auth_type = AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE));
        if (! $auth_type->is_manual_capture()) {
            return;
        }

        $payment = AuthorizedPaymentLookup::for_order_id((int) $order->get_id());
        if (! $payment instanceof PaymentRecord) {
            return;
        }

        $authorized = $payment->amount;
        $captured   = $payment->captured_amount ?? new Money(0.0, $authorized->currency);
        $remaining  = new Money($authorized->value - $captured->value, $authorized->currency);

        $template = plugin_dir_path(WC_MAYA_PLUGIN_FILE) . 'templates/admin/capture-panel.php';
        if (! file_exists($template)) {
            return;
        }

        include $template;
    }

}
