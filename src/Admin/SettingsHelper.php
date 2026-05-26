<?php

/**
 * Settings accessor.
 *
 * @package TaniKyuun\MayaGateway\Admin
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin;

use WC_Payment_Gateway;

/**
 * Centralized accessors for plugin settings.
 *
 * Placeholder scaffold — typed getters are added incrementally as callers
 * appear. Constructor keeps the same signature so callers can be wired up
 * before the helpers are restored.
 */
class SettingsHelper
{
    public function __construct(private readonly WC_Payment_Gateway $gateway) {}
}
