<?php

/**
 * Unit tests for the AuthorizationType enum.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Value;

use RogueTechPhilippines\MayaGateway\Value\AuthorizationType;

test('for_maya_api uppercases the stored value', function (): void {
    expect(AuthorizationType::Normal->for_maya_api())->toBe('NORMAL')
        ->and(AuthorizationType::FinalAuth->for_maya_api())->toBe('FINAL')
        ->and(AuthorizationType::Preauthorization->for_maya_api())->toBe('PREAUTHORIZATION');
});

test('None is not a manual-capture mode', function (): void {
    expect(AuthorizationType::None->is_manual_capture())->toBeFalse()
        ->and(AuthorizationType::Normal->is_manual_capture())->toBeTrue()
        ->and(AuthorizationType::FinalAuth->is_manual_capture())->toBeTrue()
        ->and(AuthorizationType::Preauthorization->is_manual_capture())->toBeTrue();
});

test('from_setting trims and lowercases input', function (): void {
    expect(AuthorizationType::from_setting('  NORMAL  '))->toBe(AuthorizationType::Normal)
        ->and(AuthorizationType::from_setting('final'))->toBe(AuthorizationType::FinalAuth);
});

test('from_setting falls back to None for invalid input', function (): void {
    expect(AuthorizationType::from_setting('unknown'))->toBe(AuthorizationType::None)
        ->and(AuthorizationType::from_setting(null))->toBe(AuthorizationType::None)
        ->and(AuthorizationType::from_setting(42))->toBe(AuthorizationType::None);
});
