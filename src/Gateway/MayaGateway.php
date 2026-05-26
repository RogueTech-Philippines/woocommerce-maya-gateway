<?php

/**
 * Maya WC_Payment_Gateway implementation.
 *
 * @package TaniKyuun\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Gateway;

use WC_Payment_Gateway;

/**
 * Hosted-checkout integration with Maya.
 *
 * Placeholder scaffold — payment, refund, asset, and AJAX functionality is
 * added incrementally. For now this just registers the gateway so it appears
 * in WooCommerce → Settings → Payments with editable credentials.
 */
class MayaGateway extends WC_Payment_Gateway
{
    public const ID = 'maya_checkout';

    public const META_CHECKOUT_ID = '_maya_checkout_id';
    public const META_PAYMENT_ID  = '_maya_payment_id';

    public function __construct()
    {
        $this->id                 = self::ID;
        $this->method_title       = __('Maya Checkout', 'wc-maya-gateway');
        $this->method_description = __('Accept payments via Maya (cards, e-wallets, QR Ph).', 'wc-maya-gateway');
        $this->has_fields         = false;
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ]);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable / Disable', 'wc-maya-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Maya Checkout', 'wc-maya-gateway'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'wc-maya-gateway'),
                'type'        => 'text',
                'description' => __('Title shown to customers during checkout.', 'wc-maya-gateway'),
                'default'     => __('Maya', 'wc-maya-gateway'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => __('Description', 'wc-maya-gateway'),
                'type'    => 'textarea',
                'default' => __('Pay securely via Maya.', 'wc-maya-gateway'),
            ],
            'public_key' => [
                'title'       => __('Public key', 'wc-maya-gateway'),
                'type'        => 'password',
                'placeholder' => 'pk-...',
                'default'     => '',
            ],
            'secret_key' => [
                'title'       => __('Secret key', 'wc-maya-gateway'),
                'type'        => 'password',
                'placeholder' => 'sk-...',
                'default'     => '',
            ],
            'is_sandbox' => [
                'title'   => __('Sandbox mode', 'wc-maya-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Use the Maya sandbox environment for testing.', 'wc-maya-gateway'),
                'default' => 'yes',
            ],
        ];
    }

    public function process_payment($order_id): array
    {
        return [ 'result' => 'failure' ];
    }
}
