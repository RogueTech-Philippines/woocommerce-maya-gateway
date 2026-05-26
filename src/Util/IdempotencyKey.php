<?php

/**
 * Request-reference / idempotency-key helpers.
 *
 * @package TaniKyuun\MayaGateway\Util
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Util;

/**
 * Builders for Maya's `requestReferenceNumber` field.
 *
 * Maya treats `requestReferenceNumber` as the merchant-side correlation id —
 * payments under the same RRN are grouped (auth → capture → refund). For
 * real orders that's the WC order id; for diagnostic calls (Test connection)
 * we use a prefixed token so the dashboard noise stays identifiable and
 * isolated.
 *
 * Maya caps `requestReferenceNumber` at 36 characters (per the API
 * validation error `code 2553`). The test-connection builder honors that
 * cap — a UUID is 36 chars on its own, so we strip the hyphens and slice
 * the remaining budget after the prefix.
 */
class IdempotencyKey
{
    public const TEST_PREFIX          = 'wc-maya-test-';
    public const MAX_REFERENCE_LENGTH = 36;

    public static function uuid(): string
    {
        return wp_generate_uuid4();
    }

    public static function for_order(int $order_id): string
    {
        return (string) $order_id;
    }

    public static function for_test_connection(): string
    {
        $hex           = str_replace('-', '', self::uuid());
        $suffix_length = max(1, self::MAX_REFERENCE_LENGTH - strlen(self::TEST_PREFIX));

        return self::TEST_PREFIX . substr($hex, 0, $suffix_length);
    }
}
