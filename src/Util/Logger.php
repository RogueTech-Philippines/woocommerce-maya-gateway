<?php

/**
 * Logger utility.
 *
 * @package TaniKyuun\MayaGateway\Util
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Util;

/**
 * Thin wrapper around WC_Logger.
 *
 * Writes to wp-content/uploads/wc-logs/wc-maya-gateway-YYYY-MM-DD-*.log so all
 * Maya activity is in one place and can be inspected from WooCommerce →
 * Status → Logs. Context arrays are JSON-encoded for readability; any keys
 * that look like secrets (`Authorization`, `secret_key`, `public_key`, etc.)
 * are redacted before being written.
 *
 * Debug toggle: when `$debug_enabled` is false (the production default),
 * `debug` and `info` calls are dropped. Warnings and errors are *always*
 * recorded so genuine failures still leave a trail.
 */
class Logger
{
    public const SOURCE = 'wc-maya-gateway';

    /**
     * Keys whose values are replaced with `[redacted]` before a context array
     * is written. Two classes:
     *
     *  - Secrets: `authorization`, `*_key`, `secret` — must never hit disk.
     *  - Buyer PII: `buyer` — the createCheckout request body nests the
     *    customer's name, contact, and addresses under this key. Redacting the
     *    whole subtree keeps personal data out of shareable log files (PH Data
     *    Privacy Act / GDPR). Maya's validation errors echo the offending
     *    field in the *response* (see MayaApiClient::format_parameter_details),
     *    so redacting the request copy doesn't hurt debuggability.
     *
     * @var list<string>
     */
    private const REDACT_KEYS = [
        'authorization',
        'secret_key',
        'public_key',
        'api_key',
        'secret',
        'buyer',
    ];

    public function __construct(private readonly bool $debug_enabled = false) {}

    public function debug(string $message, array $context = []): void
    {
        if (! $this->debug_enabled) {
            return;
        }
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        if (! $this->debug_enabled) {
            return;
        }
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param 'debug'|'info'|'warning'|'error' $level
     * @param array<string,mixed>              $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        if (null === $logger) {
            return;
        }

        $line = $message;
        if ([] !== $context) {
            $line .= ' ' . (string) wp_json_encode($this->redact($context));
        }

        $logger->log($level, $line, [ 'source' => self::SOURCE ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $context[ $key ] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $context[ $key ] = $this->redact($value);
            }
        }

        return $context;
    }
}
