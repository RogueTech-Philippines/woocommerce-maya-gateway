<?php

/**
 * Unit tests for IpAllowlist.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Webhook;

use RogueTechPhilippines\MayaGateway\Webhook\IpAllowlist;

test('lists Maya\'s documented sandbox and production IPs', function (): void {
    expect(IpAllowlist::SANDBOX_IPS)->toEqualCanonicalizing([ '13.229.160.234', '3.1.199.75' ]);
    expect(IpAllowlist::PRODUCTION_IPS)->toEqualCanonicalizing([ '18.138.50.235', '3.1.207.200' ]);
});

test('allows() respects environment selection', function (): void {
    expect(IpAllowlist::allows('3.1.199.75', true))->toBeTrue();
    expect(IpAllowlist::allows('3.1.199.75', false))->toBeFalse();
    expect(IpAllowlist::allows('18.138.50.235', false))->toBeTrue();
    expect(IpAllowlist::allows('203.0.113.7', true))->toBeFalse();
});

test('get_source_ip prefers CF-Connecting-IP over X-Forwarded-For', function (): void {
    $ip = IpAllowlist::get_source_ip([
        'HTTP_CF_CONNECTING_IP' => '3.1.199.75',
        'HTTP_X_FORWARDED_FOR'  => '8.8.8.8',
        'REMOTE_ADDR'           => '127.0.0.1',
    ]);

    expect($ip)->toBe('3.1.199.75');
});

test('get_source_ip uses the first X-Forwarded-For entry when CF is absent', function (): void {
    $ip = IpAllowlist::get_source_ip([
        'HTTP_X_FORWARDED_FOR' => '13.229.160.234, 10.0.0.1',
        'REMOTE_ADDR'          => '127.0.0.1',
    ]);

    expect($ip)->toBe('13.229.160.234');
});

test('get_source_ip falls back through X-Client-IP, Client-IP, REMOTE_ADDR', function (): void {
    expect(IpAllowlist::get_source_ip([ 'HTTP_X_CLIENT_IP' => '203.0.113.5' ]))->toBe('203.0.113.5');
    expect(IpAllowlist::get_source_ip([ 'HTTP_CLIENT_IP' => '203.0.113.6' ]))->toBe('203.0.113.6');
    expect(IpAllowlist::get_source_ip([ 'REMOTE_ADDR' => '127.0.0.1' ]))->toBe('127.0.0.1');
    expect(IpAllowlist::get_source_ip([]))->toBe('');
});

test('get_source_ip trims whitespace and skips all-whitespace values', function (): void {
    expect(IpAllowlist::get_source_ip([ 'HTTP_CF_CONNECTING_IP' => "  3.1.199.75\n" ]))->toBe('3.1.199.75');
    expect(IpAllowlist::get_source_ip([
        'HTTP_CF_CONNECTING_IP' => '   ',
        'REMOTE_ADDR'           => '127.0.0.1',
    ]))->toBe('127.0.0.1');
});
