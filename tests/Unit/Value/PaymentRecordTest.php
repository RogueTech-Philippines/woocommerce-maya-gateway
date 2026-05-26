<?php

/**
 * Unit tests for the PaymentRecord value object.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Value;

use TaniKyuun\MayaGateway\Value\PaymentRecord;

test('from_array parses a typical Maya payment record', function (): void {
    $record = PaymentRecord::from_array([
        'id'                     => 'pay-1',
        'status'                 => 'AUTHORIZED',
        'amount'                 => 500,
        'capturedAmount'         => 200,
        'currency'               => 'PHP',
        'requestReferenceNumber' => '42',
        'receiptNumber'          => 'rcpt-9',
        'canVoid'                => true,
        'canRefund'              => false,
        'canCapture'             => true,
        'authorizationType'      => 'NORMAL',
    ]);

    expect($record->id)->toBe('pay-1')
        ->and($record->status)->toBe('AUTHORIZED')
        ->and($record->amount->value)->toBe(500.0)
        ->and($record->amount->currency)->toBe('PHP')
        ->and($record->captured_amount?->value)->toBe(200.0)
        ->and($record->request_reference_number)->toBe('42')
        ->and($record->receipt_number)->toBe('rcpt-9')
        ->and($record->can_void)->toBeTrue()
        ->and($record->can_refund)->toBeFalse()
        ->and($record->can_capture)->toBeTrue()
        ->and($record->authorization_type)->toBe('NORMAL');
});

test('from_array yields null captured_amount when Maya omits it', function (): void {
    $record = PaymentRecord::from_array([
        'id'                     => 'pay-1',
        'status'                 => 'PAYMENT_SUCCESS',
        'amount'                 => 100,
        'currency'               => 'PHP',
        'requestReferenceNumber' => '42',
    ]);

    expect($record->captured_amount)->toBeNull()
        ->and($record->receipt_number)->toBeNull()
        ->and($record->authorization_type)->toBeNull()
        ->and($record->can_void)->toBeFalse();
});

test('captured_amount inherits the same currency as amount', function (): void {
    $record = PaymentRecord::from_array([
        'amount'         => 300,
        'capturedAmount' => 150,
        'currency'       => 'PHP',
    ]);

    expect($record->captured_amount?->currency)->toBe('PHP');
});
