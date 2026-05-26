<?php

/**
 * Unit tests for PayloadFlattener.
 *
 * @package TaniKyuun\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Tests\Unit\Webhook;

use TaniKyuun\MayaGateway\Webhook\PayloadFlattener;

test('flattens nested objects with dotted keys', function (): void {
    $flat = PayloadFlattener::flatten(
        [
            'id'     => 'pay_123',
            'amount' => [
                'value'    => 100,
                'currency' => 'PHP',
            ],
        ],
        'NONCE',
    );

    expect($flat)->toBe('amount.currency=PHP&amount.value=100&id=pay_123&nonce=NONCE');
});

test('emits booleans as lowercase strings', function (): void {
    $flat = PayloadFlattener::flatten(
        [
            'canVoid'   => true,
            'canRefund' => false,
        ],
        'n',
    );

    expect($flat)->toBe('canRefund=false&canVoid=true&nonce=n');
});

test('skips null, empty string, and empty array values', function (): void {
    $flat = PayloadFlattener::flatten(
        [
            'present' => 'yes',
            'nope'    => null,
            'blank'   => '',
            'empty'   => [],
            'nested'  => [ 'inner' => '' ],
        ],
        'X',
    );

    expect($flat)->toBe('present=yes&nonce=X');
});

test('sorts pairs in ASCII order before appending nonce', function (): void {
    $flat = PayloadFlattener::flatten(
        [
            'zeta'  => '1',
            'alpha' => '2',
            'mu'    => '3',
        ],
        'NN',
    );

    expect($flat)->toBe('alpha=2&mu=3&zeta=1&nonce=NN');
});

test('matches the legacy plugin shape for a realistic Maya payment record', function (): void {
    $payload = [
        'id'                     => 'pay_abc',
        'isPaid'                 => true,
        'status'                 => 'PAYMENT_SUCCESS',
        'amount'                 => 199.5,
        'currency'               => 'PHP',
        'canVoid'                => false,
        'canRefund'              => true,
        'canCapture'             => false,
        'requestReferenceNumber' => '4242',
        'metadata'               => [
            'source' => 'wc',
            'noise'  => null,
        ],
    ];

    $flat = PayloadFlattener::flatten($payload, 'fixture-nonce');

    expect($flat)->toBe(
        'amount=199.5'
        . '&canCapture=false'
        . '&canRefund=true'
        . '&canVoid=false'
        . '&currency=PHP'
        . '&id=pay_abc'
        . '&isPaid=true'
        . '&metadata.source=wc'
        . '&requestReferenceNumber=4242'
        . '&status=PAYMENT_SUCCESS'
        . '&nonce=fixture-nonce',
    );
});
