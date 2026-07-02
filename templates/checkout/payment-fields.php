<?php
/**
 * Payment description rendered at WooCommerce checkout.
 *
 * @package RogueTechPhilippines\MayaGateway
 *
 * @var string $description Payment method description, escaped on output.
 */

defined('ABSPATH') || exit;
?>

<?php if (! empty($description)) : ?>
	<p class="maya-gateway-description">
		<?php echo wp_kses_post($description); ?>
	</p>
<?php endif; ?>
