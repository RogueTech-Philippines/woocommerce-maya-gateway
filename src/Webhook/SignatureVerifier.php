<?php

/**
 * RSA-SHA256 verification of Maya webhook signatures.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

/**
 * Verifies the `X-Maya-Webhook-Signature` header against a flattened payload.
 *
 * Header shape: `nonce=<nonce>,v1=<hex-rsa-sha256>` — the comma-separated
 * key=value pairs are independent of order. We extract both, hex-decode the
 * v1 signature, ask PayloadFlattener to produce the canonical byte sequence
 * Maya signed, and walk every PEM in the bundle calling openssl_verify until
 * one accepts.
 *
 * Maya rotates between two active keys per environment, so verification
 * succeeds when *any* key matches (not all).
 */
class SignatureVerifier
{
    /**
     * @param list<string> $public_keys PEM-encoded RSA public keys.
     */
    public function __construct(private readonly array $public_keys) {}

    /**
     * @param array<int|string,mixed> $payload Decoded JSON body.
     */
    public function verify(array $payload, string $signature_header): bool
    {
        [ $nonce, $hex_v1 ] = self::parse_header($signature_header);

        if (null === $nonce || null === $hex_v1) {
            return false;
        }

        if ('' === $hex_v1 || 0 !== strlen($hex_v1) % 2 || ! ctype_xdigit($hex_v1)) {
            return false;
        }

        $signature_bytes = hex2bin($hex_v1);
        if (false === $signature_bytes || '' === $signature_bytes) {
            return false;
        }

        $flat = PayloadFlattener::flatten($payload, $nonce);

        foreach ($this->public_keys as $pem) {
            $result = openssl_verify($flat, $signature_bytes, $pem, 'sha256WithRSAEncryption');
            if (1 === $result) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the `nonce=` and `v1=` parts. Returns nulls when missing.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parse_header(string $header): array
    {
        if ('' === $header) {
            return [ null, null ];
        }

        $nonce = null;
        $v1    = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'nonce=')) {
                $nonce = substr($part, strlen('nonce='));
            } elseif (str_starts_with($part, 'v1=')) {
                $v1 = substr($part, strlen('v1='));
            }
        }

        if ('' === $nonce) {
            $nonce = null;
        }
        if ('' === $v1) {
            $v1 = null;
        }

        return [ $nonce, $v1 ];
    }
}
