<?php

/**
 * Maya Checkout webhook-management endpoint wrapper.
 *
 * @package TaniKyuun\MayaGateway\Api\Endpoints
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Api\Endpoints;

use TaniKyuun\MayaGateway\Api\MayaApiClient;
use WP_Error;

/**
 * Typed wrapper around `/checkout/v1/webhooks`.
 *
 * Authenticated with the Maya Checkout *secret* key. Returns raw decoded
 * arrays for now — Phase 3 will introduce a `Webhook` value object once we
 * start writing (`create`, `delete`) as well as reading.
 *
 * Note: `/payments/v1/webhooks` is a different endpoint belonging to the
 * Payment Vault product and is not used here.
 */
class Webhooks
{
    public function __construct(private readonly MayaApiClient $client) {}

    /**
     * List all webhooks registered against the configured Maya account.
     *
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public function all(): array|WP_Error
    {
        return $this->client->request('GET', '/checkout/v1/webhooks', null, MayaApiClient::KEY_SECRET);
    }
}
