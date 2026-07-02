<?php

/**
 * Maya outbound IP allowlist.
 *
 * @package RogueTechPhilippines\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Webhook;

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
        $bundled = $is_sandbox ? self::SANDBOX_IPS : self::PRODUCTION_IPS;

        /**
         * Filter the source-IP allowlist for Maya webhooks.
         *
         * Lets a site patch a changed Maya egress IP WITHOUT a plugin release
         * (otherwise every webhook would be blocked and orders would silently
         * stop completing). Returning an EMPTY array intentionally disables the
         * IP check — signature verification is the load-bearing check, so this
         * is a safe code-level escape hatch. It is deliberately NOT exposed as
         * an admin setting so it can't be clicked off by accident.
         *
         * @param list<string> $bundled    Bundled Maya IPs for this environment.
         * @param bool         $is_sandbox Whether the sandbox environment is active.
         */
        $ips = apply_filters('wc_maya_webhook_allowed_ips', $bundled, $is_sandbox);

        if (! is_array($ips)) {
            return $bundled;
        }

        return array_values(array_filter(
            array_map(static fn($ip): string => is_string($ip) ? trim($ip) : '', $ips),
            static fn(string $ip): bool => '' !== $ip,
        ));
    }

    public static function allows(string $ip, bool $is_sandbox): bool
    {
        $allowlist = self::for_environment($is_sandbox);

        // An empty allowlist means the check has been disabled via filter —
        // fail OPEN here (signature verification already gated this request).
        if ([] === $allowlist) {
            return true;
        }

        return in_array($ip, $allowlist, true);
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
            $value = trim((string) $server[ $key ]);
            if ('' === $value) {
                continue;
            }

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
