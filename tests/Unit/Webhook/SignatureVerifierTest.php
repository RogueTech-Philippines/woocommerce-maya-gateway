<?php

/**
 * Unit tests for SignatureVerifier.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use RogueTechPhilippines\MayaGateway\Webhook\PayloadFlattener;
use RogueTechPhilippines\MayaGateway\Webhook\SignatureVerifier;

/**
 * Generate a fresh RSA keypair so the verifier round-trip is real (not mocked).
 *
 * @return array{0: string, 1: string} [private PEM, public PEM]
 */
function wc_maya_test_generate_keypair(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $private_pem);
    $details = openssl_pkey_get_details($key);

    return [ (string) $private_pem, (string) $details['key'] ];
}

function wc_maya_test_sign_payload(array $payload, string $nonce, string $private_pem): string
{
    $flat = PayloadFlattener::flatten($payload, $nonce);
    openssl_sign($flat, $signature, $private_pem, OPENSSL_ALGO_SHA256);

    return bin2hex($signature);
}

test('parse_header extracts nonce and v1 in any order', function (): void {
    expect(SignatureVerifier::parse_header('nonce=abc,v1=deadbeef'))->toBe([ 'abc', 'deadbeef' ]);
    expect(SignatureVerifier::parse_header('v1=deadbeef, nonce=abc'))->toBe([ 'abc', 'deadbeef' ]);
});

test('parse_header returns nulls when fields are missing or empty', function (): void {
    expect(SignatureVerifier::parse_header(''))->toBe([ null, null ]);
    expect(SignatureVerifier::parse_header('foo=bar'))->toBe([ null, null ]);
    expect(SignatureVerifier::parse_header('nonce=,v1='))->toBe([ null, null ]);
});

test('verify accepts a signature produced by a key in the bundle', function (): void {
    [ $private_pem, $public_pem ] = wc_maya_test_generate_keypair();

    $payload = [
        'id'                     => 'pay_test',
        'status'                 => 'PAYMENT_SUCCESS',
        'amount'                 => 100,
        'requestReferenceNumber' => '42',
    ];
    $nonce     = 'nonce-value';
    $signature = wc_maya_test_sign_payload($payload, $nonce, $private_pem);
    $header    = "nonce={$nonce},v1={$signature}";

    $verifier = new SignatureVerifier([ $public_pem ]);

    expect($verifier->verify($payload, $header))->toBeTrue();
});

test('verify rejects a tampered payload', function (): void {
    [ $private_pem, $public_pem ] = wc_maya_test_generate_keypair();

    $payload   = [ 'id' => 'pay_test', 'amount' => 100 ];
    $nonce     = 'n';
    $signature = wc_maya_test_sign_payload($payload, $nonce, $private_pem);

    $tampered           = $payload;
    $tampered['amount'] = 9999;

    $verifier = new SignatureVerifier([ $public_pem ]);

    expect($verifier->verify($tampered, "nonce={$nonce},v1={$signature}"))->toBeFalse();
});

test('verify rejects when the header is missing nonce or v1', function (): void {
    [ , $public_pem ] = wc_maya_test_generate_keypair();
    $verifier         = new SignatureVerifier([ $public_pem ]);

    expect($verifier->verify([ 'id' => 'x' ], ''))->toBeFalse();
    expect($verifier->verify([ 'id' => 'x' ], 'nonce=abc'))->toBeFalse();
});

test('verify rejects malformed hex in v1', function (): void {
    [ , $public_pem ] = wc_maya_test_generate_keypair();
    $verifier         = new SignatureVerifier([ $public_pem ]);

    expect($verifier->verify([ 'id' => 'x' ], 'nonce=abc,v1=zzznotvalid'))->toBeFalse();
});

test('verify walks every key until it finds a match', function (): void {
    [ $private_pem,    $public_pem ] = wc_maya_test_generate_keypair();
    [ , $other_public ]              = wc_maya_test_generate_keypair();

    $payload   = [ 'id' => 'pay_x' ];
    $nonce     = 'n';
    $signature = wc_maya_test_sign_payload($payload, $nonce, $private_pem);

    // Wrong key first, real key second — verifier must keep going.
    $verifier = new SignatureVerifier([ $other_public, $public_pem ]);

    expect($verifier->verify($payload, "nonce={$nonce},v1={$signature}"))->toBeTrue();
});
