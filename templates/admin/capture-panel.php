<?php

/**
 * Capture-panel template, rendered by CapturePanel under the order totals.
 *
 * Vars in scope:
 *  - WC_Order $order
 *  - AuthorizationType $auth_type
 *  - PaymentRecord $payment
 *  - Money $authorized
 *  - Money $captured
 *  - Money $remaining
 */

defined('ABSPATH') || exit;
?>
<tr id="wc-maya-capture-panel" class="wc-maya-capture-panel" data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>" data-payment-id="<?php echo esc_attr($payment->id); ?>" data-currency="<?php echo esc_attr($authorized->currency); ?>" hidden>
    <td class="label" colspan="2"><?php esc_html_e('Maya capture', 'wc-maya-gateway'); ?>:</td>
    <td class="total">
        <div class="wc-maya-capture-balances">
            <p>
                <strong><?php esc_html_e('Authorization:', 'wc-maya-gateway'); ?></strong>
                <code><?php echo esc_html($auth_type->value); ?></code>
            </p>
            <p>
                <?php esc_html_e('Authorized:', 'wc-maya-gateway'); ?>
                <span class="wc-maya-amount-authorized"><?php echo esc_html(number_format($authorized->value, 2)); ?></span>
                <?php esc_html_e('Captured:', 'wc-maya-gateway'); ?>
                <span class="wc-maya-amount-captured"><?php echo esc_html(number_format($captured->value, 2)); ?></span>
                <?php esc_html_e('Remaining:', 'wc-maya-gateway'); ?>
                <span class="wc-maya-amount-remaining"><?php echo esc_html(number_format($remaining->value, 2)); ?></span>
            </p>
        </div>
        <div class="wc-maya-capture-form">
            <label for="wc-maya-capture-amount">
                <?php esc_html_e('Amount to capture:', 'wc-maya-gateway'); ?>
            </label>
            <input
                type="number"
                step="0.01"
                min="0.01"
                max="<?php echo esc_attr((string) $remaining->value); ?>"
                value="<?php echo esc_attr((string) $remaining->value); ?>"
                id="wc-maya-capture-amount"
                class="wc-input-table small-text"
            />
            <button type="button" class="button button-primary" id="wc-maya-capture-submit">
                <?php esc_html_e('Capture', 'wc-maya-gateway'); ?>
            </button>
            <span class="spinner" id="wc-maya-capture-spinner"></span>
        </div>
        <div class="wc-maya-capture-result" id="wc-maya-capture-result" aria-live="polite"></div>
        <p class="description">
            <?php esc_html_e('Final order completion still waits for the Maya PAYMENT_SUCCESS webhook — this just posts the capture call. Partial captures update the remaining balance; capturing the full remainder triggers the order to complete once the webhook arrives.', 'wc-maya-gateway'); ?>
        </p>
    </td>
</tr>
