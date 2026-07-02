<?php

/**
 * Composes the createCheckout payload, calls Maya, persists meta on the order.
 *
 * @package RogueTechPhilippines\MayaGateway\Gateway
 */

declare(strict_types=1);

namespace RogueTechPhilippines\MayaGateway\Gateway;

use RogueTechPhilippines\MayaGateway\Api\Endpoints\Checkouts;
use RogueTechPhilippines\MayaGateway\Settings\SettingsHelper;
use RogueTechPhilippines\MayaGateway\Util\IdempotencyKey;
use RogueTechPhilippines\MayaGateway\Util\Logger;
use RogueTechPhilippines\MayaGateway\Value\AuthorizationType;
use RogueTechPhilippines\MayaGateway\Value\Money;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;

/**
 * Real-checkout creation: gather every field Maya expects, fire one
 * `POST /checkout/v1/checkouts`, persist the returned ids on the order so
 * the webhook and capture flows can find them later, and return the
 * WC-shaped `[result, redirect]` tuple to the gateway.
 *
 * Stateless — every call takes the order it operates on; the gateway
 * constructs a fresh processor per `process_payment()`.
 */
class PaymentProcessor
{
    /**
     * User-meta key the customer's date of birth is stored under. Matches the
     * site's registration / account flow (the theme's
     * `DateOfBirthValidator::META_KEY`). Referenced as a literal here rather
     * than importing the theme class so the gateway stays decoupled — the
     * `wc_maya_buyer_birthday` filter is the supported override point.
     */
    public const BUYER_BIRTHDAY_META_KEY = 'date_of_birth';

    public function __construct(
        private readonly Checkouts $endpoint,
        private readonly SettingsHelper $settings,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array{result: 'success', redirect: string}|array{result: 'failure'}
     */
    public function process(WC_Order $order): array
    {
        $reference     = IdempotencyKey::for_order((int) $order->get_id());
        $authorization = $this->settings->manual_capture();
        $payload       = self::build_payload(
            $order,
            $reference,
            $this->settings->return_url((int) $order->get_id()),
            $authorization,
            self::resolve_buyer_birthday($order),
        );

        $session = $this->endpoint->create($payload);

        if ($session instanceof WP_Error) {
            $this->logger->error('PaymentProcessor: createCheckout failed.', [
                'order_id' => $order->get_id(),
                'code'     => $session->get_error_code(),
                'message'  => $session->get_error_message(),
            ]);
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: Maya error message. */
                        __('Unable to start the Maya payment: %s', 'wc-maya-gateway'),
                        $session->get_error_message(),
                    ),
                    'error',
                );
            }
            return [ 'result' => 'failure' ];
        }

        $order->update_meta_data(MayaGateway::META_CHECKOUT_ID, $session->checkout_id);
        $order->update_meta_data(MayaGateway::META_IDEMPOTENCY_KEY, $reference);
        $order->update_meta_data(MayaGateway::META_AUTHORIZATION_TYPE, $authorization->value);
        $order->save();

        $this->logger->info('PaymentProcessor: checkout session created.', [
            'order_id'    => $order->get_id(),
            'checkout_id' => $session->checkout_id,
        ]);

        return [
            'result'   => 'success',
            'redirect' => $session->redirect_url,
        ];
    }

    /**
     * Build the createCheckout payload for a real WC order.
     *
     * Pure function — no Maya call, no settings access — so the shape can
     * be pinned with unit tests against a mocked WC_Order.
     *
     * When `$authorization` is anything other than `None`, an
     * `authorizationType` field is added so Maya holds the funds instead of
     * capturing immediately. The merchant captures via the order edit screen
     * later (Phase 5's CapturePanel).
     *
     * `$birthday` (YYYY-MM-DD) is added to the buyer object as Maya's
     * `buyer.birthday` field when present and well-formed; a malformed value
     * is dropped rather than risking a Maya validation rejection on the whole
     * checkout.
     *
     * @return array<string,mixed>
     */
    public static function build_payload(
        WC_Order $order,
        string $reference,
        string $return_url_base,
        AuthorizationType $authorization = AuthorizationType::None,
        string $birthday = '',
    ): array {
        $total    = new Money((float) $order->get_total(), (string) $order->get_currency());
        $shipping = self::shipping_address($order);

        $payload = [
            'totalAmount' => [
                'value'    => $total->value,
                'currency' => $total->currency,
                'details'  => [
                    'discount'    => (float) $order->get_discount_total(),
                    'shippingFee' => (float) $order->get_shipping_total(),
                    'subtotal'    => (float) $order->get_subtotal(),
                ],
            ],
            'buyer' => [
                'firstName' => (string) $order->get_billing_first_name(),
                'lastName'  => (string) $order->get_billing_last_name(),
                'contact'   => [
                    'phone' => (string) $order->get_billing_phone(),
                    'email' => (string) $order->get_billing_email(),
                ],
                'shippingAddress' => $shipping + [
                    'phone'        => (string) $order->get_billing_phone(),
                    'email'        => (string) $order->get_billing_email(),
                    'shippingType' => 'ST',
                ],
                'billingAddress' => [
                    'line1'       => (string) $order->get_billing_address_1(),
                    'line2'       => (string) $order->get_billing_address_2(),
                    'city'        => (string) $order->get_billing_city(),
                    'state'       => (string) $order->get_billing_state(),
                    'zipCode'     => (string) $order->get_billing_postcode(),
                    'countryCode' => (string) $order->get_billing_country(),
                ],
            ],
            'items'       => self::line_items($order),
            'redirectUrl' => [
                'success' => $return_url_base . '&status=success',
                'failure' => $return_url_base . '&status=failed',
                'cancel'  => (string) $order->get_checkout_payment_url(),
            ],
            'requestReferenceNumber' => $reference,
        ];

        if ('' !== $birthday && self::is_valid_birthday($birthday)) {
            $payload['buyer']['birthday'] = $birthday;
        }

        if ($authorization->is_manual_capture()) {
            $payload['authorizationType'] = $authorization->for_maya_api();
        }

        return $payload;
    }

    /**
     * Resolve the buyer's date of birth (YYYY-MM-DD) for Maya's
     * `buyer.birthday` field.
     *
     * Sourced from the customer's {@see BUYER_BIRTHDAY_META_KEY} user meta,
     * which the site's registration / account flow populates. Empty for guest
     * checkouts — there is no user account to read it from. The
     * `wc_maya_buyer_birthday` filter lets a site override the source (e.g. a
     * guest checkout field, or a different meta key).
     */
    private static function resolve_buyer_birthday(WC_Order $order): string
    {
        $birthday    = '';
        $customer_id = (int) $order->get_customer_id();

        if ($customer_id > 0 && function_exists('get_user_meta')) {
            $birthday = (string) get_user_meta($customer_id, self::BUYER_BIRTHDAY_META_KEY, true);
        }

        /**
         * Filter the buyer birthday (YYYY-MM-DD) sent to Maya.
         *
         * @param string   $birthday Resolved DOB, '' when unavailable.
         * @param WC_Order $order    Order being paid.
         */
        return (string) apply_filters('wc_maya_buyer_birthday', $birthday, $order);
    }

    /**
     * Strict YYYY-MM-DD check (rejects 2026-13-01, 2026-02-30, 2026/02/03,
     * and any non-ISO shape) so a corrupt value can't fail the checkout.
     */
    private static function is_valid_birthday(string $value): bool
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @return array<string,string>
     */
    private static function shipping_address(WC_Order $order): array
    {
        $first   = (string) ($order->get_shipping_first_name() ?: $order->get_billing_first_name());
        $last    = (string) ($order->get_shipping_last_name() ?: $order->get_billing_last_name());
        $line1   = (string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1());
        $line2   = (string) ($order->get_shipping_address_2() ?: $order->get_billing_address_2());
        $city    = (string) ($order->get_shipping_city() ?: $order->get_billing_city());
        $zip     = (string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode());
        $country = (string) ($order->get_shipping_country() ?: $order->get_billing_country());

        return [
            'firstName'   => $first,
            'lastName'    => $last,
            'line1'       => $line1,
            'line2'       => $line2,
            'city'        => $city,
            'state'       => (string) $order->get_shipping_state(),
            'zipCode'     => $zip,
            'countryCode' => $country,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function line_items(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            if (! ($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $line_total = (float) $item->get_total();
            $quantity   = max(1, (int) $item->get_quantity());
            $items[]    = [
                'name'        => (string) $item->get_name(),
                'description' => (string) $item->get_name(),
                'quantity'    => $quantity,
                'code'        => (string) ($item->get_product_id() ?: '001'),
                // Maya's item schema expects `amount` as the per-unit price and
                // `totalAmount` as the line total. Derive the unit price from
                // the line total (rounded to 2 decimals); `totalAmount` and the
                // top-level `totalAmount` stay authoritative for the charge.
                'amount'      => [ 'value' => round($line_total / $quantity, 2) ],
                'totalAmount' => [ 'value' => $line_total ],
            ];
        }
        return $items;
    }
}
