<?php

/**
 * WC log-file line parser used by the Maya admin event-log viewer.
 *
 * @package RogueTechPhilippines\MayaGateway\Admin\EventLog
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Admin\EventLog;

/**
 * Parses lines from WC log files (the format emitted by
 * `WC_Log_Handler_File`).
 *
 * Format:
 *
 *     2026-05-26T03:51:06+00:00 LEVEL message {optional-json-context}
 *
 * Lines that do not match are dropped — the viewer is a best-effort tail,
 * not a strict log shipper.
 *
 * Pure static + side-effect free so tests can pin every shape (multi-line
 * messages, malformed lines, missing context, blank context) without
 * touching the filesystem.
 */
final class EventLogParser
{
    private const LINE_REGEX = '/^(?P<timestamp>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[\+\-]\d{2}:\d{2})\s+(?P<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\s+(?P<rest>.*)$/i';

    /**
     * @return array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}|null
     */
    public static function parse_line(string $line): ?array
    {
        $line = rtrim($line);
        if ('' === $line) {
            return null;
        }

        if (1 !== preg_match(self::LINE_REGEX, $line, $matches)) {
            return null;
        }

        [$message, $context] = self::split_message_and_context($matches['rest']);

        return [
            'timestamp' => $matches['timestamp'],
            'level'     => strtolower($matches['level']),
            'message'   => $message,
            'context'   => $context,
        ];
    }

    /**
     * Parse the full contents of a log file. Empty / malformed lines are
     * dropped; entries are returned in the order they appear in the file
     * (which is chronological — WC appends).
     *
     * @return list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}>
     */
    public static function parse_lines(string $contents): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $entry = self::parse_line($line);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * @param list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}> $entries
     * @param list<string> $levels Lowercased levels to keep. Empty list = keep all.
     * @return list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}>
     */
    public static function filter_by_level(array $entries, array $levels): array
    {
        if ([] === $levels) {
            return $entries;
        }

        $normalized = array_map('strtolower', $levels);

        return array_values(array_filter(
            $entries,
            static fn(array $entry): bool => in_array($entry['level'], $normalized, true),
        ));
    }

    /**
     * Filter entries to the subset whose message OR context (JSON-encoded)
     * contains the given needle. Case-insensitive. Empty needle = pass-through.
     *
     * @param list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}> $entries
     * @return list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}>
     */
    public static function filter_by_search(array $entries, string $needle): array
    {
        $needle = trim($needle);
        if ('' === $needle) {
            return $entries;
        }

        $needle_lc = strtolower($needle);

        return array_values(array_filter(
            $entries,
            static function (array $entry) use ($needle_lc): bool {
                if (false !== strpos(strtolower($entry['message']), $needle_lc)) {
                    return true;
                }
                if (null === $entry['context']) {
                    return false;
                }
                $encoded = json_encode($entry['context']);
                return is_string($encoded)
                    && false !== strpos(strtolower($encoded), $needle_lc);
            },
        ));
    }

    /**
     * Splits the message-plus-context tail of a log line into the message
     * portion and a decoded context array (or null when no context is
     * attached). The "context" is whatever trailing JSON object the line
     * ends with — Logger always emits it as the last token via
     * `wp_json_encode`.
     *
     * Walks `{` positions left-to-right, attempting to decode from each
     * until one succeeds (cheaper than balanced-brace scanning and robust
     * to `{}` characters inside message text — e.g. a URL like
     * `/payments/v1/payments/{id}/capture`).
     *
     * @return array{0: string, 1: ?array<string,mixed>}
     */
    private static function split_message_and_context(string $rest): array
    {
        $rest = trim($rest);
        if ('' === $rest) {
            return [ '', null ];
        }

        $offset = 0;
        while (($pos = strpos($rest, '{', $offset)) !== false) {
            $candidate = substr($rest, $pos);
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                $message = rtrim(substr($rest, 0, $pos));
                return [ $message, $decoded ];
            }
            $offset = $pos + 1;
        }

        return [ $rest, null ];
    }
}
