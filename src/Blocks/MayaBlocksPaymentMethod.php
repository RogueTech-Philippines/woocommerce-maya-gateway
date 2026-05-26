<?php

/**
 * WooCommerce Blocks (Cart & Checkout) payment-method integration.
 *
 * @package TaniKyuun\MayaGateway\Blocks
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;

/**
 * Exposes Maya Checkout to the block-based Cart and Checkout.
 *
 * Maya is a hosted-checkout product: the customer is redirected to a Maya
 * page to enter card / wallet details. The block therefore needs no card
 * input UI of its own — only the gateway title, description, supports list,
 * and an optional icon — all packaged into the localized `wc.wcSettings`
 * payload that the frontend bundle reads.
 *
 * The pure-static helpers ({@see build_payment_method_data}, {@see is_enabled})
 * exist so the data shape and activation rule can be unit-tested without
 * booting WooCommerce or the Blocks subsystem.
 */
final class MayaBlocksPaymentMethod extends AbstractPaymentMethodType
{
    /**
     * Same id as the classic gateway — the frontend bundle uses this string
     * to look its data up via `getPaymentMethodData()`.
     *
     * @var string
     */
    protected $name = MayaGateway::ID;

    public const SCRIPT_HANDLE = 'wc-maya-blocks';

    public static function register(): void
    {
        // WC Blocks ships with WooCommerce 8.3+, but guard anyway so the
        // plugin still loads on older WC or when the Blocks package has been
        // explicitly disabled.
        if (! class_exists(PaymentMethodRegistry::class)) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            [ self::class, 'register_payment_method' ],
        );
    }

    public static function register_payment_method(PaymentMethodRegistry $registry): void
    {
        $registry->register(new self());
    }

    /**
     * WC Blocks calls this once before reading any of the other accessors,
     * giving the integration a chance to read settings from the options
     * table (the gateway instance is *not* available here).
     */
    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_' . MayaGateway::ID . '_settings', []);
        if (! is_array($this->settings)) {
            $this->settings = [];
        }
    }

    /**
     * Whether the block should appear in the Cart/Checkout. We piggy-back on
     * the same `enabled` flag the classic gateway uses so flipping the
     * gateway off hides it in both contexts.
     */
    public function is_active(): bool
    {
        return self::is_enabled($this->settings);
    }

    /**
     * @return array<int,string>
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = self::SCRIPT_HANDLE;

        if (! wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                plugins_url('assets/js/maya-blocks.js', WC_MAYA_PLUGIN_FILE),
                [
                    'wc-blocks-registry',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ],
                self::asset_version(),
                true,
            );

            if (function_exists('wp_set_script_translations')) {
                wp_set_script_translations($handle, 'wc-maya-gateway');
            }
        }

        return [ $handle ];
    }

    /**
     * Data localized to the frontend bundle via `wc.wcSettings`. Reads happen
     * client-side via `getPaymentMethodData(MayaGateway::ID)`.
     *
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        return self::build_payment_method_data(
            (string) $this->get_setting('title', __('Maya', 'wc-maya-gateway')),
            (string) $this->get_setting('description', ''),
            self::resolve_icon_url(),
            self::resolve_supports(),
        );
    }

    /**
     * Pure-static shape builder so tests don't need to instantiate the class
     * or boot WooCommerce. The returned array becomes the `settings` object
     * the block reads on the client.
     *
     * @param array<int,string> $supports
     * @return array<string,mixed>
     */
    public static function build_payment_method_data(
        string $title,
        string $description,
        string $icon,
        array $supports,
    ): array {
        return [
            'title'       => $title,
            'description' => $description,
            'icon'        => $icon,
            'supports'    => array_values(array_filter($supports, 'is_string')),
        ];
    }

    /**
     * The block is active iff the underlying gateway is enabled. Kept as a
     * pure-static check so the rule is unit-testable without an
     * AbstractPaymentMethodType instance.
     *
     * @param array<string,mixed> $settings
     */
    public static function is_enabled(array $settings): bool
    {
        return isset($settings['enabled']) && 'yes' === $settings['enabled'];
    }

    /**
     * Resolve the registered gateway's `supports` list. Falls back to
     * `['products']` when WC hasn't booted yet (e.g. during very early
     * filter resolution) — matches WC's own default.
     *
     * @return array<int,string>
     */
    private static function resolve_supports(): array
    {
        if (! function_exists('WC')) {
            return [ 'products' ];
        }

        $wc = WC();
        if (! is_object($wc) || ! method_exists($wc, 'payment_gateways')) {
            return [ 'products' ];
        }

        $gateways = $wc->payment_gateways();
        if (! is_object($gateways) || ! method_exists($gateways, 'payment_gateways')) {
            return [ 'products' ];
        }

        $registered = $gateways->payment_gateways();
        if (! isset($registered[ MayaGateway::ID ])) {
            return [ 'products' ];
        }

        $gateway = $registered[ MayaGateway::ID ];
        return is_array($gateway->supports ?? null) ? $gateway->supports : [ 'products' ];
    }

    /**
     * Optional icon URL. Themes/integrations can supply one via the
     * `wc_maya_blocks_icon_url` filter; the empty default keeps the block
     * label clean.
     */
    private static function resolve_icon_url(): string
    {
        return (string) apply_filters('wc_maya_blocks_icon_url', '');
    }

    private static function asset_version(): string
    {
        return (defined('WP_DEBUG') && WP_DEBUG) ? (string) time() : '1.0.0';
    }
}
