<?php

/**
 * Unit tests for the Maya webhook handler.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit;

use TaniKyuun\MayaGateway\Webhook\WebhookHandler;

test(
    'sandbox allowlist contains Maya\'s documented sandbox IPs',
    function (): void {
        expect(WebhookHandler::SANDBOX_IPS)->toContain('13.229.160.234');
        expect(WebhookHandler::SANDBOX_IPS)->toContain('3.1.199.75');
    },
);

test(
    'production allowlist contains Maya\'s documented production IPs',
    function (): void {
        expect(WebhookHandler::PRODUCTION_IPS)->toContain('18.138.50.235');
        expect(WebhookHandler::PRODUCTION_IPS)->toContain('3.1.207.200');
    },
);
