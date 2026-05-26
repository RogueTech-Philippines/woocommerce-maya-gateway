<?php

/**
 * Plugin Name:       WooCommerce Maya Gateway
 * Plugin URI:        https://github.com/TaniKyuun/woocommerce-maya-gateway
 * Description:       Maya payment gateway for WooCommerce (Philippines).
 * Version:           1.0.0
 * Author:            TaniKyuun
 * License:           GPL-3.0
 * Text Domain:       wc-maya-gateway
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * WC requires at least: 10.6
 * WC tested up to:   10.7
 *
 * @package TaniKyuun\MayaGateway
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('WC_MAYA_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use TaniKyuun\MayaGateway\Plugin;

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', WC_MAYA_PLUGIN_FILE, true);
        }
    },
);

add_action('plugins_loaded', [ Plugin::class, 'init' ]);
