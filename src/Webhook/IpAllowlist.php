<?php

/**
 * Maya outbound IP allowlist.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

/**
 * Single source of truth for the four IPs Maya posts webhooks from.
 *
 * Documented at https://developers.maya.ph — two per environment. Plugin
 * setups behind Cloudflare or other proxies should expose the real source
 * via `HTTP_CF_CONNECTING_IP` / `HTTP_X_FORWARDED_FOR`; `get_source_ip`
 * walks the standard set of candidate headers in priority order.
 */
class IpAllowlist
{
    /**
     * @var list<string>
     */
    public const SANDBOX_IPS = [
        '13.229.160.234',
        '3.1.199.75',
    ];

    /**
     * @var list<string>
     */
    public const PRODUCTION_IPS = [
        '18.138.50.235',
        '3.1.207.200',
    ];

    /**
     * @return list<string>
     */
    public static function for_environment(bool $is_sandbox): array
    {
        return $is_sandbox ? self::SANDBOX_IPS : self::PRODUCTION_IPS;
    }

    public static function allows(string $ip, bool $is_sandbox): bool
    {
        return in_array($ip, self::for_environment($is_sandbox), true);
    }

    /**
     * Pick the most-likely source IP from a `$_SERVER`-shaped array.
     *
     * Order matches the legacy plugin: Cloudflare's `CF-Connecting-IP` is
     * preferred because Cloudflare sets it itself when the request traverses
     * CF, then `X-Forwarded-For` for non-CF reverse proxies, then less-common
     * proxy headers, and finally `REMOTE_ADDR` for direct connections.
     *
     * Caveat: any proxy header can be spoofed by a client that bypasses the
     * intended proxy. The IP allowlist is defense-in-depth — the load-bearing
     * security check is RSA signature verification in {@see SignatureVerifier}.
     *
     * @param array<string,mixed> $server $_SERVER-shaped array.
     */
    public static function get_source_ip(array $server): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_CLIENT_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (empty($server[ $key ])) {
                continue;
            }
            $value = (string) $server[ $key ];

            if ('HTTP_X_FORWARDED_FOR' === $key) {
                $parts = array_map('trim', explode(',', $value));
                $first = $parts[0] ?? '';
                if ('' !== $first) {
                    return $first;
                }
                continue;
            }

            return $value;
        }

        return '';
    }
}
