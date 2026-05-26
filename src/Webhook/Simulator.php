<?php

/**
 * Local-dev webhook simulator.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

use TaniKyuun\MayaGateway\Settings\SettingsHelper;
use TaniKyuun\MayaGateway\Value\WebhookEvent;
use WC_Order;
use WP_Error;

/**
 * Builds a forged Maya webhook payload and POSTs it at our own endpoint with
 * the `X-Simulated-Webhook: true` bypass header so a developer can exercise
 * the full webhook pipeline without a tunnel and without signing.
 *
 * Sandbox-only by contract: `WebhookHandler::is_simulated()` rejects the
 * bypass when the gateway is in production mode, so the worst case here is a
 * misconfigured sandbox install accepting the simulator — never production.
 *
 * Returns a structured result (status code + decoded body) so the AJAX
 * caller can surface either the handler's success message or its rejection
 * reason verbatim. Easier to debug "what did the handler think?" than a
 * generic "OK".
 */
class Simulator
{
    /**
     * Statuses the simulator UI is allowed to fire — matches the rebuild
     * plan's "success/failed/expired" surface area.
     */
    public const ALLOWED_STATUSES = [
        WebhookEvent::PaymentSuccess->value,
        WebhookEvent::PaymentFailed->value,
        WebhookEvent::PaymentExpired->value,
    ];

    public function __construct(private readonly SettingsHelper $settings) {}

    /**
     * @return array{status: int, body: array<string,mixed>}|WP_Error
     */
    public function simulate(WC_Order $order, string $status): array|WP_Error
    {
        if (! $this->settings->is_sandbox()) {
            return new WP_Error(
                'wc_maya_simulator_not_sandbox',
                __('Simulator can only run in sandbox mode.', 'wc-maya-gateway'),
            );
        }

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            return new WP_Error(
                'wc_maya_simulator_invalid_status',
                sprintf(
                    /* translators: %s: simulator status the user submitted. */
                    __('Unsupported simulator status "%s".', 'wc-maya-gateway'),
                    $status,
                ),
            );
        }

        $payload  = self::build_payload($order, $status);
        $response = wp_remote_post(
            $this->settings->webhook_url(),
            [
                'timeout'   => 15,
                'sslverify' => false, // local-dev tunnels often present self-signed certs; sandbox check above gates this.
                'headers'   => [
                    'Content-Type'                   => 'application/json',
                    WebhookHandler::HEADER_SIMULATED => 'true',
                ],
                'body' => wp_json_encode($payload),
            ],
        );

        if ($response instanceof WP_Error) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body    = (string) wp_remote_retrieve_body($response);
        $body        = '' === $raw_body ? [] : json_decode($raw_body, true);
        if (! is_array($body)) {
            $body = [ 'raw' => $raw_body ];
        }

        return [
            'status' => $status_code,
            'body'   => $body,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function build_payload(WC_Order $order, string $status): array
    {
        return [
            'id'                     => 'simulated_' . bin2hex(random_bytes(8)),
            'isPaid'                 => WebhookEvent::PaymentSuccess->value === $status,
            'status'                 => $status,
            'amount'                 => (float) $order->get_total(),
            'currency'               => $order->get_currency(),
            'canVoid'                => false,
            'canRefund'              => WebhookEvent::PaymentSuccess->value === $status,
            'canCapture'             => false,
            'createdAt'              => gmdate('c'),
            'updatedAt'              => gmdate('c'),
            'requestReferenceNumber' => (string) $order->get_id(),
            'metadata'               => [
                'simulated'   => true,
                'environment' => 'local_development',
            ],
        ];
    }
}
