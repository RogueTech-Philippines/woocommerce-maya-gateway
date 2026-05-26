/**
 * Maya Checkout — block-based Cart / Checkout payment method.
 *
 * Hosted-checkout product: no card / wallet input lives here. The block
 * renders the merchant-configured title + description; the customer is
 * redirected to Maya's hosted page after clicking "Place order".
 *
 * The data bag (title, description, icon, supports) is shipped to the
 * client by `MayaBlocksPaymentMethod::get_payment_method_data()` and read
 * here via `wc.wcSettings.getPaymentMethodData`.
 */
( function ( wp, wc ) {
    'use strict';

    if ( ! wp || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
        return;
    }

    var createElement = wp.element.createElement;
    var decodeEntities = wp.htmlEntities.decodeEntities;
    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var settings = wc.wcSettings.getPaymentMethodData( 'maya_checkout', {} );

    var defaultLabel = ( wp.i18n && wp.i18n.__ )
        ? wp.i18n.__( 'Maya', 'wc-maya-gateway' )
        : 'Maya';
    var label = decodeEntities( settings.title || '' ) || defaultLabel;

    /**
     * Body shown when the customer has Maya selected. Falls back to a short
     * default sentence when the merchant has wiped the gateway description.
     */
    function Content() {
        var defaultDescription = ( wp.i18n && wp.i18n.__ )
            ? wp.i18n.__( 'You will be redirected to Maya to complete your payment.', 'wc-maya-gateway' )
            : 'You will be redirected to Maya to complete your payment.';
        var description = decodeEntities( settings.description || '' ) || defaultDescription;

        return createElement(
            'div',
            { className: 'wc-maya-blocks-description' },
            description,
        );
    }

    /**
     * Tab/list label. Uses the PaymentMethodLabel component the Blocks
     * runtime hands us so the icon prop (if present) renders inline.
     */
    function Label( props ) {
        var PaymentMethodLabel = props.components.PaymentMethodLabel;
        var labelProps = { text: label };

        if ( settings.icon ) {
            labelProps.icon = settings.icon;
        }

        return createElement( PaymentMethodLabel, labelProps );
    }

    registerPaymentMethod( {
        name: 'maya_checkout',
        label: createElement( Label, null ),
        content: createElement( Content, null ),
        edit: createElement( Content, null ),
        canMakePayment: function () {
            return true;
        },
        ariaLabel: label,
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );
}( window.wp || {}, window.wc || {} ) );
