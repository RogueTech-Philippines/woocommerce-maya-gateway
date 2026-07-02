<?php

/**
 * Per-request memo for "is this order's Maya payment capturable?".
 *
 * @package RogueTechPhilippines\MayaGateway\Admin\OrderActions
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Admin\OrderActions;

use RogueTechPhilippines\MayaGateway\Api\Endpoints\Payments;
use RogueTechPhilippines\MayaGateway\Gateway\CaptureProcessor;
use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Util\IdempotencyKey;
use RogueTechPhilippines\MayaGateway\Value\PaymentRecord;

/**
 * Shared between {@see CaptureButton} and {@see CapturePanel} so the
 * order-edit screen makes one synchronous Maya call per page load instead
 * of two. The cache is process-scoped (static array) — explicitly cleared
 * via {@see reset_cache()} from test setup; production processes are
 * one-request-per-class-load so leakage isn't a concern there.
 *
 * `null` is a cached value too: "we looked, nothing capturable." Avoids
 * a second lookup when the button decides not to render.
 */
class AuthorizedPaymentLookup
{
    /** @var array<int, ?PaymentRecord> */
    private static array $cache = [];

    public static function for_order_id(int $order_id): ?PaymentRecord
    {
        if (array_key_exists($order_id, self::$cache)) {
            return self::$cache[ $order_id ];
        }

        $gateway = self::find_gateway();
        if (null === $gateway) {
            return self::$cache[ $order_id ] = null;
        }

        $payments = (new Payments($gateway->build_api_client()))
            ->get_by_rrn(IdempotencyKey::for_order($order_id));

        $found = is_array($payments) ? CaptureProcessor::find_capturable_payment($payments) : null;

        return self::$cache[ $order_id ] = $found;
    }

    public static function reset_cache(): void
    {
        self::$cache = [];
    }

    private static function find_gateway(): ?MayaGateway
    {
        if (! function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways[ MayaGateway::ID ] ?? null;

        return $gateway instanceof MayaGateway ? $gateway : null;
    }
}
