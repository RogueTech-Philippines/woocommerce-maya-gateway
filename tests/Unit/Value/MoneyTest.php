<?php

/**
 * Unit tests for the Money value object.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Value;

use TaniKyuun\MayaGateway\Value\Money;

test('from_array casts numeric strings to float', function (): void {
    $money = Money::from_array([ 'value' => '100.50', 'currency' => 'PHP' ]);

    expect($money->value)->toBe(100.5)
        ->and($money->currency)->toBe('PHP');
});

test('from_array defaults currency to PHP when omitted', function (): void {
    $money = Money::from_array([ 'value' => 250 ]);

    expect($money->currency)->toBe('PHP');
});

test('to_array round-trips the value and currency', function (): void {
    $original  = new Money(199.99, 'PHP');
    $reparsed  = Money::from_array($original->to_array());

    expect($reparsed->value)->toBe(199.99)
        ->and($reparsed->currency)->toBe('PHP');
});
