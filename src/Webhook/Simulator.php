<?php

/**
 * Local-dev webhook simulator.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

use RogueTechPhilippines\MayaGateway\Settings\SettingsHelper;
use RogueTechPhilippines\MayaGateway\Value\WebhookEvent;
use WC_Order;
use WP_Error;

/**
 * Builds a forged Maya webhook payload and dispatches it through the webhook
 * pipeline in-process, as a trusted sandbox-only simulation.
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

        $body = (string) wp_json_encode(self::build_payload($order, $status));

        return WebhookHandler::process(
            $body,
            [],
            '127.0.0.1',
            true,
            new \RogueTechPhilippines\MayaGateway\Util\Logger(true),
            null,
            null,
            true,
        );
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
