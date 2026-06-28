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
use TaniKyuun\MayaGateway\Webhook\EventDispatcher;
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

function wc_maya_dispatcher_recording(): EventDispatcher
{
    $dispatcher = Mockery::mock(EventDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->andReturn([ 'action' => 'noop_for_test' ]);
    return $dispatcher;
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

test('returns 200 with parsed event and dispatch result when all checks pass', function (): void {
    $body = (string) json_encode([
        'status'                 => 'PAYMENT_SUCCESS',
        'requestReferenceNumber' => '42',
        'amount'                 => 199.5,
        'id'                     => 'pay_abc',
    ]);

    $dispatcher = Mockery::mock(EventDispatcher::class);
    $dispatcher->expects('dispatch')
        ->withArgs(static function ($event, $payload): bool {
            return 'PAYMENT_SUCCESS' === $event->value
                && '42'              === ($payload['requestReferenceNumber'] ?? null);
        })
        ->andReturn([ 'action' => 'payment_complete', 'order_id' => 42 ]);

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
        $dispatcher,
    );

    expect($result['status'])->toBe(200);
    expect($result['body'])->toMatchArray([
        'received'  => true,
        'simulated' => false,
        'event'     => 'PAYMENT_SUCCESS',
        'reference' => '42',
    ]);
    expect($result['body']['dispatch'])->toBe([ 'action' => 'payment_complete', 'order_id' => 42 ]);
});

test('rejects the former simulator header on the public handler', function (): void {
    $body = (string) json_encode([
        'status'                 => 'PAYMENT_FAILED',
        'requestReferenceNumber' => '99',
    ]);

    $result = WebhookHandler::process(
        $body,
        [ 'x-simulated-webhook' => 'true' ],
        '127.0.0.1',
        true,
        new Logger(false),
        wc_maya_verifier_rejecting(),
        wc_maya_dispatcher_recording(),
    );

    expect($result['status'])->toBe(401);
    expect($result['body']['error']['code'])->toBe('stale_timestamp');
});

test('does not call the dispatcher when the event is unknown to the enum', function (): void {
    $body = (string) json_encode([
        'status'                 => 'TOTALLY_UNKNOWN_EVENT',
        'requestReferenceNumber' => '7',
    ]);

    $dispatcher = Mockery::mock(EventDispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');

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
        $dispatcher,
    );

    expect($result['status'])->toBe(200);
    expect($result['body']['event'])->toBeNull();
    expect($result['body']['dispatch'])->toBeNull();
});

test('former simulator header still requires a valid signature', function (): void {
    $body = (string) json_encode([ 'status' => 'PAYMENT_SUCCESS', 'requestReferenceNumber' => '1' ]);

    $result = WebhookHandler::process(
        $body,
        [
            'x-simulated-webhook'             => 'true',
            WebhookHandler::HEADER_TIMESTAMP => wc_maya_fresh_timestamp(),
            WebhookHandler::HEADER_SIGNATURE => 'nonce=n,v1=ab',
        ],
        '13.229.160.234',
        false,
        new Logger(false),
        wc_maya_verifier_rejecting(),
    );

    expect($result['status'])->toBe(401);
    expect($result['body']['error']['code'])->toBe('invalid_signature');
});

test('exposes REST route namespace and path as public constants', function (): void {
    expect(WebhookHandler::ROUTE_NAMESPACE)->toBe('wc-maya/v1');
    expect(WebhookHandler::ROUTE_PATH)->toBe('/webhook');
});
