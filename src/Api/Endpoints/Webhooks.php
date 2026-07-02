<?php

/**
 * Maya Checkout webhook-management endpoint wrapper.
 *
 * @package RogueTechPhilippines\MayaGateway\Api\Endpoints
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Api\Endpoints;

use RogueTechPhilippines\MayaGateway\Api\MayaApiClient;
use RogueTechPhilippines\MayaGateway\Value\WebhookRecord;
use WP_Error;

/**
 * Typed wrapper around `/checkout/v1/webhooks`.
 *
 * Authenticated with the Maya Checkout *secret* key. List/create/delete
 * surface — the {@see \RogueTechPhilippines\MayaGateway\Webhook\Registrar} composes
 * these into the idempotent "reconcile our managed set" operation that
 * runs on settings save.
 *
 * Note: `/payments/v1/webhooks` is a different endpoint belonging to the
 * Payment Vault product and is not used here.
 */
class Webhooks
{
    public function __construct(private readonly MayaApiClient $client) {}

    /**
     * List every webhook registered against the configured Maya account.
     *
     * @return list<WebhookRecord>|WP_Error
     */
    public function all(): array|WP_Error
    {
        $response = $this->client->request('GET', '/checkout/v1/webhooks', null, MayaApiClient::KEY_SECRET);
        if ($response instanceof WP_Error) {
            return $response;
        }

        $records = [];
        foreach ($response as $item) {
            if (is_array($item)) {
                $records[] = WebhookRecord::from_array($item);
            }
        }
        return $records;
    }

    /**
     * Register a webhook for the given event name pointing at $callback_url.
     */
    public function create(string $event_name, string $callback_url): WebhookRecord|WP_Error
    {
        $response = $this->client->request(
            'POST',
            '/checkout/v1/webhooks',
            [
                'name'        => $event_name,
                'callbackUrl' => $callback_url,
            ],
            MayaApiClient::KEY_SECRET,
        );
        return $response instanceof WP_Error ? $response : WebhookRecord::from_array($response);
    }

    /**
     * Delete a single webhook by Maya-assigned id. Returns the parsed record
     * Maya echoes back, or WP_Error on failure.
     */
    public function delete(string $id): WebhookRecord|WP_Error
    {
        $response = $this->client->request(
            'DELETE',
            '/checkout/v1/webhooks/' . rawurlencode($id),
            null,
            MayaApiClient::KEY_SECRET,
        );
        return $response instanceof WP_Error ? $response : WebhookRecord::from_array($response);
    }
}
