<?php

/**
 * AJAX handler for the Test Connection button.
 *
 * @package TaniKyuun\MayaGateway\Admin\Ajax
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin\Ajax;

use TaniKyuun\MayaGateway\Admin\AdminAssets;
use TaniKyuun\MayaGateway\Api\Endpoints\Checkouts;
use TaniKyuun\MayaGateway\Api\Endpoints\Webhooks;
use TaniKyuun\MayaGateway\Api\MayaApiClient;
use TaniKyuun\MayaGateway\Gateway\MayaGateway;
use TaniKyuun\MayaGateway\Util\IdempotencyKey;
use TaniKyuun\MayaGateway\Util\Logger;
use TaniKyuun\MayaGateway\Value\Money;
use WP_Error;

/**
 * Runs per-key probes and returns the structured result.
 *
 * Each probe exercises the same call WooCommerce makes in production:
 *
 * - Public key → POST /checkout/v1/checkouts (real createCheckout; no
 *   buyer ever visits the returned redirectUrl, so no charge occurs).
 * - Secret key → GET /checkout/v1/webhooks (lists the merchant's
 *   registered webhooks).
 *
 * Honors unsaved field values from POST so the user can verify credentials
 * before saving the form.
 */
class TestConnection
{
    public const ACTION               = 'wc_maya_test_connection';
    public const TEST_AMOUNT          = 100.0;
    public const TEST_CURRENCY        = 'PHP';
    public const TEST_METADATA_SOURCE = 'wc-maya-gateway-test-connection';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [ self::class, 'handle' ]);
    }

    public static function handle(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error([ 'message' => __('Insufficient permissions.', 'wc-maya-gateway') ], 403);
        }

        check_ajax_referer(AdminAssets::NONCE_ACTION, 'nonce');

        $public_key = isset($_POST['public_key']) ? trim((string) wp_unslash($_POST['public_key'])) : '';
        $secret_key = isset($_POST['secret_key']) ? trim((string) wp_unslash($_POST['secret_key'])) : '';
        $is_sandbox = isset($_POST['is_sandbox']) && 'yes' === wp_unslash($_POST['is_sandbox']);
        $debug_log  = isset($_POST['debug_log'])  && 'yes' === wp_unslash($_POST['debug_log']);

        if ('' !== $public_key && '' !== $secret_key) {
            $client = new MayaApiClient($public_key, $secret_key, $is_sandbox, new Logger($debug_log));
        } else {
            $gateway = self::find_gateway();
            if (null === $gateway) {
                wp_send_json_error([ 'message' => __('Maya gateway not registered.', 'wc-maya-gateway') ], 500);
                return;
            }
            $client = $gateway->build_api_client();
        }

        wp_send_json_success(
            [
                'public_key'  => self::probe_public_key($client),
                'secret_key'  => self::probe_secret_key($client),
                'environment' => $client->is_sandbox() ? 'sandbox' : 'production',
            ],
        );
    }

    /**
     * Build the minimal, harmless createCheckout payload used by the
     * public-key probe.
     *
     * Pure function — no Maya call, no WC dependency — so the payload shape
     * (totalAmount conformance to Money, redirectUrl symmetry, metadata
     * tagging) is directly unit-testable.
     *
     * @return array<string,mixed>
     */
    public static function build_test_checkout_payload(string $reference, string $return_url): array
    {
        $amount = new Money(self::TEST_AMOUNT, self::TEST_CURRENCY);

        return [
            'totalAmount'            => $amount->to_array(),
            'requestReferenceNumber' => $reference,
            'redirectUrl'            => [
                'success' => $return_url,
                'failure' => $return_url,
                'cancel'  => $return_url,
            ],
            'metadata' => [
                'source' => self::TEST_METADATA_SOURCE,
            ],
        ];
    }

    /**
     * @return array{ok: bool, message?: string, code?: string|int, checkoutId?: string, reference?: string}
     */
    private static function probe_public_key(MayaApiClient $client): array
    {
        $reference = IdempotencyKey::for_test_connection();
        $payload   = self::build_test_checkout_payload($reference, home_url('/'));

        $response = (new Checkouts($client))->create($payload);

        if ($response instanceof WP_Error) {
            return self::error_to_array($response);
        }

        return [
            'ok'         => true,
            'checkoutId' => $response->checkout_id,
            'reference'  => $reference,
        ];
    }

    /**
     * @return array{ok: bool, message?: string, code?: string|int, webhookCount?: int}
     */
    private static function probe_secret_key(MayaApiClient $client): array
    {
        $response = (new Webhooks($client))->all();

        if ($response instanceof WP_Error) {
            return self::error_to_array($response);
        }

        return [
            'ok'           => true,
            'webhookCount' => count($response),
        ];
    }

    /**
     * @return array{ok: false, message: string, code: string|int}
     */
    private static function error_to_array(WP_Error $error): array
    {
        return [
            'ok'      => false,
            'message' => $error->get_error_message(),
            'code'    => $error->get_error_code(),
        ];
    }

    private static function find_gateway(): ?MayaGateway
    {
        if (! function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways[ MayaGateway::ID ] ?? null;

        return $gateway instanceof MayaGateway ? $gateway : null;
    }
}
