<?php

/**
 * Checkout-session value object.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

/**
 * Parsed result of POST /checkout/v1/checkouts.
 *
 * Maya returns more fields than these two, but `checkoutId` and `redirectUrl`
 * are the only ones the plugin acts on; everything else is logged raw by the
 * API client.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $checkout_id,
        public string $redirect_url,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function from_array(array $data): self
    {
        return new self(
            isset($data['checkoutId'])  && is_string($data['checkoutId']) ? $data['checkoutId'] : '',
            isset($data['redirectUrl']) && is_string($data['redirectUrl']) ? $data['redirectUrl'] : '',
        );
    }

    /**
     * @return array{checkoutId: string, redirectUrl: string}
     */
    public function to_array(): array
    {
        return [
            'checkoutId'  => $this->checkout_id,
            'redirectUrl' => $this->redirect_url,
        ];
    }
}
