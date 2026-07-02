<?php

/**
 * Unit tests for WebhookRecord.
 *
 * @package RogueTechPhilippines\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Tests\Unit\Value;

use RogueTechPhilippines\MayaGateway\Value\WebhookRecord;

test('from_array maps every Maya field onto its property', function (): void {
    $record = WebhookRecord::from_array([
        'id'          => 'wh-123',
        'name'        => 'PAYMENT_SUCCESS',
        'callbackUrl' => 'https://example.test/?wc-api=maya_webhook',
        'createdAt'   => '2026-05-01T12:00:00Z',
        'updatedAt'   => '2026-05-02T12:00:00Z',
    ]);

    expect($record->id)->toBe('wh-123')
        ->and($record->name)->toBe('PAYMENT_SUCCESS')
        ->and($record->callback_url)->toBe('https://example.test/?wc-api=maya_webhook')
        ->and($record->created_at)->toBe('2026-05-01T12:00:00Z')
        ->and($record->updated_at)->toBe('2026-05-02T12:00:00Z');
});

test('from_array defaults missing fields to empty strings', function (): void {
    $record = WebhookRecord::from_array([]);

    expect($record->id)->toBe('')
        ->and($record->name)->toBe('')
        ->and($record->callback_url)->toBe('')
        ->and($record->created_at)->toBe('')
        ->and($record->updated_at)->toBe('');
});

test('to_array round-trips Maya-shaped keys', function (): void {
    $original = WebhookRecord::from_array([
        'id'          => 'wh-1',
        'name'        => 'CHECKOUT_SUCCESS',
        'callbackUrl' => 'https://example.test/cb',
        'createdAt'   => '2026-01-01T00:00:00Z',
        'updatedAt'   => '2026-01-02T00:00:00Z',
    ]);

    expect($original->to_array())->toBe([
        'id'          => 'wh-1',
        'name'        => 'CHECKOUT_SUCCESS',
        'callbackUrl' => 'https://example.test/cb',
        'createdAt'   => '2026-01-01T00:00:00Z',
        'updatedAt'   => '2026-01-02T00:00:00Z',
    ]);
});
