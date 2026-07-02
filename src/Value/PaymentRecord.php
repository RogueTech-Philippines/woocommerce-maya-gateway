<?php

/**
 * Payment record value object.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

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
        public bool $is_capture = false,
        public string $created_at = '',
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
            // Maya marks captures with an `authorizationPayment` ref pointing
            // at their parent authorization. Presence alone is enough; we
            // don't need the nested object's contents (just the boolean fact).
            is_capture: array_key_exists('authorizationPayment', $data),
            created_at: isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : '',
        );
    }

    /**
     * True when the payment is an *authorization* (vs an immediate-capture
     * payment or a capture). Maya marks authorizations with the
     * `authorizationType` field.
     */
    public function is_authorization(): bool
    {
        return null !== $this->authorization_type;
    }
}
