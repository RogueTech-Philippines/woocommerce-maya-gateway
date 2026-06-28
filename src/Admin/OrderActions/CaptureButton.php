<?php

/**
 * "Capture" button beside the Refund button in the order-edit screen.
 *
 * @package TaniKyuun\MayaGateway\Admin\OrderActions
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin\OrderActions;

use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Value\AuthorizationType;
use WC_Order;

/**
 * Adds a "Capture" button to the row that holds the Refund button.
 *
 * Renders only when:
 *
 *  - the order's payment method is Maya, AND
 *  - the saved `_maya_authorization_type` is a manual-capture mode, AND
 *  - Maya reports at least one authorization record with `canCapture: true`
 *    for the order's RRN.
 *
 * The synchronous Maya lookup is acceptable because the order-edit screen
 * is already a heavyweight admin page and merchants reach it infrequently.
 * The {@see CapturePanel} reuses the same lookup for its balance read.
 */
class CaptureButton
{
    public static function register(): void
    {
        add_action('woocommerce_order_item_add_action_buttons', [ self::class, 'render' ]);
    }

    public static function render(WC_Order $order): void
    {
        if (! self::should_render($order)) {
            return;
        }

        echo '<button type="button" class="button button-primary wc-maya-capture-trigger" data-target="wc-maya-capture-panel">'
            . esc_html__('Capture Maya payment', 'wc-maya-gateway')
            . '</button>';
    }

    public static function should_render(WC_Order $order): bool
    {
        if (MayaGateway::ID !== $order->get_payment_method()) {
            return false;
        }

        $auth_type = AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE));
        if (! $auth_type->is_manual_capture()) {
            return false;
        }

        return null !== AuthorizedPaymentLookup::for_order_id((int) $order->get_id());
    }
}
