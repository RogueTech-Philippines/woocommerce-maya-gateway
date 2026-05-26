<?php

/**
 * HTML renderers + sanitizers for custom gateway-settings field types.
 *
 * @package TaniKyuun\MayaGateway\Admin
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin;

use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use WC_Payment_Gateway;

/**
 * Implementations for the custom `type` values declared in FormFields.
 *
 * Static methods to keep the renderers stateless — MayaGateway delegates its
 * `generate_<type>_html()` methods here, so the gateway file stays a thin
 * WC_Settings_API surface.
 */
class FieldRenderers
{
    /**
     * Render the "Test connection" row: button, spinner, result placeholder.
     *
     * @param array<string,mixed> $data Field config from FormFields.
     */
    public static function test_connection(WC_Payment_Gateway $gateway, string $key, array $data): string
    {
        unset($gateway, $key); // signature matches WC's generate_* convention
        $defaults = [ 'title' => '', 'description' => '' ];
        $data     = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <button
                    type="button"
                    class="button button-secondary"
                    id="wc-maya-test-connection"
                    data-action="wc_maya_test_connection"
                >
                    <?php esc_html_e('Test connection', 'wc-maya-gateway'); ?>
                </button>
                <span class="spinner" id="wc-maya-test-connection-spinner"></span>
                <div class="description" id="wc-maya-test-connection-result" aria-live="polite"></div>
                <p class="description">
                    <?php esc_html_e('Public key: creates a tiny sandbox checkout session (POST /checkout/v1/checkouts). The session is never visited and expires on its own — no card is charged. Secret key: lists registered Checkout webhooks (GET /checkout/v1/webhooks). Both calls are logged to WooCommerce → Status → Logs (source: wc-maya-gateway).', 'wc-maya-gateway'); ?>
                </p>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the computed webhook URL as a read-only input + copy button.
     *
     * @param array<string,mixed> $data Field config from FormFields.
     */
    public static function webhook_url_display(WC_Payment_Gateway $gateway, string $key, array $data): string
    {
        unset($key);
        $defaults = [ 'title' => '' ];
        $data     = wp_parse_args($data, $defaults);

        $helper      = new SettingsHelper($gateway);
        $webhook_url = $helper->webhook_url();
        $using_local = '' !== $helper->local_dev_webhook_base_url();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <div class="wc-maya-webhook-url">
                    <input
                        type="text"
                        readonly
                        class="regular-text code"
                        id="wc-maya-webhook-url"
                        value="<?php echo esc_attr($webhook_url); ?>"
                    />
                    <button
                        type="button"
                        class="button button-secondary"
                        id="wc-maya-copy-webhook-url"
                        data-target="#wc-maya-webhook-url"
                    >
                        <?php esc_html_e('Copy', 'wc-maya-gateway'); ?>
                    </button>
                </div>
                <p class="description">
                    <?php if ($using_local) : ?>
                        <strong><?php esc_html_e('Using local-dev override.', 'wc-maya-gateway'); ?></strong>
                        <?php esc_html_e('Save the form first if you just changed it — this URL is rendered from saved settings.', 'wc-maya-gateway'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Falling back to home_url(). Set the local-dev webhook URL above to point Maya at a tunnel instead.', 'wc-maya-gateway'); ?>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Sanitize the local-dev URL: trim, drop trailing slash, require http(s).
     */
    public static function validate_local_dev_webhook_url(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ('' === $value) {
            return '';
        }

        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return untrailingslashit(esc_url_raw($value));
    }
}
