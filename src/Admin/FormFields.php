<?php

/**
 * Gateway settings form definitions.
 *
 * @package TaniKyuun\MayaGateway\Admin
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin;

/**
 * Single source of truth for the WC_Settings_API form on the Maya gateway
 * screen. Returns the array consumed by WC_Payment_Gateway::$form_fields.
 *
 * Custom `type` values (`test_connection`, `webhook_url_display`) are
 * rendered by FieldRenderers and dispatched from MayaGateway via
 * `generate_<type>_html()` methods.
 */
class FormFields
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
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

            'api_section' => [
                'title'       => __('API credentials', 'wc-maya-gateway'),
                'type'        => 'title',
                'description' => __('Get your keys from the Maya Manager → Developers section. Sandbox shared keys are documented at developers.maya.ph.', 'wc-maya-gateway'),
            ],
            'is_sandbox' => [
                'title'       => __('Sandbox mode', 'wc-maya-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Use the Maya sandbox environment for testing.', 'wc-maya-gateway'),
                'description' => __('Sandbox base URL: https://pg-sandbox.paymaya.com — Production: https://pg.maya.ph', 'wc-maya-gateway'),
                'default'     => 'yes',
            ],
            'public_key' => [
                'title'       => __('Public key', 'wc-maya-gateway'),
                'type'        => 'password',
                'placeholder' => 'pk-...',
                'description' => __('Used to create checkout sessions (POST /checkout/v1/checkouts).', 'wc-maya-gateway'),
                'desc_tip'    => true,
                'default'     => '',
                'class'       => 'wc-maya-key-input',
            ],
            'secret_key' => [
                'title'       => __('Secret key', 'wc-maya-gateway'),
                'type'        => 'password',
                'placeholder' => 'sk-...',
                'description' => __('Maya Checkout secret key (pair of the public key above). Used to manage webhooks at /checkout/v1/webhooks. Note: the Payment Vault product has separate keys at /payments/v1/* and is not used here. Never expose this on the frontend.', 'wc-maya-gateway'),
                'desc_tip'    => true,
                'default'     => '',
                'class'       => 'wc-maya-key-input',
            ],
            'test_connection' => [
                'title' => __('Test connection', 'wc-maya-gateway'),
                'type'  => 'test_connection',
            ],
            'debug_log' => [
                'title'       => __('Debug log', 'wc-maya-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Log API requests and responses', 'wc-maya-gateway'),
                'description' => __('Writes to WooCommerce → Status → Logs (source: wc-maya-gateway). Warnings and errors are always logged; enable this to also capture successful requests and full response bodies. Disable in production.', 'wc-maya-gateway'),
                'default'     => 'no',
            ],

            'webhook_section' => [
                'title'       => __('Webhooks', 'wc-maya-gateway'),
                'type'        => 'title',
                'description' => __('Maya posts payment status updates to the URL below. Register it in the Maya Manager → Webhooks section (or via the API).', 'wc-maya-gateway'),
            ],
            'local_dev_webhook_url' => [
                'title'       => __('Local dev webhook URL', 'wc-maya-gateway'),
                'type'        => 'text',
                'placeholder' => 'https://your-subdomain.trycloudflare.com',
                'description' => __('Optional. When running locally behind ngrok / cloudflared, paste the public tunnel URL here. Leave blank to use this site\'s home URL. Paste either the bare host or the full webhook URL — the path is appended automatically.', 'wc-maya-gateway'),
                'desc_tip'    => false,
                'default'     => '',
            ],
            'webhook_url_display' => [
                'title' => __('Webhook URL to register', 'wc-maya-gateway'),
                'type'  => 'webhook_url_display',
            ],
        ];
    }
}
