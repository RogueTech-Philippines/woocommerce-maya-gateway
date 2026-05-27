<?php

/**
 * Unit tests for the WC log-line parser used by the admin event-log viewer.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Admin\EventLog
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Admin\EventLog;

use TaniKyuun\MayaGateway\Admin\EventLog\EventLogParser;

test('parse_line splits timestamp, level, message, and JSON context for a real WC line', function (): void {
    $line   = '2026-05-26T03:51:06+00:00 DEBUG -> POST /checkout/v1/checkouts {"auth_key":"public","body":{"totalAmount":{"value":100}}}';
    $result = EventLogParser::parse_line($line);

    expect($result)->not->toBeNull();
    expect($result['timestamp'])->toBe('2026-05-26T03:51:06+00:00');
    expect($result['level'])->toBe('debug');
    expect($result['message'])->toBe('-> POST /checkout/v1/checkouts');
    expect($result['context'])->toBe([
        'auth_key' => 'public',
        'body'     => [ 'totalAmount' => [ 'value' => 100 ] ],
    ]);
});

test('parse_line lowercases the level so filters can be case-insensitive', function (): void {
    $result = EventLogParser::parse_line('2026-05-26T03:51:06+00:00 WARNING webhook rejected');

    expect($result)->not->toBeNull();
    expect($result['level'])->toBe('warning');
});

test('parse_line handles entries with no context object', function (): void {
    $result = EventLogParser::parse_line('2026-05-26T03:51:06+00:00 INFO RetryQueue: replay terminal.');

    expect($result)->not->toBeNull();
    expect($result['message'])->toBe('RetryQueue: replay terminal.');
    expect($result['context'])->toBeNull();
});

test('parse_line returns null for blank lines', function (): void {
    expect(EventLogParser::parse_line(''))->toBeNull();
    expect(EventLogParser::parse_line('   '))->toBeNull();
});

test('parse_line returns null for non-WC lines (no timestamp at the front)', function (): void {
    expect(EventLogParser::parse_line('Plain text without a timestamp prefix'))->toBeNull();
    expect(EventLogParser::parse_line('DEBUG no timestamp'))->toBeNull();
});

test('parse_line keeps message text intact when the trailing brace is not valid JSON', function (): void {
    $line   = '2026-05-26T03:51:06+00:00 INFO Something with {invalid braces in it';
    $result = EventLogParser::parse_line($line);

    expect($result)->not->toBeNull();
    expect($result['context'])->toBeNull();
    expect($result['message'])->toBe('Something with {invalid braces in it');
});

test('parse_lines drops blank and unparseable lines, keeps chronological order', function (): void {
    $contents = implode("\n", [
        '2026-05-26T01:00:00+00:00 DEBUG line one',
        '',
        'garbage line',
        '2026-05-26T02:00:00+00:00 INFO line two {"k":"v"}',
        '   ',
        '2026-05-26T03:00:00+00:00 WARNING line three',
    ]);

    $entries = EventLogParser::parse_lines($contents);
    expect($entries)->toHaveCount(3);
    expect(array_column($entries, 'message'))->toBe([ 'line one', 'line two', 'line three' ]);
});

test('parse_lines accepts CRLF line endings', function (): void {
    $contents = "2026-05-26T01:00:00+00:00 DEBUG a\r\n2026-05-26T02:00:00+00:00 INFO b\r\n";

    expect(EventLogParser::parse_lines($contents))->toHaveCount(2);
});

test('filter_by_level keeps only entries matching the requested levels', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'debug',   'message' => 'a', 'context' => null ],
        [ 'timestamp' => 't2', 'level' => 'info',    'message' => 'b', 'context' => null ],
        [ 'timestamp' => 't3', 'level' => 'warning', 'message' => 'c', 'context' => null ],
        [ 'timestamp' => 't4', 'level' => 'error',   'message' => 'd', 'context' => null ],
    ];

    $kept = EventLogParser::filter_by_level($entries, [ 'warning', 'error' ]);
    expect(array_column($kept, 'message'))->toBe([ 'c', 'd' ]);
});

test('filter_by_level is case-insensitive', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'warning', 'message' => 'a', 'context' => null ],
    ];

    expect(EventLogParser::filter_by_level($entries, [ 'WARNING' ]))->toHaveCount(1);
});

test('filter_by_level passes everything through when given an empty levels list', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'debug', 'message' => 'a', 'context' => null ],
        [ 'timestamp' => 't2', 'level' => 'info',  'message' => 'b', 'context' => null ],
    ];

    expect(EventLogParser::filter_by_level($entries, []))->toHaveCount(2);
});

test('filter_by_search matches against the message text (case-insensitive)', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'info', 'message' => 'EventDispatcher: payment_complete()', 'context' => null ],
        [ 'timestamp' => 't2', 'level' => 'info', 'message' => 'EventDispatcher: ignored.',           'context' => null ],
    ];

    $kept = EventLogParser::filter_by_search($entries, 'PAYMENT_COMPLETE');
    expect($kept)->toHaveCount(1);
    expect($kept[0]['message'])->toContain('payment_complete()');
});

test('filter_by_search matches against the JSON-encoded context too', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'info', 'message' => 'msg', 'context' => [ 'order_id' => 4242 ] ],
        [ 'timestamp' => 't2', 'level' => 'info', 'message' => 'msg', 'context' => [ 'order_id' => 9999 ] ],
    ];

    $kept = EventLogParser::filter_by_search($entries, '4242');
    expect($kept)->toHaveCount(1);
    expect($kept[0]['context']['order_id'])->toBe(4242);
});

test('filter_by_search with an empty needle is a no-op pass-through', function (): void {
    $entries = [
        [ 'timestamp' => 't1', 'level' => 'info', 'message' => 'a', 'context' => null ],
    ];

    expect(EventLogParser::filter_by_search($entries, ''))->toBe($entries);
    expect(EventLogParser::filter_by_search($entries, '   '))->toBe($entries);
});

test('parse_line preserves the JSON when the message itself contains a brace', function (): void {
    $line   = '2026-05-26T03:51:06+00:00 INFO -> POST /payments/v1/payments/{id}/capture {"body":{"ok":true}}';
    $result = EventLogParser::parse_line($line);

    expect($result)->not->toBeNull();
    expect($result['message'])->toBe('-> POST /payments/v1/payments/{id}/capture');
    expect($result['context'])->toBe([ 'body' => [ 'ok' => true ] ]);
});
