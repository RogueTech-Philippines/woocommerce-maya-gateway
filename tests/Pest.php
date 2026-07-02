<?php

/**
 * Pest 4 global configuration.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests
 */

declare(strict_types=1);

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class MayaBaseTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
}

pest()
    ->extend(MayaBaseTestCase::class)
    ->beforeEach(
        function (): void {
            Monkey\setUp();
        },
    )
    ->afterEach(
        function (): void {
            Monkey\tearDown();
        },
    )
    ->in('Unit');
