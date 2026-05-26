<?php

/**
 * Webhook receiver for Maya payment notifications.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\WebhookEvent;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Receives payment-status callbacks from Maya and verifies them before
 * (eventually) dispatching to order state changes.
 *
 * Phase 2 scope: parse + verify + log. No order updates yet — that comes in
 * Phase 4's EventDispatcher. The handler exposes two entrypoints that share
 * one `process()` pipeline:
 *
 *  - REST: POST /wp-json/wc-maya/v1/webhook (preferred — modern WP routing).
 *  - wc-api shim: ?wc-api=maya_webhook (compatibility with the URL shape WC
 *    has historically used; Maya merchants migrating from the legacy plugin
 *    keep their existing webhook registrations working).
 *
 * The shared `process()` is a pure-ish function: takes the raw body + headers
 * + source IP + environment + logger, returns a `[status, body]` tuple. That
 * lets unit tests exercise the verification rules end-to-end without booting
 * WP_REST_Server or `php://input`.
 */
class WebhookHandler
{
    public const ROUTE_NAMESPACE = 'wc-maya/v1';
    public const ROUTE_PATH      = '/webhook';

    public const HEADER_SIGNATURE = 'x-maya-webhook-signature';
    public const HEADER_TIMESTAMP = 'x-maya-webhook-timestamp';
    public const HEADER_SIMULATED = 'x-simulated-webhook';

    public static function register(): void
    {
        add_action('rest_api_init', [ self::class, 'register_rest_route' ]);
        add_action('woocommerce_api_' . SettingsHelper::WEBHOOK_ROUTE, [ self::class, 'handle_wc_api' ]);
    }

    public static function register_rest_route(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_rest' ],
                'permission_callback' => '__return_true',
            ],
        );
    }

    public static function handle_rest(WP_REST_Request $request): WP_REST_Response
    {
        $settings = self::load_runtime_settings();
        $logger   = new Logger($settings['debug_log']);

        $headers = [
            self::HEADER_SIGNATURE => (string) $request->get_header(self::HEADER_SIGNATURE),
            self::HEADER_TIMESTAMP => (string) $request->get_header(self::HEADER_TIMESTAMP),
            self::HEADER_SIMULATED => (string) $request->get_header(self::HEADER_SIMULATED),
        ];

        $result = self::process(
            (string) $request->get_body(),
            $headers,
            IpAllowlist::get_source_ip($_SERVER), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $settings['is_sandbox'],
            $logger,
        );

        return new WP_REST_Response($result['body'], $result['status']);
    }

    public static function handle_wc_api(): void
    {
        $settings = self::load_runtime_settings();
        $logger   = new Logger($settings['debug_log']);

        $body    = (string) file_get_contents('php://input');
        $headers = [
            self::HEADER_SIGNATURE => isset($_SERVER['HTTP_X_MAYA_WEBHOOK_SIGNATURE']) ? (string) wp_unslash($_SERVER['HTTP_X_MAYA_WEBHOOK_SIGNATURE']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            self::HEADER_TIMESTAMP => isset($_SERVER['HTTP_X_MAYA_WEBHOOK_TIMESTAMP']) ? (string) wp_unslash($_SERVER['HTTP_X_MAYA_WEBHOOK_TIMESTAMP']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            self::HEADER_SIMULATED => isset($_SERVER['HTTP_X_SIMULATED_WEBHOOK']) ? (string) wp_unslash($_SERVER['HTTP_X_SIMULATED_WEBHOOK']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];

        $result = self::process(
            $body,
            $headers,
            IpAllowlist::get_source_ip($_SERVER), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $settings['is_sandbox'],
            $logger,
        );

        status_header($result['status']);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($result['body']);
        exit;
    }

    /**
     * Verify a webhook request and report what *would* be dispatched.
     *
     * Returns `['status' => int, 'body' => array]`. Phase 2 stops at the log
     * line — order updates land in Phase 4 once the EventDispatcher exists.
     *
     * @param array<string,string> $headers Lowercased header lookup.
     * @param ?SignatureVerifier   $signature_verifier_override Injection seam for tests.
     *
     * @return array{status: int, body: array<string,mixed>}
     */
    public static function process(
        string $body,
        array $headers,
        string $source_ip,
        bool $is_sandbox,
        Logger $logger,
        ?SignatureVerifier $signature_verifier_override = null,
    ): array {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $logger->warning('Webhook rejected: body is not valid JSON.', [ 'source_ip' => $source_ip ]);
            return self::reject(400, 'invalid_body', 'Request body must be JSON.');
        }

        $is_simulated = self::is_simulated($headers, $is_sandbox);

        if (! $is_simulated) {
            $timestamp = $headers[ self::HEADER_TIMESTAMP ] ?? '';
            if (! TimestampVerifier::within_tolerance($timestamp)) {
                $logger->warning(
                    'Webhook rejected: timestamp outside tolerance.',
                    [
                        'source_ip' => $source_ip,
                        'timestamp' => $timestamp,
                    ],
                );
                return self::reject(401, 'stale_timestamp', 'Webhook timestamp outside tolerance window.');
            }

            $verifier = $signature_verifier_override
                ?? new SignatureVerifier(PublicKeyBundle::for_environment($is_sandbox));

            $signature = $headers[ self::HEADER_SIGNATURE ] ?? '';
            if (! $verifier->verify($payload, $signature)) {
                $logger->warning('Webhook rejected: signature did not verify.', [ 'source_ip' => $source_ip ]);
                return self::reject(401, 'invalid_signature', 'Webhook signature did not verify.');
            }

            if (! IpAllowlist::allows($source_ip, $is_sandbox)) {
                $logger->warning(
                    'Webhook rejected: source IP not in allowlist.',
                    [
                        'source_ip' => $source_ip,
                    ],
                );
                return self::reject(403, 'source_ip_blocked', 'Source IP is not in Maya\'s allowlist.');
            }
        }

        $event_name = self::extract_event_name($payload);
        $reference  = self::extract_reference($payload);
        $event      = WebhookEvent::try_from_string($event_name);

        $logger->info(
            sprintf(
                'Webhook verified%s — would dispatch %s for order %s.',
                $is_simulated ? ' (simulated)' : '',
                null !== $event ? $event->value : 'UNKNOWN(' . (string) $event_name . ')',
                '' === $reference ? '?' : $reference,
            ),
            [
                'simulated' => $is_simulated,
                'source_ip' => $source_ip,
                'payload'   => $payload,
            ],
        );

        return [
            'status' => 200,
            'body'   => [
                'received'  => true,
                'simulated' => $is_simulated,
                'event'     => null !== $event ? $event->value : null,
                'reference' => '' === $reference ? null : $reference,
            ],
        ];
    }

    /**
     * @param array<string,string> $headers
     */
    private static function is_simulated(array $headers, bool $is_sandbox): bool
    {
        if (! $is_sandbox) {
            return false; // Hard rule: simulator bypass is never allowed in production.
        }

        $flag = strtolower(trim($headers[ self::HEADER_SIMULATED ] ?? ''));

        return 'true' === $flag || '1' === $flag || 'yes' === $flag;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function extract_event_name(array $payload): string
    {
        foreach ([ 'status', 'paymentStatus', 'name' ] as $key) {
            if (isset($payload[ $key ]) && is_string($payload[ $key ])) {
                return $payload[ $key ];
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function extract_reference(array $payload): string
    {
        $value = $payload['requestReferenceNumber'] ?? null;
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * @return array{status: int, body: array{received: false, error: array{code: string, message: string}}}
     */
    private static function reject(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body'   => [
                'received' => false,
                'error'    => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ],
        ];
    }

    /**
     * @return array{is_sandbox: bool, debug_log: bool}
     */
    private static function load_runtime_settings(): array
    {
        $option = get_option('woocommerce_' . MayaGateway::ID . '_settings', []);
        if (! is_array($option)) {
            $option = [];
        }

        return [
            'is_sandbox' => 'yes' === ($option['is_sandbox'] ?? 'yes'),
            'debug_log'  => 'yes' === ($option['debug_log'] ?? 'no'),
        ];
    }
}
