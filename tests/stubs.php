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
