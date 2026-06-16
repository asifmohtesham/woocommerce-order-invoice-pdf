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
		return $this->sanitize_recursive( $settings );
	}

	private function sanitize_recursive( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ sanitize_key( $key ) ] = $this->sanitize_recursive( $value );
			} else {
				$out[ sanitize_key( $key ) ] = wp_kses_post( $value );
			}
		}
		return $out;
	}

	/**
	 * Render a settings section header (intentionally empty — title already shown by WP).
	 */
	public function section( $args = array() ) {}

	/**
	 * Render raw HTML — used when a section needs custom markup.
	 */
	public function html_section( $args = array() ) {
		if ( ! empty( $args['html'] ) ) {
			echo wp_kses_post( $args['html'] );
		}
	}

	/**
	 * Render a media upload (logo) field.
	 */
	public function media_upload( $args = array() ) {
		$option_name   = $args['option_name'] ?? '';
		$id            = $args['id'] ?? '';
		$settings      = get_option( $option_name, array() );
		// Honour a freshly picked attachment passed by the AJAX re-render ($args['current'])
		// so the selection survives the HTML regeneration that happens before saving.
		$attachment_id = ! empty( $args['current'] )
			? absint( $args['current'] )
			: ( isset( $settings[ $id ] ) ? absint( $settings[ $id ] ) : 0 );
		$height_id     = ! empty( $args['height_id'] ) ? $args['height_id'] : ( $id . '_height' );
		$height        = $settings[ $height_id ] ?? '';
		$setting_name  = $option_name . '[' . $id . ']';

		$uploader_title       = __( 'Select image', 'woocommerce-orders-invoice-pdf' );
		$uploader_button_text = __( 'Use image', 'woocommerce-orders-invoice-pdf' );
		$remove_button_text   = __( 'Remove image', 'woocommerce-orders-invoice-pdf' );

		if ( $attachment_id ) {
			$attachment = wp_get_attachment_image_src( $attachment_id, 'full', false );
			if ( $attachment ) {
				printf(
					'<img src="%1$s" style="display:block" id="img-%2$s" class="media-upload-preview"/>',
					esc_attr( $attachment[0] ),
					esc_attr( $id )
				);
			}
			printf(
				'<span class="button wpo_remove_image_button" data-input_id="%1$s">%2$s</span> ',
				esc_attr( $id ),
				esc_html( $remove_button_text )
			);
		}

		printf(
			'<input id="%1$s" name="%2$s" type="hidden" value="%3$s" data-settings_callback_args="%4$s" data-ajax_nonce="%5$s" class="media-upload-id"/>',
			esc_attr( $id ),
			esc_attr( $setting_name ),
			esc_attr( $attachment_id ),
			esc_attr( wp_json_encode( $args ) ),
			esc_attr( wp_create_nonce( 'woi_pdf_get_media_upload_setting_html' ) )
		);

		printf(
			'<span class="button wpo_upload_image_button %4$s" data-uploader_title="%1$s" data-uploader_button_text="%2$s" data-remove_button_text="%3$s" data-input_id="%4$s">%2$s</span>',
			esc_attr( $uploader_title ),
			esc_attr( $uploader_button_text ),
			esc_attr( $remove_button_text ),
			esc_attr( $id )
		);

		if ( ! empty( $args['height_id'] ) ) {
			echo '<p>';
			printf(
				'<label for="%1$s">%2$s</label> <input type="text" id="%1$s" name="%3$s[%1$s]" value="%4$s" size="4"/>',
				esc_attr( $height_id ),
				esc_html__( 'Max height (e.g. 3cm, 25mm)', 'woocommerce-orders-invoice-pdf' ),
				esc_attr( $option_name ),
				esc_attr( $height )
			);
			echo '</p>';
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $args['description'] ) );
		}
	}

	/**
	 * Render a text input field.
	 */
	public function text_element( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$settings    = get_option( $option_name, array() );
		$value       = $settings[ $id ] ?? ( $args['default'] ?? '' );
		$size        = $args['size'] ?? 'regular';
		?>
		<input type="text"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="<?php echo esc_attr( $size ); ?>-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a textarea field.
	 */
	public function textarea_element( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$settings    = get_option( $option_name, array() );
		$value       = $settings[ $id ] ?? ( $args['default'] ?? '' );
		?>
		<textarea
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
			rows="<?php echo esc_attr( $args['rows'] ?? 4 ); ?>"
			cols="50"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a checkbox field.
	 */
	public function checkbox( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$settings    = get_option( $option_name, array() );
		$default     = isset( $args['default'] ) ? (bool) $args['default'] : false;
		$store_unchecked = ! empty( $args['store_unchecked'] );

		if ( $store_unchecked ) {
			// When store_unchecked is set, the option is stored as 1 (checked) or 0 (unchecked).
			$checked = isset( $settings[ $id ] ) ? (bool) $settings[ $id ] : $default;
		} else {
			$checked = isset( $settings[ $id ] ) ? (bool) $settings[ $id ] : $default;
		}
		?>
		<input type="checkbox"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
			value="1"
			<?php checked( $checked ); ?> />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo wp_kses_post( $args['description'] ); ?></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a checkbox with an associated text input shown when checked.
	 */
	public function checkbox_text_input( $args = array() ) {
		$option_name      = $args['option_name'] ?? '';
		$id               = $args['id'] ?? '';
		$text_input_id    = $args['text_input_id'] ?? ( $id . '_value' );
		$text_input_wrap  = $args['text_input_wrap'] ?? '%s';
		$text_input_size  = absint( $args['text_input_size'] ?? 5 );
		$text_input_default = $args['text_input_default'] ?? '';

		$settings  = get_option( $option_name, array() );
		$checked   = ! empty( $settings[ $id ] );
		$text_val  = $settings[ $text_input_id ] ?? $text_input_default;

		$text_input_html = sprintf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" size="%d" style="width:4em;" />',
			esc_attr( $text_input_id ),
			esc_attr( $option_name ),
			esc_attr( $text_input_id ),
			esc_attr( $text_val ),
			$text_input_size
		);
		?>
		<input type="checkbox"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
			value="1"
			<?php checked( $checked ); ?> />
		<?php echo wp_kses( sprintf( $text_input_wrap, $text_input_html ), array( 'input' => array( 'type' => true, 'id' => true, 'name' => true, 'value' => true, 'size' => true, 'style' => true ) ) ); ?>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select dropdown. Supports options_callback, multiple, enhanced_select.
	 */
	public function select( $args = array() ) {
		$option_name      = $args['option_name'] ?? '';
		$id               = $args['id'] ?? '';
		$settings         = get_option( $option_name, array() );
		$current          = $settings[ $id ] ?? ( $args['default'] ?? '' );
		$options          = $args['options'] ?? array();
		$options_callback = $args['options_callback'] ?? '';
		$multiple         = ! empty( $args['multiple'] );

		if ( empty( $options ) && ! empty( $options_callback ) && is_callable( $options_callback ) ) {
			$options = call_user_func( $options_callback );
		}

		$current_arr = is_array( $current ) ? array_map( 'strval', $current ) : array( (string) $current );
		$name        = $multiple
			? esc_attr( $option_name ) . '[' . esc_attr( $id ) . '][]'
			: esc_attr( $option_name ) . '[' . esc_attr( $id ) . ']';
		?>
		<select id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo $name; // already escaped ?>"
			<?php echo $multiple ? 'multiple="multiple" style="height:auto;"' : ''; ?>>
			<?php foreach ( $options as $key => $label ) :
				$selected = in_array( (string) $key, $current_arr, true ) ? ' selected="selected"' : '';
			?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php echo $selected; ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a group of checkboxes. options_callback returns ['id' => 'label'] pairs.
	 */
	public function multiple_checkboxes( $args = array() ) {
		$option_name     = $args['option_name'] ?? '';
		$id              = $args['id'] ?? '';
		$settings        = get_option( $option_name, array() );
		$current         = isset( $settings[ $id ] ) && is_array( $settings[ $id ] ) ? $settings[ $id ] : array();
		$fields_callback = $args['fields_callback'] ?? '';
		$options         = $args['options'] ?? array();

		if ( empty( $options ) && ! empty( $fields_callback ) && is_callable( $fields_callback ) ) {
			$options = call_user_func( $fields_callback );
		}

		foreach ( $options as $key => $label ) :
			$cb_id   = $option_name . '_' . $id . '_' . $key;
			$checked = isset( $current[ $key ] ) || in_array( $key, $current, true );
			?>
			<input type="checkbox"
				id="<?php echo esc_attr( $cb_id ); ?>"
				name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>][<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( $checked ); ?> />
			<label for="<?php echo esc_attr( $cb_id ); ?>"><?php echo esc_html( is_array( $label ) ? ( $label['title'] ?? $key ) : $label ); ?></label><br/>
		<?php endforeach;

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render radio buttons.
	 */
	public function radio_button( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$settings    = get_option( $option_name, array() );
		$current     = $settings[ $id ] ?? ( $args['default'] ?? '' );
		$options     = $args['options'] ?? array();

		foreach ( $options as $key => $label ) :
			$rb_id = $option_name . '_' . $id . '_' . $key;
			?>
			<label>
				<input type="radio"
					id="<?php echo esc_attr( $rb_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
					value="<?php echo esc_attr( $key ); ?>"
					<?php checked( $current, $key ); ?> />
				<?php echo esc_html( $label ); ?>
			</label><br/>
		<?php endforeach;

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render the next document number input with an AJAX set-number button.
	 */
	public function next_number_edit( $args = array() ) {
		$store_callback = $args['store_callback'] ?? null;
		$size           = absint( $args['size'] ?? 10 );
		$description    = $args['description'] ?? '';

		if ( empty( $store_callback ) || ! is_callable( $store_callback ) ) {
			return;
		}

		try {
			$store       = call_user_func( $store_callback );
			$next_number = is_object( $store ) && method_exists( $store, 'get_next' ) ? $store->get_next() : 1;
			$store_name  = is_object( $store ) ? $store->table_name : '';
		} catch ( \Exception $e ) {
			$next_number = 1;
			$store_name  = '';
		}
		?>
		<?php
		printf(
			'<input id="next_%1$s" class="next-number-input" type="number" size="10" value="%2$s" disabled="disabled" data-store="%1$s" data-nonce="%3$s"/> <span class="edit-next-number dashicons dashicons-edit"></span><span class="save-next-number button secondary" style="display:none;">%4$s</span>',
			esc_attr( $store_name ),
			esc_attr( $next_number ),
			esc_attr( wp_create_nonce( "woi_pdf_next_{$store_name}" ) ),
			esc_html__( 'Save', 'woocommerce-orders-invoice-pdf' )
		);
		?>
		<?php if ( $description ) : ?>
			<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render multiple labeled text inputs (prefix, suffix, padding, etc.).
	 */
	public function multiple_text_input( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$fields      = $args['fields'] ?? array();
		$settings    = get_option( $option_name, array() );
		$current     = isset( $settings[ $id ] ) && is_array( $settings[ $id ] ) ? $settings[ $id ] : array();

		foreach ( $fields as $field_key => $field ) :
			$label       = $field['label'] ?? $field_key;
			$field_size  = absint( $field['size'] ?? 20 );
			$field_type  = $field['type'] ?? 'text';
			$field_desc  = $field['description'] ?? '';
			$field_value = $current[ $field_key ] ?? ( $field['default'] ?? '' );
			$field_id    = $option_name . '_' . $id . '_' . $field_key;
			?>
			<p>
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label><br/>
				<input
					type="<?php echo esc_attr( $field_type ); ?>"
					id="<?php echo esc_attr( $field_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>][<?php echo esc_attr( $field_key ); ?>]"
					value="<?php echo esc_attr( $field_value ); ?>"
					size="<?php echo esc_attr( $field_size ); ?>"
					class="regular-text" />
				<?php if ( $field_desc ) : ?>
					<span class="description"><?php echo wp_kses_post( $field_desc ); ?></span>
				<?php endif; ?>
			</p>
		<?php endforeach;

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Alias used by EditorSettings — renders a textarea with width/height args.
	 */
	public function textarea( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$id          = $args['id'] ?? '';
		$settings    = get_option( $option_name, array() );
		$value       = $settings[ $id ] ?? ( $args['default'] ?? '' );
		$cols        = absint( $args['width'] ?? 50 );
		$rows        = absint( $args['height'] ?? 4 );
		?>
		<textarea
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $id ); ?>]"
			rows="<?php echo esc_attr( $rows ); ?>"
			cols="<?php echo esc_attr( $cols ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a singular text element (stored as its own option key, not nested).
	 */
	public function singular_text_element( $args = array() ) {
		$option_name = $args['option_name'] ?? '';
		$value       = get_option( $option_name, '' );
		?>
		<input type="text"
			name="<?php echo esc_attr( $option_name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<?php
	}
}

endif;
