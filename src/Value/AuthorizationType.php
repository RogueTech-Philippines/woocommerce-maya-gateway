<?php

/**
 * Manual-capture authorization type enum.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

/**
 * Authorization modes the merchant can select on the gateway settings page.
 *
 * `Maya` accepts the uppercase form (`NORMAL`, `FINAL`, `PREAUTHORIZATION`)
 * in the createCheckout payload. We store the lowercase form as a setting
 * value so it survives serialize/unserialize cleanly, and convert via
 * `for_maya_api()` when calling the API.
 *
 * The enum case names use PascalCase to avoid colliding with PHP's reserved
 * `final` keyword.
 */
enum AuthorizationType: string
{
    case None             = 'none';
    case Normal           = 'normal';
    case FinalAuth        = 'final';
    case Preauthorization = 'preauthorization';

    /**
     * Best-effort parse for stored option values. Falls back to None so we
     * never crash on a typo in wp_options.
     */
    public static function from_setting(mixed $value): self
    {
        if (! is_string($value)) {
            return self::None;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::None;
    }

    public function for_maya_api(): string
    {
        return strtoupper($this->value);
    }

    public function is_manual_capture(): bool
    {
        return self::None !== $this;
    }
}
