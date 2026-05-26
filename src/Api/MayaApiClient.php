<?php

/**
 * Maya API client.
 *
 * @package TaniKyuun\MayaGateway\Api
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Api;

/**
 * HTTP client for Maya's REST API.
 *
 * Placeholder scaffold — endpoint methods (create_checkout, get_payment,
 * void_payment, refund_payment) are added incrementally. See Phase 3.2 of
 * docs/PLAN.md for the full intended interface and the per-endpoint
 * public/secret key authentication rules.
 */
class MayaApiClient
{
    public const URL_PRODUCTION = 'https://pg.maya.ph';
    public const URL_SANDBOX    = 'https://pg-sandbox.paymaya.com';

    public function __construct(
        private readonly string $public_key,
        private readonly string $secret_key,
        private readonly bool $is_sandbox = false,
    ) {}

    public function get_base_url(): string
    {
        return $this->is_sandbox ? self::URL_SANDBOX : self::URL_PRODUCTION;
    }
}
