<?php

/**
 * Webhook receiver for Maya payment notifications.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

use RogueTechPhilippines\MayaGateway\Api\Endpoints\Payments;
use RogueTechPhilippines\MayaGateway\Api\MayaApiClient;
use RogueTechPhilippines\MayaGateway\Gateway\MayaGateway;
use RogueTechPhilippines\MayaGateway\Settings\SettingsHelper;
use RogueTechPhilippines\MayaGateway\Util\Logger;
use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;
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
     * Verify a webhook request and dispatch it to the order state machine.
     *
     * Returns `['status' => int, 'body' => array]`. The body now includes
     * `dispatch` — the structured result the {@see EventDispatcher} returned
     * for the event — so simulator and integration tests can see exactly
     * what state change (if any) happened.
     *
     * @param array<string,string> $headers Lowercased header lookup.
     * @param ?SignatureVerifier   $signature_verifier_override Injection seam for tests.
     * @param ?EventDispatcher     $event_dispatcher_override   Injection seam for tests.
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
        ?EventDispatcher $event_dispatcher_override = null,
        bool $trusted_simulation = false,
    ): array {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $logger->warning('Webhook rejected: body is not valid JSON.', [ 'source_ip' => $source_ip ]);
            return self::reject(400, 'invalid_body', 'Request body must be JSON.');
        }

        if (! $trusted_simulation) {
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
                'Webhook verified%s — dispatching %s for order %s.',
                $trusted_simulation ? ' (simulated)' : '',
                null !== $event ? $event->value : 'UNKNOWN(' . (string) $event_name . ')',
                '' === $reference ? '?' : $reference,
            ),
            [
                'simulated' => $trusted_simulation,
                'source_ip' => $source_ip,
                'payload'   => $payload,
            ],
        );

        $dispatch = null;
        if (null !== $event) {
            $dispatcher = $event_dispatcher_override
                ?? new EventDispatcher($logger, self::build_payments_endpoint($is_sandbox, $logger));
            $dispatch = $dispatcher->dispatch($event, $payload);

            // Transient-failure retry safety net (Phase 8). The dispatch's
            // action drives the decision — see {@see RetryQueue::should_schedule}.
            // Simulator payloads never enter the retry queue (the order ids
            // are synthesized) so local debugging doesn't pollute the AS table.
            if (! $trusted_simulation) {
                RetryQueue::maybe_schedule($dispatch ?? [], $payload, 1, $logger);
            }
        }

        return [
            'status' => 200,
            'body'   => [
                'received'  => true,
                'simulated' => $trusted_simulation,
                'event'     => null !== $event ? $event->value : null,
                'reference' => '' === $reference ? null : $reference,
                'dispatch'  => $dispatch,
            ],
        ];
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

    /**
     * Build a {@see Payments} endpoint from the saved Maya keys so the
     * {@see EventDispatcher}'s manual-capture branch can look up the
     * authoritative authorization state. Independent of the WC payment-
     * gateway instance so it works even if WC's gateway dispatch hasn't
     * fully booted by the time a webhook lands.
     */
    private static function build_payments_endpoint(bool $is_sandbox, Logger $logger): Payments
    {
        $option = get_option('woocommerce_' . MayaGateway::ID . '_settings', []);
        if (! is_array($option)) {
            $option = [];
        }

        return new Payments(new MayaApiClient(
            (string) ($option['public_key'] ?? ''),
            (string) ($option['secret_key'] ?? ''),
            $is_sandbox,
            $logger,
        ));
    }
}
