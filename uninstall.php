<?php

/**
 * Uninstall cleanup for WooCommerce Maya Gateway.
 *
 * Runs only when the plugin is deleted (not on deactivate). Removes the
 * settings option and any pending Action Scheduler jobs this plugin created.
 *
 * Order meta written per order (`_maya_*`, including the webhook event log) is
 * intentionally NOT bulk-deleted here: on a large store that would mean
 * iterating every order (and, under HPOS, both the orders table and postmeta),
 * risking a timeout during deletion — and the data is a payment audit record a
 * merchant may still need after removing the plugin. It is removed naturally
 * when the orders themselves are deleted. See docs/go-live-runbook.md.
 *
 * @package RogueTechPhilippines\MayaGateway
 */

declare(strict_types=1);

// If uninstall was not called by WordPress, bail.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/*
 * Gateway settings option. Mirrors WC's option name for the gateway id
 * `maya_checkout` (see MayaGateway::ID) — kept as a literal because the Composer
 * autoloader is not guaranteed to be loaded during uninstall.
 */
delete_option('woocommerce_maya_checkout_settings');

/*
 * Cancel any scheduled webhook-replay jobs (RetryQueue::GROUP =
 * 'wc-maya-gateway', RetryQueue::ACTION_HOOK = 'wc_maya_replay_webhook').
 */
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_maya_replay_webhook', [], 'wc-maya-gateway');
}
