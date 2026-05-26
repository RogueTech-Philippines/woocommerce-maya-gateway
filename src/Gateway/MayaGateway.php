<?php

/**
 * Maya WC_Payment_Gateway implementation.
 *
 * @package TaniKyuun\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Gateway;

use TaniKyuun\MayaGateway\Admin\FieldRenderers;
use TaniKyuun\MayaGateway\Admin\FormFields;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Util\Logger;
use WC_Payment_Gateway;

/**
 * Hosted-checkout integration with Maya.
 *
 * Stays focused on what only this class can do: own the WC_Payment_Gateway
 * surface (id, supports, form_fields wiring, process_payment). Form
 * definitions live in Admin\FormFields, custom-type rendering in
 * Admin\FieldRenderers, and settings reads in Settings\SettingsHelper.
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
        $this->form_fields = FormFields::definitions();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function generate_test_connection_html(string $key, array $data): string
    {
        return FieldRenderers::test_connection($this, $key, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function generate_webhook_url_display_html(string $key, array $data): string
    {
        return FieldRenderers::webhook_url_display($this, $key, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function generate_webhook_simulator_html(string $key, array $data): string
    {
        return FieldRenderers::webhook_simulator($this, $key, $data);
    }

    public function validate_local_dev_webhook_url_field(string $key, mixed $value): string
    {
        unset($key);
        return FieldRenderers::validate_local_dev_webhook_url($value);
    }

    public function build_api_client(): MayaApiClient
    {
        $helper = new SettingsHelper($this);

        return new MayaApiClient(
            $helper->public_key(),
            $helper->secret_key(),
            $helper->is_sandbox(),
            new Logger($helper->debug_log_enabled()),
        );
    }

    public function process_payment($order_id): array
    {
        return [ 'result' => 'failure' ];
    }
}
