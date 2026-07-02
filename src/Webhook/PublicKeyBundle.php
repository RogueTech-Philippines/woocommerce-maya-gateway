<?php

/**
 * Maya webhook-signing public keys.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

/**
 * RSA public keys Maya uses to sign outbound webhooks.
 *
 * Two keys per environment so Maya can rotate one without breaking
 * verification on our side: SignatureVerifier walks both and accepts a match
 * on either. If Maya ever publishes a key-rotation endpoint, this class is
 * the single place that needs to learn how to fetch.
 *
 * Source: the four PEMs are reproduced verbatim from Maya's official guidance
 * (see the lines 39-87 of the legacy wc-maya-payment-gateway plugin, which
 * captured them from Maya's developer portal).
 */
class PublicKeyBundle
{
    /**
     * @var list<string>
     */
    public const SANDBOX_PEMS = [
        "-----BEGIN PUBLIC KEY-----\n"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAjNkSX6p+goDPaPAYuTzT\n"
        . "zKTCBeLhh8FkPMbZxDKTUxF93dOwiC7jsdx7KyopupeLosiVlbs+gpAJ7XBQP/Ex\n"
        . "giyzXC9TljpyvkUQfyRPMAMKq+BzxdUliTl6hgrLBsH28CP5FuPHCsfxDXe7mDtv\n"
        . "9H4mP3SKO0HfkZ45tudxD9CWbwWKF0lU9LRbLlJ0y7KEaK7Rv9fI1Dp/KPT+9pls\n"
        . "tU+CPNKaxJjGRKGuxW2AOCabSD0cTZNXki+K51mNoma7Mj1HMhnsR68FGJvCqk1q\n"
        . "Wsr3q8+EUMVPBMX+5nKATfZYGvxg4ytzT8pnEVeWl6phYKviB9aVVwurh1gDJB4r\n"
        . "lQIDAQAB\n"
        . "-----END PUBLIC KEY-----\n",
        "-----BEGIN PUBLIC KEY-----\n"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAp14gezqq4dGWu7EZ7BHx\n"
        . "8wD3y1hqxwQR7UYPXtXJP+WngN4wqwatjsnQaRGnmdPRG8VEzUzw9PlR7t7P24uW\n"
        . "+J08xBrtTVouD2MKglcIcy13rt1XL79zr/LIAFMFI6f4O8/OQi1xsGsZ6xarD+wl\n"
        . "OQKG4W66I3yp2jNAbge25eSPuo0BNqPWvebMcIYJu4f3Fxu1eDgeM6zCEqLc6+jX\n"
        . "cNTP/zFHCvQaiIlLOqfgXDRPBcHPPZ2qcB99UVPAHXBKsKdtBB2w2qT2l99MlTAB\n"
        . "iRy+IKtVQcQyRP7T8blegO25x35G2CZ3VCKPkmUen3eXQ4+r5fVlzEIBSfNvBwT9\n"
        . "jQIDAQAB\n"
        . "-----END PUBLIC KEY-----\n",
    ];

    /**
     * @var list<string>
     */
    public const PRODUCTION_PEMS = [
        "-----BEGIN PUBLIC KEY-----\n"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAjCGhkjg1PQe0WVHCYdTT\n"
        . "2luqzXhKfeStALWlEcMpHqYusd6dAU4vZ9bGQns/OYe/H2cIxEPvRJnRcipMKvVZ\n"
        . "pzAFEKHQLiXdeuNcxkAaxEZEwMAmFdVGmNLZbpi579r2s6Q++zYy0OHb9awY/2z0\n"
        . "OYRwV5XN7SCrqIlf1tEHfxKV2cJDCFW030nnRMoWisQ9KXG3Ihvjj4tOQimPCtzp\n"
        . "SDtlf6QFmg/WZBIOEdLro9oROztK6PwrI/yG5ZFaUCQYfY8fw0y1/PI3heEf8z5k\n"
        . "xA466LdSqCeVdGwfjKy9ZHown8XiiPI82HnBrMP3UPX4efEfopbP4SpDFOEwRNA9\n"
        . "FQIDAQAB\n"
        . "-----END PUBLIC KEY-----\n",
        "-----BEGIN PUBLIC KEY-----\n"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxZJxNmpNYjxFCBa2P6Ad\n"
        . "wzDDuDKOKAgiTBrQvJGuX/l2u32N4d4FYw99md16rf1iIcxD70/KG9nWrltrxbIs\n"
        . "bm9+bCHVLKMfdjaJQCBGXN/WW6W1XaGQQPft9UlmAwA/uMKTsN/2XqFjoKSJoe9e\n"
        . "Xz/p3pGn66oBTCwvzDqma46GxF92atiOt6CEcRl8P+dDKJlYY7fcxiuNMeDMOOla\n"
        . "KMxUz9nMgJ6uESK/kS8C8+hGuiCWgKeIRm/ONL5Gk/lypWzrphaKcWqpBGZxpNAL\n"
        . "AVmPY9ke4+RxyojkEre4d5sT2C21oAQVHyGewd0ttQ/bK59X17+yg5FOfRpI1BKj\n"
        . "7wIDAQAB\n"
        . "-----END PUBLIC KEY-----\n",
    ];

    /**
     * @return list<string>
     */
    public static function for_environment(bool $is_sandbox): array
    {
        $bundled = $is_sandbox ? self::SANDBOX_PEMS : self::PRODUCTION_PEMS;

        /**
         * Filter the RSA public keys used to verify Maya webhook signatures.
         *
         * Lets a site patch in a rotated / emergency key WITHOUT a plugin
         * release if Maya changes its signing key unexpectedly — otherwise
         * every webhook would fail signature verification and orders would
         * silently stop completing until an update ships. The bundled PEMs are
         * the default; a filter can append to or replace them.
         *
         * @param list<string> $bundled    The bundled PEM strings for this environment.
         * @param bool         $is_sandbox Whether the sandbox environment is active.
         */
        $keys = apply_filters('wc_maya_webhook_public_keys', $bundled, $is_sandbox);

        if (! is_array($keys)) {
            return $bundled;
        }

        $keys = array_values(array_filter(
            array_map(static fn($k): string => is_string($k) ? $k : '', $keys),
            static fn(string $k): bool => '' !== trim($k),
        ));

        // Never leave verification with an empty key set — fall back to bundled
        // so a mis-typed filter can't disable signature checks entirely.
        return [] === $keys ? $bundled : $keys;
    }
}
