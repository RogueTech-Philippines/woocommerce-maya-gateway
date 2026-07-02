<?php

/**
 * Refund-record value object.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

/**
 * Parsed item from GET `/payments/v1/payments/{id}/refunds`.
 *
 * Maya returns a list of refunds for a given payment. RefundProcessor reduces
 * over them to compute the still-refundable balance on a captured payment.
 * Only successful refunds count toward the balance — pending or failed ones
 * are tracked but don't lock funds.
 */
final readonly class RefundRecord
{
    public function __construct(
        public string $id,
        public string $status,
        public Money $amount,
        public string $reason,
        public string $request_reference_number,
        public string $created_at,
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
            amount: new Money((float) ($data['amount'] ?? 0), $currency),
            reason: isset($data['reason']) ? (string) $data['reason'] : '',
            request_reference_number: isset($data['requestReferenceNumber']) ? (string) $data['requestReferenceNumber'] : '',
            created_at: isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : '',
        );
    }

    /**
     * Maya marks successful refunds with `status: SUCCESS`. PENDING / FAILED
     * refunds don't reduce the captured payment's remaining refundable
     * balance and are excluded from the reduce in RefundProcessor.
     */
    public function is_successful(): bool
    {
        return 'SUCCESS' === $this->status;
    }
}
