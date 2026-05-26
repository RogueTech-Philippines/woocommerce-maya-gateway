<?php

/**
 * Settings accessor.
 *
 * @package TaniKyuun\MayaGateway\Settings
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Settings;

use WC_Payment_Gateway;

/**
 * Centralized accessors for plugin settings.
 *
 * Lives outside Admin/ because runtime callers (the webhook handler, the
 * gateway, the API-client factory) read these values too — not just the
 * settings screen.
 */
class SettingsHelper
{
    public const WEBHOOK_ROUTE = 'maya_webhook';

    public function __construct(private readonly WC_Payment_Gateway $gateway) {}

    public function public_key(): string
    {
        return trim((string) $this->gateway->get_option('public_key'));
    }

    public function secret_key(): string
    {
        return trim((string) $this->gateway->get_option('secret_key'));
    }

    public function is_sandbox(): bool
    {
        return 'yes' === $this->gateway->get_option('is_sandbox', 'yes');
    }

    public function debug_log_enabled(): bool
    {
        return 'yes' === $this->gateway->get_option('debug_log', 'no');
    }

    /**
     * User-supplied tunnel base URL (e.g. https://stork.tanikyuun.pw). Empty
     * when not configured.
     */
    public function local_dev_webhook_base_url(): string
    {
        return trim((string) $this->gateway->get_option('local_dev_webhook_url'));
    }

    /**
     * Webhook URL that should be registered with Maya.
     *
     * If a local-dev override is set we use it as the host so tunneled
     * (ngrok / cloudflared) environments can receive callbacks; otherwise we
     * fall back to home_url() which is correct in production.
     */
    public function webhook_url(): string
    {
        $override = $this->local_dev_webhook_base_url();
        $path     = '?wc-api=' . self::WEBHOOK_ROUTE;

        if ('' === $override) {
            return home_url('/' . $path);
        }

        // If the user pasted the full webhook URL, return it as-is.
        if (str_contains($override, 'wc-api')) {
            return $override;
        }

        return rtrim($override, '/') . '/' . $path;
    }
}
