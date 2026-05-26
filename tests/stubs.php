<?php

/**
 * Minimal stubs for WordPress classes that Brain Monkey doesn't auto-mock.
 *
 * @package TaniKyuun\MayaGateway\Tests
 */

declare(strict_types=1);

if (! class_exists('WP_Error')) {
    /**
     * Bare minimum WP_Error stub for unit tests.
     */
    class WP_Error
    { // phpcs:ignore

        /** @var string|int */
        public string|int $code;

        public string $message;

        public mixed $data;

        /**
         * Constructor.
         *
         * @param string|int $code    Error code.
         * @param string     $message Error message.
         * @param mixed      $data    Optional error data.
         */
        public function __construct(string|int $code = '', string $message = '', mixed $data = null)
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string|int
        {
            return $this->code;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! class_exists('WC_Order')) {
    /**
     * Skeleton WC_Order — real expectations are set per-test with Mockery.
     */
    class WC_Order
    { // phpcs:ignore
    }
}

if (! class_exists('WC_Order_Item_Product')) {
    /**
     * Skeleton WC_Order_Item_Product — instanceof check needs the class to exist.
     */
    class WC_Order_Item_Product
    { // phpcs:ignore
    }
}

if (! class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry')) {
    /**
     * Skeleton PaymentMethodRegistry — the real class lives in WC core and
     * isn't loaded for unit tests. We only need an `instanceof`-able shape
     * with a `register()` method so MayaBlocksPaymentMethod compiles + tests
     * can assert it was called.
     */
    class FakePaymentMethodRegistry
    { // phpcs:ignore

        /** @var array<int,object> */
        public array $registered = [];

        public function register(object $payment_method): void
        {
            $this->registered[] = $payment_method;
        }
    }

    class_alias(
        FakePaymentMethodRegistry::class,
        'Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry',
    );
}

if (! interface_exists('Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodTypeInterface')) {
    interface FakePaymentMethodTypeInterface
    { // phpcs:ignore

        public function get_name();
    }

    class_alias(
        FakePaymentMethodTypeInterface::class,
        'Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodTypeInterface',
    );
}

if (! class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    /**
     * Skeleton AbstractPaymentMethodType — minimal shape from WC's real
     * class. Concrete plugin subclasses can extend and override; tests
     * cover those overrides directly without the WC Blocks package.
     */
    abstract class FakeAbstractPaymentMethodType implements \Automattic\WooCommerce\Blocks\Payments\PaymentMethodTypeInterface
    { // phpcs:ignore

        /** @var string */
        protected $name = '';

        /** @var array<string,mixed> */
        protected $settings = [];

        public function get_name()
        {
            return $this->name;
        }

        public function is_active()
        {
            return true;
        }

        public function get_payment_method_script_handles()
        {
            return [];
        }

        public function get_payment_method_data()
        {
            return [];
        }

        public function get_supported_features()
        {
            return [ 'products' ];
        }

        protected function get_setting($name, $default = '')
        {
            return $this->settings[ $name ] ?? $default;
        }
    }

    class_alias(
        FakeAbstractPaymentMethodType::class,
        'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType',
    );
}

if (! class_exists('WC_Payment_Gateway')) {
    /**
     * Skeleton WC_Payment_Gateway so the gateway class can be loaded.
     */
    class WC_Payment_Gateway
    { // phpcs:ignore

        /** @var array<string,mixed> */
        public array $form_fields = [];

        public string $id                 = '';
        public string $title              = '';
        public string $description        = '';
        public string $method_title       = '';
        public string $method_description = '';
        public bool   $has_fields         = false;

        /** @var array<int,string> */
        public array $supports = [];

        /** @var array<string,mixed> */
        protected array $settings = [];

        public function init_settings(): void {}
        public function process_admin_options(): bool
        {
            return true;
        }

        /**
         * @param string $key     Option key.
         * @param mixed  $default Default value.
         */
        public function get_option(string $key, mixed $default = ''): mixed
        {
            return $this->settings[ $key ] ?? $default;
        }

        public function get_description(): string
        {
            return $this->description;
        }

        public function get_return_url(mixed $order = null): string
        {
            return 'https://example.test/return';
        }
    }
}
