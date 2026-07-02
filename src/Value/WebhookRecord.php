<?php

/**
 * Webhook-registration value object.
 *
 * @package RogueTechPhilippines\MayaGateway\Value
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Value;

/**
 * Parsed item from GET/POST `/checkout/v1/webhooks`.
 *
 * Mirrors what Maya returns: an id, the event name, the callback URL, and
 * the two timestamps. Kept as strings (no DateTimeImmutable wrapping) because
 * the registrar only needs to display them in the admin status table — and
 * Maya's timestamps are ISO-8601, so passing the raw string to a JS
 * `new Date()` is cheaper than re-formatting in PHP.
 */
final readonly class WebhookRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $callback_url,
        public string $created_at,
        public string $updated_at,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function from_array(array $data): self
    {
        return new self(
            id: isset($data['id'])                    && is_string($data['id']) ? $data['id'] : '',
            name: isset($data['name'])                && is_string($data['name']) ? $data['name'] : '',
            callback_url: isset($data['callbackUrl']) && is_string($data['callbackUrl']) ? $data['callbackUrl'] : '',
            created_at: isset($data['createdAt'])     && is_string($data['createdAt']) ? $data['createdAt'] : '',
            updated_at: isset($data['updatedAt'])     && is_string($data['updatedAt']) ? $data['updatedAt'] : '',
        );
    }

    /**
     * @return array{id: string, name: string, callbackUrl: string, createdAt: string, updatedAt: string}
     */
    public function to_array(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'callbackUrl' => $this->callback_url,
            'createdAt'   => $this->created_at,
            'updatedAt'   => $this->updated_at,
        ];
    }
}
