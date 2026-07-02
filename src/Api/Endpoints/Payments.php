<?php

/**
 * Maya `/payments/v1/*` endpoint wrappers (read + capture).
 *
 * @package RogueTechPhilippines\MayaGateway\Api\Endpoints
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Api\Endpoints;

use RogueTechPhilippines\MayaGateway\Api\MayaApiClient;
use RogueTechPhilippines\MayaGateway\Value\PaymentRecord;
use RogueTechPhilippines\MayaGateway\Value\RefundRecord;
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

    /**
     * Void an authorization. Only works while Maya still permits it
     * (`canVoid: true` on the payment record). Voids are full-amount only.
     */
    public function void(string $payment_id, string $reason): PaymentRecord|WP_Error
    {
        $response = $this->client->request(
            'POST',
            '/payments/v1/payments/' . rawurlencode($payment_id) . '/voids',
            [ 'reason' => $reason ],
            MayaApiClient::KEY_SECRET,
        );
        return $response instanceof WP_Error ? $response : PaymentRecord::from_array($response);
    }

    /**
     * Refund a captured (or immediate-capture PAYMENT_SUCCESS) payment, in
     * whole or in part.
     *
     * The payload mirrors Maya's contract:
     *
     *     [
     *         'totalAmount' => [ 'amount' => 50.0, 'currency' => 'PHP' ],
     *         'reason'      => 'Customer changed mind',
     *     ]
     *
     * @param array<string,mixed> $payload
     */
    public function refund(string $payment_id, array $payload): RefundRecord|WP_Error
    {
        $response = $this->client->request(
            'POST',
            '/payments/v1/payments/' . rawurlencode($payment_id) . '/refunds',
            $payload,
            MayaApiClient::KEY_SECRET,
        );
        return $response instanceof WP_Error ? $response : RefundRecord::from_array($response);
    }

    /**
     * List every refund Maya has on file for a given payment id. Used by
     * RefundProcessor to compute the still-refundable balance on a captured
     * payment (Maya doesn't expose a single "remaining" field).
     *
     * @return list<RefundRecord>|WP_Error
     */
    public function get_refunds(string $payment_id): array|WP_Error
    {
        $response = $this->client->request(
            'GET',
            '/payments/v1/payments/' . rawurlencode($payment_id) . '/refunds',
            null,
            MayaApiClient::KEY_SECRET,
        );
        if ($response instanceof WP_Error) {
            return $response;
        }

        $records = [];
        foreach ($response as $item) {
            if (is_array($item)) {
                $records[] = RefundRecord::from_array($item);
            }
        }
        return $records;
    }
}
