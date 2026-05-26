<?php

/**
 * Payment record value object.
 *
 * @package TaniKyuun\MayaGateway\Value
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Value;

/**
 * Parsed item from GET /payments/v1/payment-rrns/{rrn}.
 *
 * Used by the capture/refund/void flows added in later phases. Keeps the
 * Maya-side flags (`canVoid`, `canRefund`, `canCapture`) as typed bools so
 * the decision tree in RefundProcessor doesn't have to repeat null-coalesces.
 */
final readonly class PaymentRecord
{
    public function __construct(
        public string $id,
        public string $status,
        public Money $amount,
        public ?Money $captured_amount,
        public string $request_reference_number,
        public ?string $receipt_number,
        public bool $can_void,
        public bool $can_refund,
        public bool $can_capture,
        public ?string $authorization_type,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function from_array(array $data): self
    {
        $currency = isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'PHP';

        return new self(
            id: isset($data['id'])         && is_string($data['id']) ? $data['id'] : '',
            status: isset($data['status']) && is_string($data['status']) ? $data['status'] : '',
            amount: new Money(
                (float) ($data['amount'] ?? 0),
                $currency,
            ),
            captured_amount: isset($data['capturedAmount'])
                ? new Money((float) $data['capturedAmount'], $currency)
                : null,
            request_reference_number: isset($data['requestReferenceNumber']) ? (string) $data['requestReferenceNumber'] : '',
            receipt_number: isset($data['receiptNumber']) ? (string) $data['receiptNumber'] : null,
            can_void: ! empty($data['canVoid']),
            can_refund: ! empty($data['canRefund']),
            can_capture: ! empty($data['canCapture']),
            authorization_type: isset($data['authorizationType']) && is_string($data['authorizationType'])
                ? $data['authorizationType']
                : null,
        );
    }
}
