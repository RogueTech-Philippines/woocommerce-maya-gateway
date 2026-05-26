<?php

/**
 * Maya signature payload flattener.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

/**
 * Re-creates the exact byte sequence Maya signs on the sender side.
 *
 * Algorithm (ported from the legacy plugin's `flatten_object_to_string` +
 * `verify_signature_v1`):
 *
 *  1. Walk the JSON payload depth-first.
 *  2. Skip null, empty-string, and empty-array values (Maya's signer does the
 *     same — including them would produce a string Maya never signed).
 *  3. Emit "{dotted.key}=value" — booleans become the lowercase strings
 *     "true"/"false", everything else is cast to string.
 *  4. Sort the resulting pairs ascending (ASCII byte order).
 *  5. Join with "&" and append "&nonce={nonce}".
 *
 * Pure function — no I/O, no WP dependency — so golden fixtures can pin the
 * shape against samples captured from real sandbox transactions.
 */
class PayloadFlattener
{
    /**
     * @param array<int|string,mixed> $payload Decoded JSON body.
     * @param string                  $nonce   Nonce from the X-Maya-Webhook-Signature header.
     */
    public static function flatten(array $payload, string $nonce): string
    {
        $pairs = [];
        self::walk($payload, '', $pairs);
        sort($pairs);

        return implode('&', $pairs) . '&nonce=' . $nonce;
    }

    /**
     * @param array<int|string,mixed> $obj
     * @param list<string>            $pairs
     */
    private static function walk(array $obj, string $prefix, array &$pairs): void
    {
        foreach ($obj as $key => $value) {
            $full_key = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (self::is_empty($value)) {
                continue;
            }

            if (is_array($value)) {
                self::walk($value, $full_key, $pairs);
                continue;
            }

            if (is_object($value)) {
                self::walk((array) $value, $full_key, $pairs);
                continue;
            }

            if (is_bool($value)) {
                $pairs[] = $full_key . '=' . ($value ? 'true' : 'false');
                continue;
            }

            $pairs[] = $full_key . '=' . (string) $value;
        }
    }

    private static function is_empty(mixed $value): bool
    {
        if (null === $value || '' === $value || [] === $value) {
            return true;
        }
        if (is_object($value) && 0 === count((array) $value)) {
            return true;
        }
        return false;
    }
}
