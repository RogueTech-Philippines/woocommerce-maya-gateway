<?php

/**
 * Unit tests for the WebhookHandler::process() pipeline.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Webhook;

use Mockery;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Webhook\SignatureVerifier;
use TaniKyuun\MayaGateway\Webhook\WebhookHandler;

function wc_maya_fresh_timestamp(): string
{
    return (string) (int) floor(microtime(true) * 1000);
}

function wc_maya_verifier_accepting(): SignatureVerifier
{
    $verifier = Mockery::mock(SignatureVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(true);
    return $verifier;
}

function wc_maya_verifier_rejecting(): SignatureVerifier
{
    $verifier = Mockery::mock(SignatureVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(false);
    return $verifier;
}

test('rejects a non-JSON body with 400', function (): void {
    $result = WebhookHandler::process(
        'not json',
        [],
        '13.229.160.234',
        true,
        new Logger(false),
        wc_maya_verifier_accepting(),
    );

    expect($result['status'])->toBe(400);
    expect($result['body']['error']['code'])->toBe('invalid_body');
});

test('rejects a stale timestamp with 401', function (): void {
    $body = (string) json_encode([ 'status' => 'PAYMENT_SUCCESS', 'requestReferenceNumber' => '1' ]);

    $result = WebhookHandler::process(
        $body,
        [
            WebhookHandler::HEADER_TIMESTAMP => '1000', // far in the past
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=00',
        ],
        '13.229.160.234',
        true,
        new Logger(false),
        wc_maya_verifier_accepting(),
    );

    expect($result['status'])->toBe(401);
    expect($result['body']['error']['code'])->toBe('stale_timestamp');
});

test('rejects an invalid signature with 401', function (): void {
    $body = (string) json_encode([ 'status' => 'PAYMENT_SUCCESS', 'requestReferenceNumber' => '1' ]);

    $result = WebhookHandler::process(
        $body,
        [
            WebhookHandler::HEADER_TIMESTAMP => wc_maya_fresh_timestamp(),
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=deadbeef',
        ],
        '13.229.160.234',
        true,
        new Logger(false),
        wc_maya_verifier_rejecting(),
    );

    expect($result['status'])->toBe(401);
    expect($result['body']['error']['code'])->toBe('invalid_signature');
});

test('rejects an IP outside the allowlist with 403', function (): void {
    $body = (string) json_encode([ 'status' => 'PAYMENT_SUCCESS', 'requestReferenceNumber' => '7' ]);

    $result = WebhookHandler::process(
        $body,
        [
            WebhookHandler::HEADER_TIMESTAMP => wc_maya_fresh_timestamp(),
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=ab',
        ],
        '8.8.8.8',
        true,
        new Logger(false),
        wc_maya_verifier_accepting(),
    );

    expect($result['status'])->toBe(403);
    expect($result['body']['error']['code'])->toBe('source_ip_blocked');
});

test('returns 200 with parsed event when all checks pass', function (): void {
    $body = (string) json_encode([
        'status'                 => 'PAYMENT_SUCCESS',
        'requestReferenceNumber' => '42',
    ]);

    $result = WebhookHandler::process(
        $body,
        [
            WebhookHandler::HEADER_TIMESTAMP => wc_maya_fresh_timestamp(),
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=ab',
        ],
        '13.229.160.234',
        true,
        new Logger(false),
        wc_maya_verifier_accepting(),
    );

    expect($result['status'])->toBe(200);
    expect($result['body'])->toMatchArray([
        'received'  => true,
        'simulated' => false,
        'event'     => 'PAYMENT_SUCCESS',
        'reference' => '42',
    ]);
});

test('honors the X-Simulated-Webhook bypass in sandbox without checking timestamp, signature, or IP', function (): void {
    $body = (string) json_encode([
        'status'                 => 'PAYMENT_FAILED',
        'requestReferenceNumber' => '99',
    ]);

    $result = WebhookHandler::process(
        $body,
        [ WebhookHandler::HEADER_SIMULATED => 'true' ], // no timestamp, no signature
        '127.0.0.1',                                    // not in allowlist
        true,                                           // sandbox
        new Logger(false),
        wc_maya_verifier_rejecting(),                   // would reject if asked
    );

    expect($result['status'])->toBe(200);
    expect($result['body']['simulated'])->toBeTrue();
    expect($result['body']['event'])->toBe('PAYMENT_FAILED');
});

test('refuses the simulator bypass in production mode', function (): void {
    $body = (string) json_encode([ 'status' => 'PAYMENT_SUCCESS', 'requestReferenceNumber' => '1' ]);

    $result = WebhookHandler::process(
        $body,
        [
            WebhookHandler::HEADER_SIMULATED => 'true',
            WebhookHandler::HEADER_TIMESTAMP => wc_maya_fresh_timestamp(),
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=ab',
        ],
        '13.229.160.234',
        false,                          // production
        new Logger(false),
        wc_maya_verifier_rejecting(),
    );

    expect($result['status'])->toBe(401); // falls through to signature check, which rejects
    expect($result['body']['error']['code'])->toBe('invalid_signature');
});

test('exposes REST route namespace and path as public constants', function (): void {
    expect(WebhookHandler::ROUTE_NAMESPACE)->toBe('wc-maya/v1');
    expect(WebhookHandler::ROUTE_PATH)->toBe('/webhook');
});
