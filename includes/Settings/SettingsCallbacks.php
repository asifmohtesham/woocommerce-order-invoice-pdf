<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsCallbacks' ) ) :

class SettingsCallbacks {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Sanitize/validate settings before saving.
	 */
	public function validate( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		return array_map( 'sanitize_text_field', $settings );
	}

	/**
	 * Render a settings section.
	 */
	public function section( $args = array() ) {
		// intentionally empty — sections have no output by default
	}

	/**
	 * Render a media upload field.
	 */
	public function media_upload( $args = array() ) {
		$attachment_id = ! empty( $args['current'] ) ? absint( $args['current'] ) : 0;
		$name          = ! empty( $args['name'] ) ? esc_attr( $args['name'] ) : '';
		$value         = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		?>
		<input type="hidden" name="<?php echo $name; ?>" value="<?php echo esc_attr( $attachment_id ); ?>" />
		<?php if ( $value ) : ?>
			<img src="<?php echo esc_url( $value ); ?>" style="max-height:60px;" />
		<?php endif; ?>
		<button class="button"><?php esc_html_e( 'Select image', 'woocommerce-orders-invoice-pdf' ); ?></button>
		<?php
	}

	/**
	 * Render a text input field.
	 */
	public function text_element( $args = array() ) {
		$option_name = ! empty( $args['option_name'] ) ? $args['option_name'] : '';
		$id          = ! empty( $args['id'] ) ? $args['id'] : '';
		$value       = ! empty( $args['current'] ) ? $args['current'] : '';
		?>
		<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Render a textarea field.
	 */
	public function textarea_element( $args = array() ) {
		$option_name = ! empty( $args['option_name'] ) ? $args['option_name'] : '';
		$id          = ! empty( $args['id'] ) ? $args['id'] : '';
		$value       = ! empty( $args['current'] ) ? $args['current'] : '';
		?>
		<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]" rows="4" cols="50"><?php echo esc_textarea( $value ); ?></textarea>
		<?php
	}

	/**
	 * Render a checkbox field.
	 */
	public function checkbox( $args = array() ) {
		$option_name = ! empty( $args['option_name'] ) ? $args['option_name'] : '';
		$id          = ! empty( $args['id'] ) ? $args['id'] : '';
		$checked     = ! empty( $args['current'] );
		?>
		<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $checked ); ?> />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo wp_kses_post( $args['description'] ); ?></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select dropdown field.
	 */
	public function select( $args = array() ) {
		$option_name = ! empty( $args['option_name'] ) ? $args['option_name'] : '';
		$id          = ! empty( $args['id'] ) ? $args['id'] : '';
		$current     = ! empty( $args['current'] ) ? $args['current'] : '';
		$options     = ! empty( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render a singular text element (stored as its own option key).
	 */
	public function singular_text_element( $args = array() ) {
		$option_name = ! empty( $args['option_name'] ) ? $args['option_name'] : '';
		$value       = get_option( $option_name, '' );
		?>
		<input type="text" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<?php
	}
}

endif;
