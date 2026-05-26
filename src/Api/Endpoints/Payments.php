<?php

/**
 * Maya `/payments/v1/*` endpoint wrappers (read + capture).
 *
 * @package TaniKyuun\MayaGateway\Api\Endpoints
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Api\Endpoints;

use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Value\PaymentRecord;
use WP_Error;

/**
 * Typed wrapper around the payments slice Maya exposes for the Checkout
 * product: looking up payments by RRN (the WC order id) and capturing
 * authorized funds.
 *
 * Both methods authenticate with the Checkout secret key. Phase 6 will add
 * `void()`, `refund()`, and `get_refunds()` to this class for the refund
 * decision tree.
 */
class Payments
{
    public function __construct(private readonly MayaApiClient $client) {}

    /**
     * List every payment Maya has on file for the given merchant reference.
     * Maya returns a JSON array — we wrap each item in a {@see PaymentRecord}.
     *
     * @return list<PaymentRecord>|WP_Error
     */
    public function get_by_rrn(string $rrn): array|WP_Error
    {
        $response = $this->client->request(
            'GET',
            '/payments/v1/payment-rrns/' . rawurlencode($rrn),
            null,
            MayaApiClient::KEY_SECRET,
        );
        if ($response instanceof WP_Error) {
            return $response;
        }

        $records = [];
        foreach ($response as $item) {
            if (is_array($item)) {
                $records[] = PaymentRecord::from_array($item);
            }
        }
        return $records;
    }

    /**
     * Capture an authorized payment, in full or partially.
     *
     * The payload mirrors Maya's contract:
     *
     *     [
     *         'requestReferenceNumber' => (string) $order_id,
     *         'captureAmount' => [
     *             'amount'   => 50.0,
     *             'currency' => 'PHP',
     *         ],
     *     ]
     *
     * @param array<string,mixed> $payload
     */
    public function capture(string $payment_id, array $payload): PaymentRecord|WP_Error
    {
        $response = $this->client->request(
            'POST',
            '/payments/v1/payments/' . rawurlencode($payment_id) . '/capture',
            $payload,
            MayaApiClient::KEY_SECRET,
        );
        return $response instanceof WP_Error ? $response : PaymentRecord::from_array($response);
    }
}
