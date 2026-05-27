<?php

/**
 * Unit tests for the Logger — level gating + secret/PII redaction.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Util
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Util;

use Brain\Monkey\Functions;
use TaniKyuun\MayaGateway\Util\Logger;

/**
 * Register a capturing sink as WC's logger for the current test.
 *
 * The `wc_get_logger()` test stub (tests/stubs.php) returns whatever is in
 * $GLOBALS['wc_maya_test_log_sink']; we set it here and clear it in
 * afterEach so logging stays a no-op for every other test. `wp_json_encode`
 * is stubbed per-test via Brain Monkey (below) — it can't live in stubs.php
 * because Patchwork must own the function to let other tests redefine it.
 */
function wc_maya_log_sink(): object
{
    $sink = new class {
        /** @var list<array{level: string, message: string, context: array}> */
        public array $calls = [];

        public function log(string $level, string $message, array $context = []): void
        {
            $this->calls[] = compact('level', 'message', 'context');
        }
    };

    $GLOBALS['wc_maya_test_log_sink'] = $sink;

    return $sink;
}

beforeEach(function (): void {
    Functions\when('wp_json_encode')->alias(static fn(mixed $data): string|false => json_encode($data));
});

afterEach(function (): void {
    unset($GLOBALS['wc_maya_test_log_sink']);
});

test('SOURCE constant is the dedicated maya channel', function (): void {
    expect(Logger::SOURCE)->toBe('wc-maya-gateway');
});

test('warning and error are always logged with the maya source', function (): void {
    $sink = wc_maya_log_sink();

    (new Logger(false))->warning('warn-msg');
    (new Logger(false))->error('err-msg');

    expect($sink->calls)->toHaveCount(2);
    expect($sink->calls[0])->toMatchArray([ 'level' => 'warning', 'message' => 'warn-msg' ]);
    expect($sink->calls[0]['context'])->toBe([ 'source' => 'wc-maya-gateway' ]);
    expect($sink->calls[1])->toMatchArray([ 'level' => 'error', 'message' => 'err-msg' ]);
});

test('debug and info are dropped when debug logging is disabled', function (): void {
    $sink = wc_maya_log_sink();

    $logger = new Logger(false);
    $logger->debug('d');
    $logger->info('i');

    expect($sink->calls)->toHaveCount(0);
});

test('debug and info are emitted when debug logging is enabled', function (): void {
    $sink = wc_maya_log_sink();

    $logger = new Logger(true);
    $logger->debug('d');
    $logger->info('i');

    expect($sink->calls)->toHaveCount(2);
    expect($sink->calls[0]['level'])->toBe('debug');
    expect($sink->calls[1]['level'])->toBe('info');
});

test('an empty context appends no JSON tail — line is the bare message', function (): void {
    $sink = wc_maya_log_sink();

    (new Logger(false))->warning('just the message');

    expect($sink->calls[0]['message'])->toBe('just the message');
});

test('secret-like keys are redacted; benign keys pass through', function (): void {
    $sink = wc_maya_log_sink();

    (new Logger(true))->debug('->', [
        'secret_key'    => 'sk-123',
        'public_key'    => 'pk-456',
        'authorization' => 'Basic abc',
        'api_key'       => 'ak-789',
        'secret'        => 'shh',
        'auth_key'      => 'public', // benign: NOT in the redact list
    ]);

    $line = $sink->calls[0]['message'];

    expect($line)->toContain('[redacted]');
    expect($line)->not->toContain('sk-123');
    expect($line)->not->toContain('pk-456');
    expect($line)->not->toContain('Basic abc');
    expect($line)->not->toContain('ak-789');
    // The benign auth_key value survives verbatim.
    expect($line)->toContain('"auth_key":"public"');
});

test('the buyer subtree (PII) is redacted whole, siblings preserved', function (): void {
    $sink = wc_maya_log_sink();

    (new Logger(true))->debug('->', [
        'body' => [
            'buyer' => [
                'firstName'      => 'Juan',
                'contact'        => [ 'email' => 'juan@example.test', 'phone' => '+639170000000' ],
                'billingAddress' => [ 'line1' => '123 Rizal' ],
            ],
            'requestReferenceNumber' => '42',
        ],
    ]);

    $line = $sink->calls[0]['message'];

    expect($line)->not->toContain('Juan');
    expect($line)->not->toContain('juan@example.test');
    expect($line)->not->toContain('+639170000000');
    expect($line)->not->toContain('123 Rizal');
    expect($line)->toContain('[redacted]');
    // Non-PII sibling is kept so the log is still useful for correlation.
    expect($line)->toContain('"requestReferenceNumber":"42"');
});

test('redaction matches key names case-insensitively', function (): void {
    $sink = wc_maya_log_sink();

    (new Logger(true))->debug('->', [
        'Authorization' => 'Basic zzz',
        'Buyer'         => [ 'firstName' => 'Maria' ],
    ]);

    $line = $sink->calls[0]['message'];

    expect($line)->not->toContain('Basic zzz');
    expect($line)->not->toContain('Maria');
});
