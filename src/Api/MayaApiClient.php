<?php

/**
 * Maya API client.
 *
 * @package RogueTechPhilippines\MayaGateway\Api
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Api;

use RogueTechPhilippines\MayaGateway\Util\Logger;
use WP_Error;

/**
 * HTTP transport for Maya's REST API.
 *
 * Owns only the transport layer: Basic-auth header build, JSON request +
 * response, structured logging, error mapping. Per-endpoint shaping (request
 * payload composition, response DTO parsing) lives in
 * `Api\Endpoints\*` so this class stays small and reusable across
 * endpoints.
 *
 * Authentication: Basic auth with the API key as the username and an empty
 * password ({key}:). The public key authenticates the Checkout endpoints
 * (POST /checkout/v1/checkouts); the Checkout *secret* key authenticates
 * `/checkout/v1/webhooks`. The Payment Vault product has separate keys at
 * `/payments/v1/*` and is not used here.
 *
 * @see https://developers.maya.ph/reference/api-authentication-methods
 */
class MayaApiClient
{
    public const URL_PRODUCTION = 'https://pg.maya.ph';
    public const URL_SANDBOX    = 'https://pg-sandbox.paymaya.com';

    public const KEY_PUBLIC = 'public';
    public const KEY_SECRET = 'secret';

    private readonly Logger $logger;

    public function __construct(
        private readonly string $public_key,
        private readonly string $secret_key,
        private readonly bool $is_sandbox = false,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? new Logger();
    }

    public function get_base_url(): string
    {
        return $this->is_sandbox ? self::URL_SANDBOX : self::URL_PRODUCTION;
    }

    public function is_sandbox(): bool
    {
        return $this->is_sandbox;
    }

    /**
     * Issue a JSON request against the Maya REST API.
     *
     * @param 'GET'|'POST'|'PUT'|'DELETE' $method  HTTP method.
     * @param string                      $path    Path beginning with `/`.
     * @param array<string,mixed>|null    $body    JSON body, omitted when null.
     * @param self::KEY_*                 $key     Which API key to authenticate with.
     *
     * @return array<string,mixed>|array<int,mixed>|WP_Error
     */
    public function request(string $method, string $path, ?array $body, string $key): array|WP_Error
    {
        $api_key = self::KEY_SECRET === $key ? $this->secret_key : $this->public_key;

        if ('' === $api_key) {
            return new WP_Error(
                'wc_maya_missing_key',
                sprintf(
                    /* translators: %s: which API key (public/secret) was missing. */
                    __('Missing %s API key.', 'wc-maya-gateway'),
                    $key,
                ),
            );
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Accept'        => 'application/json',
            ],
        ];

        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode($body);
        }

        $url = $this->get_base_url() . $path;

        $this->logger->debug(
            sprintf('-> %s %s', $method, $path),
            [
                'auth_key' => $key,
                'body'     => $body,
            ],
        );

        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            $this->logger->error(
                sprintf('<- %s %s transport error', $method, $path),
                [
                    'code'    => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                ],
            );
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = '' === $raw ? [] : json_decode($raw, true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 200 && $status < 300) {
            $this->logger->info(
                sprintf('<- %s %s %d OK', $method, $path, $status),
                [ 'body' => $decoded ],
            );
            return $decoded;
        }

        $message = isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : sprintf(
                /* translators: %d: HTTP status code returned by Maya. */
                __('Maya API returned HTTP %d.', 'wc-maya-gateway'),
                $status,
            );

        $message .= self::format_parameter_details($decoded);

        $this->logger->warning(
            sprintf('<- %s %s %d %s', $method, $path, $status, $message),
            [ 'body' => $decoded ],
        );

        return new WP_Error(
            'wc_maya_http_' . $status,
            $message,
            [
                'status' => $status,
                'body'   => $decoded,
            ],
        );
    }

    /**
     * Surface Maya's per-field validation details so messages like
     * "Missing/invalid parameters." gain the actual offending field.
     *
     * Maya error bodies use the shape:
     *
     *     {
     *       "code": "2553",
     *       "message": "Missing/invalid parameters.",
     *       "parameters": [
     *         { "field": "requestReferenceNumber", "description": "length must be at most 36" }
     *       ]
     *     }
     *
     * @param array<string,mixed> $decoded
     */
    public static function format_parameter_details(array $decoded): string
    {
        if (! isset($decoded['parameters']) || ! is_array($decoded['parameters'])) {
            return '';
        }

        $details = [];
        foreach ($decoded['parameters'] as $param) {
            if (! is_array($param)) {
                continue;
            }
            $field       = isset($param['field'])       && is_string($param['field']) ? $param['field'] : '';
            $description = isset($param['description']) && is_string($param['description']) ? $param['description'] : '';
            if ('' !== $field && '' !== $description) {
                $details[] = $field . ': ' . $description;
            }
        }

        return [] === $details ? '' : ' (' . implode('; ', $details) . ')';
    }
}
