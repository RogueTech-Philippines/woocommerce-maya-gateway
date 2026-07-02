<?php

/**
 * Maya Checkout endpoint wrapper.
 *
 * @package RogueTechPhilippines\MayaGateway\Api\Endpoints
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Api\Endpoints;

use RogueTechPhilippines\MayaGateway\Api\MayaApiClient;
use RogueTechPhilippines\MayaGateway\Value\CheckoutSession;
use WP_Error;

/**
 * Typed wrapper around `/checkout/v1/checkouts`.
 *
 * Composes the API client (the transport) and converts decoded responses
 * into `CheckoutSession`. The endpoint requires the *public* API key, per
 * Maya's auth model.
 *
 * @see https://developers.maya.ph/reference/createv1checkout
 */
class Checkouts
{
    public function __construct(private readonly MayaApiClient $client) {}

    /**
     * Create a checkout session.
     *
     * @param array<string,mixed> $payload Maya-shaped checkout payload (totalAmount, requestReferenceNumber, redirectUrl, …).
     */
    public function create(array $payload): CheckoutSession|WP_Error
    {
        $response = $this->client->request('POST', '/checkout/v1/checkouts', $payload, MayaApiClient::KEY_PUBLIC);

        return $response instanceof WP_Error ? $response : CheckoutSession::from_array($response);
    }
}
