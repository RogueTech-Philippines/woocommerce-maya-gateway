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
use TaniKyuun\MayaGateway\Api\Endpoints\Checkouts;
use TaniKyuun\MayaGateway\Api\Endpoints\Webhooks;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Webhook\Registrar;
use WC_Admin_Settings;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

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

    public const META_CHECKOUT_ID        = '_maya_checkout_id';
    public const META_PAYMENT_ID         = '_maya_payment_id';
    public const META_IDEMPOTENCY_KEY    = '_maya_idempotency_key';
    public const META_AUTHORIZATION_TYPE = '_maya_authorization_type';

    public function __construct()
    {
        $this->id                 = self::ID;
        $this->method_title       = __('Maya Checkout', 'wc-maya-gateway');
        $this->method_description = __('Accept payments via Maya (cards, e-wallets, QR Ph).', 'wc-maya-gateway');
        $this->has_fields         = false;
        // 'refunds' is added back in Phase 6 alongside RefundProcessor — never
        // advertise a capability the gateway can't actually service.
        $this->supports = [ 'products' ];

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

    /**
     * @param array<string,mixed> $data
     */
    public function generate_webhook_status_table_html(string $key, array $data): string
    {
        return FieldRenderers::webhook_status_table($this, $key, $data);
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
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (! $order instanceof WC_Order) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    sprintf(
                        /* translators: %d: order id we couldn't load. */
                        __('Could not load order #%d.', 'wc-maya-gateway'),
                        (int) $order_id,
                    ),
                    'error',
                );
            }
            return [ 'result' => 'failure' ];
        }

        $helper = new SettingsHelper($this);

        return (new PaymentProcessor(
            new Checkouts($this->build_api_client()),
            $helper,
            new Logger($helper->debug_log_enabled()),
        ))->process($order);
    }

    /**
     * Save settings + reconcile webhook registrations with Maya.
     *
     * Runs the parent save first, then — only on success and only when the
     * gateway is enabled with both keys present — asks {@see Registrar} to
     * delete the managed webhook set and recreate it pointing at the
     * computed webhook URL. Failures are surfaced via WC's admin notice API
     * so the merchant sees what happened instead of having to dig in logs.
     */
    public function process_admin_options(): bool
    {
        $saved = parent::process_admin_options();
        if (! $saved) {
            return $saved;
        }

        // Re-read settings the parent just wrote.
        $this->init_settings();
        $helper = new SettingsHelper($this);

        if ('yes' !== $this->get_option('enabled')) {
            return $saved;
        }

        if ('' === $helper->public_key() || '' === $helper->secret_key()) {
            WC_Admin_Settings::add_message(
                __('Saved. Add both Maya API keys to register webhooks automatically.', 'wc-maya-gateway'),
            );
            return $saved;
        }

        $registrar = new Registrar(
            new Webhooks($this->build_api_client()),
            new Logger($helper->debug_log_enabled()),
        );

        $result = $registrar->reconcile($helper->webhook_url());

        if ($result instanceof WP_Error) {
            WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: Maya API error message. */
                __('Webhook registration failed: %s', 'wc-maya-gateway'),
                $result->get_error_message(),
            ));
            return $saved;
        }

        $created_count = count($result['created']);

        if ([] !== $result['errors']) {
            WC_Admin_Settings::add_error(sprintf(
                /* translators: 1: number of webhooks created. 2: comma-joined error list. */
                __('Webhook registration partially succeeded — %1$d created; errors: %2$s', 'wc-maya-gateway'),
                $created_count,
                implode('; ', $result['errors']),
            ));
            return $saved;
        }

        WC_Admin_Settings::add_message(sprintf(
            /* translators: %d: number of webhooks registered. */
            _n(
                '%d webhook registered with Maya.',
                '%d webhooks registered with Maya.',
                $created_count,
                'wc-maya-gateway',
            ),
            $created_count,
        ));

        return $saved;
    }
}
