<?php

/**
 * Money value object.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

/**
 * Amount + currency pair, immutable.
 *
 * Maya uses major-unit decimals (PHP 100.00 → value: 100). Currency is the
 * ISO-4217 alpha code (always "PHP" in practice for Maya).
 */
final readonly class Money
{
    public function __construct(
        public float $value,
        public string $currency = 'PHP',
    ) {}

    /**
     * @param array{value: int|float|string, currency?: string} $data
     */
    public static function from_array(array $data): self
    {
        return new self(
            (float) ($data['value'] ?? 0),
            (string) ($data['currency'] ?? 'PHP'),
        );
    }

    /**
     * @return array{value: float, currency: string}
     */
    public function to_array(): array
    {
        return [
            'value'    => $this->value,
            'currency' => $this->currency,
        ];
    }
}
