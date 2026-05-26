<?php

/**
 * Webhook receiver for Maya payment notifications.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

/**
 * Receives payment-status callbacks from Maya.
 *
 * Placeholder scaffold — IP allowlist verification + status mapping is added
 * incrementally. See docs/PLAN.md §3.5 for the target design and the per-
 * environment IP allowlists below.
 */
class WebhookHandler
{
    /**
     * Maya's documented outbound IPs for the sandbox environment.
     *
     * @var list<string>
     */
    public const SANDBOX_IPS = [
        '13.229.160.234',
        '3.1.199.75',
    ];

    /**
     * Maya's documented outbound IPs for production.
     *
     * @var list<string>
     */
    public const PRODUCTION_IPS = [
        '18.138.50.235',
        '3.1.207.200',
    ];

    public static function handle(): void
    {
        status_header(503);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Webhook handler not yet implemented.';
        exit;
    }
}
