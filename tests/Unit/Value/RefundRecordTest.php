<?php

/**
 * Unit tests for RefundRecord.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Value;

use TaniKyuun\MayaGateway\Value\RefundRecord;

test('from_array maps Maya fields onto typed properties', function (): void {
    $record = RefundRecord::from_array([
        'id'                     => 'rfd_1',
        'status'                 => 'SUCCESS',
        'amount'                 => 50.0,
        'currency'               => 'PHP',
        'reason'                 => 'Customer request',
        'requestReferenceNumber' => 'order-42-rfd-1',
        'createdAt'              => '2026-05-01T00:00:00Z',
    ]);

    expect($record->id)->toBe('rfd_1');
    expect($record->status)->toBe('SUCCESS');
    expect($record->amount->value)->toBe(50.0);
    expect($record->amount->currency)->toBe('PHP');
    expect($record->reason)->toBe('Customer request');
    expect($record->request_reference_number)->toBe('order-42-rfd-1');
    expect($record->created_at)->toBe('2026-05-01T00:00:00Z');
});

test('from_array defaults currency to PHP and missing fields to empties', function (): void {
    $record = RefundRecord::from_array([ 'id' => 'r1' ]);

    expect($record->id)->toBe('r1');
    expect($record->status)->toBe('');
    expect($record->amount->currency)->toBe('PHP');
    expect($record->amount->value)->toBe(0.0);
    expect($record->reason)->toBe('');
});

test('is_successful only returns true for status SUCCESS', function (): void {
    expect(RefundRecord::from_array([ 'status' => 'SUCCESS' ])->is_successful())->toBeTrue();
    expect(RefundRecord::from_array([ 'status' => 'PENDING' ])->is_successful())->toBeFalse();
    expect(RefundRecord::from_array([ 'status' => 'FAILED' ])->is_successful())->toBeFalse();
    expect(RefundRecord::from_array([])->is_successful())->toBeFalse();
});
