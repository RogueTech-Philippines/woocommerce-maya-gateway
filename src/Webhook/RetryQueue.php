<?php

/**
 * Action Scheduler-backed retry queue for transient webhook failures.
 *
 * @package TaniKyuun\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Webhook;

use TaniKyuun\MayaGateway\Util\Logger;

/**
 * Re-enqueues a verified-but-undeliverable webhook payload for a later
 * processing attempt.
 *
 * Maya already retries 4 times on its side when our endpoint returns
 * non-2xx. This class is the *internal* safety net for the case where a
 * webhook arrives 200-acknowledged but the local processing failed
 * transiently — typically:
 *
 *  - the WC order isn't visible to `wc_get_order()` yet (rare DB lag
 *    between the customer return and the webhook),
 *  - a Maya lookup the {@see EventDispatcher::complete_manual_capture} branch
 *    needs (`Payments::get_by_rrn`) returned a transport error.
 *
 * Either is safe to retry: every downstream effect (order_complete, status
 * change, notes) is idempotent. We schedule a single follow-up via Action
 * Scheduler, capped at {@see MAX_ATTEMPTS} attempts so a permanently-broken
 * payload doesn't loop forever.
 *
 * Pure-static planning (`plan_delay`, `should_schedule`) so the policy is
 * unit-testable; the only WP touchpoints are the AS function calls in
 * `register()` and `schedule()`.
 */
final class RetryQueue
{
    public const ACTION_HOOK = 'wc_maya_replay_webhook';
    public const GROUP       = 'wc-maya-gateway';

    public const MAX_ATTEMPTS = 4;

    /**
     * Retryable dispatch `action` values from {@see EventDispatcher::dispatch}.
     * Anything not in this list is either a terminal state (payment_complete,
     * failed) or a definitive non-event we shouldn't bother retrying
     * (ignored, already_paid, amount_mismatch — that one needs human review).
     *
     * @var list<string>
     */
    public const RETRYABLE_ACTIONS = [
        'order_not_found',
        'manual_capture_lookup_failed',
        'manual_capture_lookup_unavailable',
    ];

    public static function register(): void
    {
        add_action(self::ACTION_HOOK, [ self::class, 'handle' ], 10, 1);
    }

    /**
     * Schedule a follow-up if `$dispatch` is retryable and we haven't hit
     * the attempt cap. Returns the AS action id (truthy = scheduled, 0 =
     * skipped — kept consumable for tests).
     *
     * @param array{action?: string, order_id?: int, payment_id?: string} $dispatch
     * @param array<string,mixed>                                         $payload
     */
    public static function maybe_schedule(array $dispatch, array $payload, int $attempt, Logger $logger): int
    {
        if (! self::should_schedule($dispatch, $attempt)) {
            return 0;
        }

        return self::schedule($payload, $attempt + 1, self::plan_delay($attempt + 1), $logger);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function schedule(array $payload, int $attempt, int $delay_seconds, Logger $logger): int
    {
        if (! function_exists('as_schedule_single_action')) {
            $logger->warning('RetryQueue: Action Scheduler not available; skipping retry schedule.');
            return 0;
        }

        $when = time() + max($delay_seconds, 1);
        $args = [
            [
                'payload' => $payload,
                'attempt' => $attempt,
            ],
        ];

        $id = (int) as_schedule_single_action($when, self::ACTION_HOOK, $args, self::GROUP);

        $logger->info('RetryQueue: webhook replay scheduled.', [
            'action_id'     => $id,
            'attempt'       => $attempt,
            'delay_seconds' => $delay_seconds,
            'reference'     => (string) ($payload['requestReferenceNumber'] ?? ''),
        ]);

        return $id;
    }

    /**
     * Action Scheduler callback. We rebuild the dispatcher chain inside
     * {@see WebhookHandler::process()} but with a `replay=true` body marker
     * so the receiver knows to bypass signature checks (this payload was
     * verified once already — the original POST is gone).
     *
     * @param array{payload?: array<string,mixed>, attempt?: int} $args
     */
    public static function handle(array $args): void
    {
        $payload = isset($args['payload']) && is_array($args['payload']) ? $args['payload'] : [];
        $attempt = isset($args['attempt']) ? (int) $args['attempt'] : 1;

        $settings = self::load_runtime_settings();
        $logger   = new Logger($settings['debug_log']);

        $logger->info('RetryQueue: replaying webhook.', [
            'attempt'   => $attempt,
            'reference' => (string) ($payload['requestReferenceNumber'] ?? ''),
        ]);

        // Skip verification — the payload was verified on the original
        // delivery; the only purpose of the replay is to re-run dispatch
        // now that whatever transient blocker (DB lag, lookup failure) has
        // had time to resolve.
        $event_name = self::extract_event_name($payload);
        $event      = \TaniKyuun\MayaGateway\Value\WebhookEvent::try_from_string($event_name);

        if (null === $event) {
            $logger->warning('RetryQueue: replay payload has no recognizable event; dropping.', [
                'event_raw' => $event_name,
            ]);
            return;
        }

        $dispatcher = new EventDispatcher(
            $logger,
            self::build_payments_endpoint($settings['is_sandbox'], $logger),
        );

        $dispatch = $dispatcher->dispatch($event, $payload);

        $next_id = self::maybe_schedule($dispatch, $payload, $attempt, $logger);
        if (0 === $next_id) {
            $logger->info('RetryQueue: replay terminal (no further retry).', [
                'attempt'         => $attempt,
                'dispatch_action' => $dispatch['action'] ?? 'unknown',
            ]);
        }
    }

    /**
     * @param array{action?: string} $dispatch
     */
    public static function should_schedule(array $dispatch, int $attempt): bool
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return false;
        }

        $action = isset($dispatch['action']) && is_string($dispatch['action']) ? $dispatch['action'] : '';
        return in_array($action, self::RETRYABLE_ACTIONS, true);
    }

    /**
     * Exponential backoff with a hard floor of 60s — the first retry
     * happens after a minute, then 4 min, 16 min, 64 min, capped by
     * MAX_ATTEMPTS.
     */
    public static function plan_delay(int $attempt): int
    {
        $minutes = (int) (60 * (4 ** max($attempt - 1, 0)));
        return max($minutes, 60);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function extract_event_name(array $payload): string
    {
        foreach ([ 'status', 'paymentStatus', 'name' ] as $key) {
            if (isset($payload[ $key ]) && is_string($payload[ $key ])) {
                return $payload[ $key ];
            }
        }
        return '';
    }

    /**
     * @return array{is_sandbox: bool, debug_log: bool}
     */
    private static function load_runtime_settings(): array
    {
        $option = get_option('woocommerce_' . \TaniKyuun\MayaGateway\Gateway\MayaGateway::ID . '_settings', []);
        if (! is_array($option)) {
            $option = [];
        }

        return [
            'is_sandbox' => 'yes' === ($option['is_sandbox'] ?? 'yes'),
            'debug_log'  => 'yes' === ($option['debug_log'] ?? 'no'),
        ];
    }

    private static function build_payments_endpoint(bool $is_sandbox, Logger $logger): \TaniKyuun\MayaGateway\Api\Endpoints\Payments
    {
        $option = get_option('woocommerce_' . \TaniKyuun\MayaGateway\Gateway\MayaGateway::ID . '_settings', []);
        if (! is_array($option)) {
            $option = [];
        }

        return new \TaniKyuun\MayaGateway\Api\Endpoints\Payments(
            new \TaniKyuun\MayaGateway\Api\MayaApiClient(
                (string) ($option['public_key'] ?? ''),
                (string) ($option['secret_key'] ?? ''),
                $is_sandbox,
                $logger,
            ),
        );
    }
}
