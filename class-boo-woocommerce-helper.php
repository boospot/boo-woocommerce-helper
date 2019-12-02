<?php
/**
 * Name:        Boo WooCommerce helper class
 *
 * Version:     1.0.0
 * Author:        RaoAbid | BooSpot
 *
 * @author RaoAbid | BooSpot
 * @link https://github.com/boospot/boo-woocommerce-helper
 */
if ( ! class_exists( 'Boo_Woocommerce_Helper' ) ):

	class Boo_Woocommerce_Helper {

		public $debug = false;

		public $log = false;

		public $field_types = array();

		protected $tabs = array();

		// flag for options processing
		protected $prefix = '';

		protected $active_tabs;

		/**
		 * Fields array
		 *
		 * @var array
		 */
		protected $fields = array();


		public function __construct( $config_array = null ) {

			if ( ! empty( $config_array ) && is_array( $config_array ) ) {
				$this->set_properties( $config_array );
			}

			add_action( 'init', array( $this, 'init' ) );

		}

		/**
		 *
		 */
		public function init() {

			add_filter( 'woocommerce_product_data_tabs', array( $this, 'register_tabs' ) );

			add_filter( 'woocommerce_product_data_panels', array( $this, 'display_tab_fields' ) ); // WC 2.6 and up

			add_action( 'woocommerce_process_product_meta', array( $this, 'save_tab_fields' ) );

			$this->register_fields_display_hooks();

		}

		/**
		 * Save fields
		 */
		public function save_tab_fields( $post_id ) {

			$product = wc_get_product( $post_id );

			foreach ( $this->get_fields() as $tab_id => $fields ) {

				foreach ( $fields as $field ) {
					$dirty_value = isset( $_POST[ $field['name'] ] ) ? $_POST[ $field['name'] ] : '';
					$clean_value = call_user_func(
						( is_callable( $field['sanitize_callback'] ) )
							? $field['sanitize_callback']
							: $this->get_sanitize_callback_method( $field['type'] ),
						$dirty_value
					);
					$product->update_meta_data( $field['name'], $clean_value );
				}

			}

			$product->save();

		}

		/**
		 * Display field Label
		 */
		public function print_field_label( $field ) {

			// Field start
			printf( '<p class="form-field %1$s %2$s"><label for="%1$s">%3$s</label>',
				sanitize_html_class( $field['id'] ) . '_field ',
				sanitize_html_class( $field['wrapper_class'] ),
				wp_kses_post( $field['label'] )
			);

		}

		/**
		 * Display tab contents
		 */
		public function display_tab_fields() {

			foreach ( $this->get_fields() as $tab_id => $fields ) {
				$this->tab_start( $tab_id );

				do_action( 'woocommerce_product_options_' . $tab_id );

				$this->tab_end();
			}

			// Call General Scripts
			$this->script_general();

		}

		/**
		 *
		 */
		public function register_fields_display_hooks() {

			foreach ( array_keys( $this->get_fields() ) as $tab_id ) {

				$this->active_tabs[] = $tab_id;
				add_action( 'woocommerce_product_options_' . $tab_id, function () {
					foreach ( $this->active_tabs as $index => $tab_id ) {
						if ( doing_action( 'woocommerce_product_options_' . $tab_id ) ) {
							$this->display_fields_for_tab( $tab_id );
							unset( $this->active_tabs[ $tab_id ] );
						}
					}

				} );
			}
		}

		/**
		 *
		 */
		public function display_fields_for_tab( $tab_id ) {

			$fields = $this->get_fields( $tab_id );
			foreach ( $fields as $field ) {
				call_user_func(
					( is_callable( $field['callback'] ) )
						? $field['callback']
						: $this->get_field_markup_callback_method( $field['type'] ),
					$field
				);
				$this->print_field_description( $field );
				printf( '</p>' );
			}

		}


		/**
		 *
		 */
		public function process_fields() {
			foreach ( $this->get_fields() as $tab_id => $fields ) {

				foreach ( $fields as $field ) {
					call_user_func(
						( is_callable( $field['callback'] ) )
							? $field['callback']
							: $this->get_field_markup_callback_method( $field['type'] ),
						$field
					);

					$this->print_field_description( $field );

					printf( '</p>' );
				}
			}


		}

		/**
		 *
		 */
		public function tab_start( $tab_id ) {
			printf( '<div id="%s" class="panel woocommerce_options_panel"><div class="options_group">', $tab_id . '_options' );
		}

		/**
		 *
		 */
		public function tab_end() {
			echo '</div></div>';
		}

		/**
		 * Register Woocommerce tabs
		 */
		public function register_tabs( $tabs ) {

			foreach ( $this->get_tabs() as $tab ) {
				$tabs[ $tab['id'] ] = array(
					'label'    => $tab['label'],
					'target'   => $tab['id'] . '_options',
					'class'    => $tab['class'],
					'priority' => $tab['priority'],
				);
			}

			return $tabs;

		}


		/**
		 * Hooks to Add scripts and CSS
		 */
		public function setup_hooks() {

			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'admin_head', array( $this, 'admin_enqueue_styles' ) );

		}


		/**
		 * Set Properties of the class
		 */
		protected function set_properties( array $config_array ) {

			// Normalise config array

			if ( isset( $config_array['prefix'] ) ) {
				$this->prefix = ( ! empty( $config_array['prefix'] ) ) ? sanitize_key( $config_array['prefix'] ) : '';
			}

			if ( isset( $config_array['tabs'] ) ) {
				$this->set_tabs( $config_array['tabs'] );
			}

			// Do we have fields config, if yes, call the method
			if ( isset( $config_array['fields'] ) ) {
				$this->set_fields( $config_array['fields'] );
			}


		}

		//DEBUG
		public function write_log( $type, $log_line ) {

			$hash        = '';
			$fn          = plugin_dir_path( __FILE__ ) . '/' . $type . '-' . $hash . '.log';
			$log_in_file = file_put_contents( $fn, date( 'Y-m-d H:i:s' ) . ' - ' . $log_line . PHP_EOL, FILE_APPEND );

		}

		/*
		 * @return array configured field types
		 */
		public function get_field_types() {

			foreach ( $this->fields as $fields ) {
				foreach ( $fields as $field ) {
					$this->field_types[] = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
				}
			}

			return array_unique( $this->field_types );
		}


		/**
		 * @return bool true if its plugin options page is loaded
		 */
		protected function is_edit_product() {

			$current_screen = get_current_screen();

			return ( 'product' === $current_screen->post_type );

		}

		/**
		 * Enqueue scripts and styles
		 */
		function admin_enqueue_scripts() {

			// Conditionally Load scripts and styles for field types configured

			if ( ! $this->is_edit_product() ) {
				return null;
			}

			// Load Color Picker if required
			if ( in_array( 'color', $this->get_field_types(), true ) ) {
				wp_enqueue_style( 'wp-color-picker' );
				wp_enqueue_script( 'wp-color-picker' );
			}

			if ( in_array( 'media', $this->get_field_types(), true ) ) {
				wp_enqueue_media();
			}
			wp_enqueue_script( 'jquery' );


		}

		/**
		 * Enqueue scripts and styles
		 */
		function admin_enqueue_styles() {

			if ( ! $this->is_edit_product() ) {
				return null;
			}

			$css = '.woocommerce_options_panel input[type=url].short{width: 50%;}
			.woocommerce_options_panel input[type=url]{float: left;}
			.woocommerce_options_panel input[type=checkbox]{float: left;}
			';

			echo "<style>{$css}</style>";
		}


		/**
		 * Set settings tabs
		 *
		 * @param array $tabs setting tabs array
		 */
		function set_tabs( array $tabs ) {

			$this->tabs = array_merge_recursive( $this->tabs, $tabs );

			$this->normalize_tabs();

			return $this;
		}

		/**
		 * Set settings fields
		 *
		 * @param array $fields settings fields array
		 */
		public function set_fields( $fields ) {
			$this->fields = array_merge_recursive( $this->fields, $fields );
			$this->normalize_fields();
			$this->setup_hooks();

			return $this;

		}


		public function get_sanitize_callback_method( $type ) {

			return ( method_exists( $this, "sanitize_{$type}" ) )
				? array( $this, "sanitize_{$type}" )
				: array( $this, "sanitize_text" );

		}

		public function get_field_markup_callback_method( $type ) {

			return ( method_exists( $this, "callback_{$type}" ) )
				? array( $this, "callback_{$type}" )
				: array( $this, "callback_text" );

		}

		public function get_field_markup_callback_name( $type ) {

			return ( method_exists( $this, "callback_{$type}" ) )
				? "callback_{$type}"
				: "callback_text";


		}

		public function get_default_tabs_args( $tab ) {
			return array(
				'id'       => $tab['id'],
				'label'    => '',
				'priority' => null,
				'target'   => $tab['id'] . '_options',
				'class'    => array( 'show_if_simple', 'show_if_variable', $tab['id'] ),
			);
		}

		public function get_default_field_args( $field ) {
			return array(
				'id'                => $field['id'],
				'label'             => '',
				'description'       => '',
				'desc'              => '',
				'desc_tip'          => true,
				'type'              => 'text',
				'wrapper_class'     => '',
				'placeholder'       => '',
				'default'           => '',
				'std'               => '',
				'custom_attributes' => array(),
				'options'           => array(),
				'callback'          => '',
				'sanitize_callback' => '',
				'value'             => '',
				'style'             => '',
				'class'             => '',
				'size'              => '',
				// Auto Calculated
				'name'              => $this->prefix . $field['id'],
				'label_for'         => $this->prefix . $field['id'],
				'data_type'         => ''
			);
		}

		/**
		 * Process tabs to normalise its array
		 */
		public function normalize_tabs() {

			foreach ( $this->tabs as $index => $tab ) {

				$this->tabs[ $index ] = wp_parse_args(
					$tab,
					$this->get_default_tabs_args( $tab )
				);
			}

		}

		public function normalize_fields() {

			$admin_post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

			foreach ( $this->fields as $tab_id => $fields ) {
				if ( is_array( $fields ) && ! empty( $fields ) ) {
					foreach ( $fields as $i => $field ) {

						$field = wp_parse_args(
							$field,
							$this->get_default_field_args( $field )
						);

						$field['value'] =
							( empty( $field['value'] ) && $admin_post_id )
								? get_post_meta( $admin_post_id, $field['name'], true )
								: '';

						$data_type = empty( $field['data_type'] ) ? '' : $field['data_type'];
						switch ( $data_type ) {
							case 'price':
								$field['class'] .= ' wc_input_price';
								$field['value'] = wc_format_localized_price( $field['value'] );
								break;
							case 'decimal':
								$field['class'] .= ' wc_input_decimal';
								$field['value'] = wc_format_localized_decimal( $field['value'] );
								break;
							case 'stock':
								$field['class'] .= ' wc_input_stock';
								$field['value'] = wc_stock_amount( $field['value'] );
								break;
							case 'url':
								$field['class'] .= ' wc_input_url';
								$field['value'] = esc_url( $field['value'] );
								break;

							default:
								break;
						}

						switch ( $field['type'] ) {
							case 'text':
							case 'url':
							case 'number':
							case 'textarea':
							case 'select':
							case 'pages':
							case 'posts':
							case 'file':
							case 'media':
								$field['class'] .= ' short';
								break;
							default:
								break;
						}

						// Custom attribute handling
						$custom_attributes = array();

						if ( ! empty( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ) {
							foreach ( $field['custom_attributes'] as $attribute => $value ) {
								$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $value ) . '"';
							}
							$field['custom_attributes'] = $custom_attributes;
						}

						if ( empty( $field['description'] ) && ! empty( $field['desc'] ) ) {
							$field['description'] = $field['desc'];
						}

						$this->fields[ $tab_id ][ $i ] = $field;

					}
				}

			}

		}


		function sanitize_text( $value ) {
			return ( ! empty( $value ) ) ? sanitize_text_field( $value ) : '';
		}

		function sanitize_number( $value ) {
			return ( is_numeric( $value ) ) ? $value : 0;
		}

		function sanitize_editor( $value ) {
			return wp_kses_post( $value );
		}

		function sanitize_textarea( $value ) {
			return sanitize_textarea_field( $value );
		}

		function sanitize_checkbox( $value ) {
			return ( 'yes' === $value ) ? 1 : 0;
		}

		function sanitize_select( $value ) {
			return $this->sanitize_text( $value );
		}

		function sanitize_radio( $value ) {
			return $this->sanitize_text( $value );
		}

		function sanitize_multicheck( $value ) {
			return ( is_array( $value ) ) ? array_map( 'sanitize_text_field', $value ) : array();
		}

		function sanitize_color( $value ) {

			if ( false === strpos( $value, 'rgba' ) ) {
				return sanitize_hex_color( $value );
			} else {
				// By now we know the string is formatted as an rgba color so we need to further sanitize it.

				$value = trim( $value, ' ' );
				$red   = $green = $blue = $alpha = '';
				sscanf( $value, 'rgba(%d,%d,%d,%f)', $red, $green, $blue, $alpha );

				return 'rgba(' . $red . ',' . $green . ',' . $blue . ',' . $alpha . ')';
			}
		}

		function sanitize_password( $value ) {

			$password_get_info = password_get_info( $value );

			if ( isset( $password_get_info['algo'] ) && $password_get_info['algo'] ) {
				unset( $password_get_info );

				return $value;
				// do nothing, we have got already stored hashed password
			} else {
				unset( $password_get_info );

				return password_hash( $value, PASSWORD_DEFAULT );
			}

		}

		function sanitize_url( $value ) {
			return esc_url_raw( $value );
		}

		function sanitize_file( $value ) {
//		    TODO: if the option to store file as file url
			return esc_url_raw( $value );
		}

		function sanitize_html( $value ) {
			// nothing to save
			return '';
		}

		function sanitize_posts( $value ) {
			// Only store post id
			return absint( $value );
		}

		function sanitize_pages( $value ) {
			// Only store page id
			return absint( $value );
		}

		function sanitize_media( $value ) {
			// Only store media id
			return absint( $value );
		}

		/**
		 * Displays a text field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_text( $field ) {

			$this->print_field_label( $field );

			$this->print_field_description_before( $field );

			echo '<input 
		    type="' . esc_attr( $field['type'] ) . '" 
		    class="' . esc_attr( $field['class'] ) . '" 
		    style="' . esc_attr( $field['style'] ) . '" 
		    name="' . esc_attr( $field['name'] ) . '" 
		    id="' . esc_attr( $field['name'] ) . '" 
		    value="' . esc_attr( $field['value'] ) . '" 
		    placeholder="' . esc_attr( $field['placeholder'] ) . '" '
			     . implode( ' ', $field['custom_attributes'] ) . ' /> ';

			$this->print_field_description( $field );

		}


		/**
		 * Get field description for display
		 *
		 * @param array $args settings field args
		 */
		public function print_field_description_before( $field ) {
			if ( ! empty( $field['description'] ) && false !== $field['desc_tip'] ) {
				echo wc_help_tip( $field['description'] );
			}
		}

		/**
		 * Get field description for display
		 *
		 * @param array $args settings field args
		 */
		public function print_field_description( $field ) {
			if ( ! empty( $field['description'] ) && false === $field['desc_tip'] ) {
				echo '<span class="description">' . wp_kses_post( $field['description'] ) . '</span>';
			}
		}


		/**
		 * Displays a url field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_url( $field ) {
			$this->callback_text( $field );
		}

		/**
		 * Displays a number field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_number( $field ) {
			$this->callback_text( $field );
		}

		/**
		 * Displays a checkbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_checkbox( $field ) {

			$this->print_field_label( $field );
			$this->print_field_description_before( $field );

			$field['class']   = '';
			$field['cbvalue'] = isset( $field['cbvalue'] ) ? $field['cbvalue'] : 'yes';

			echo '<input 
		    type="' . esc_attr( $field['type'] ) . '" 
		    class="' . esc_attr( $field['class'] ) . '" 
		    style="' . esc_attr( $field['style'] ) . '" 
		    name="' . esc_attr( $field['name'] ) . '" 
		    id="' . esc_attr( $field['name'] ) . '" 
		    value="' . esc_attr( $field['cbvalue'] ) . '" 
		    placeholder="' . esc_attr( $field['placeholder'] ) . '" ' .
			     checked( $field['value'], '1', false ) .
			     implode( ' ', $field['custom_attributes'] ) . ' /> ';

			$this->print_field_description( $field );

		}

		/**
		 * Displays a multicheckbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_multicheck( $field ) {
			echo '<fieldset class="form-field ' . esc_attr( $field['id'] ) . '_field ' . esc_attr( $field['wrapper_class'] ) . '"><legend>' . wp_kses_post( $field['label'] ) . '</legend>';

			$this->print_field_description_before( $field );

			echo '<ul class="wc-radios">';
			foreach ( $field['options'] as $key => $value ) {
				$checked = isset( $field['value'][ $key ] ) ? $field['value'][ $key ] : '0';
//				$this->var_dump_pretty( $field['value']);

				echo '<li><label><input
				name="' . esc_attr( $field['name'] . '[' . $key . ']' ) . '"
				value="' . esc_attr( $key ) . '"
				type="checkbox"
				class="' . esc_attr( $field['class'] ) . '"
				style="' . esc_attr( $field['style'] ) . '"
				' . checked( $checked, esc_attr( $key ), false ) . '
				/> ' . esc_html( $value ) . '</label>
		</li>';
			}
			echo '</ul>';

			echo '</fieldset>';
			$this->print_field_description( $field );
		}

		/**
		 * Displays a radio button for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_radio( $field ) {

			$value = $field['value'];

			if ( empty( $value ) ) {
				$value = is_array( $field['default'] ) ? $field['default'] : array();
			}

			echo '<fieldset class="form-field ' . esc_attr( $field['id'] ) . '_field ' . esc_attr( $field['wrapper_class'] ) . '"><legend>' . wp_kses_post( $field['label'] ) . '</legend>';
			echo '<ul class="wc-radios">';
			foreach ( $field['options'] as $key => $value ) {

				echo '<li><label><input
				name="' . esc_attr( $field['name'] ) . '"
				value="' . esc_attr( $key ) . '"
				type="' . esc_attr( $field['type'] ) . '"
				class="' . esc_attr( $field['class'] ) . '"
				style="' . esc_attr( $field['style'] ) . '"
				' . checked( esc_attr( $field['value'] ), esc_attr( $key ), false ) . '
				/> ' . esc_html( $value ) . '</label>
		</li>';
			}
			echo '</ul>';

			echo '</fieldset>';

		}

		/**
		 * Displays a selectbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_select( $field ) {

			$this->print_field_label( $field );

			$this->print_field_description_before( $field );

			echo '<select 
		    type="' . esc_attr( $field['type'] ) . '" 
		    class="' . esc_attr( $field['class'] ) . '" 
		    style="' . esc_attr( $field['style'] ) . '" 
		    name="' . esc_attr( $field['name'] ) . '" 
		    id="' . esc_attr( $field['name'] ) . '" 
		    placeholder="' . esc_attr( $field['placeholder'] ) . '" '
			     . implode( ' ', $field['custom_attributes'] ) . ' > ';

			foreach ( $field['options'] as $key => $label ) {
				printf( '<option value="%1s"%2s>%3s</option>',
					esc_attr( $key ),
					selected( $field['value'], $key, false ),
					esc_html( $label )
				);
			}

			echo '</select>';
			$this->print_field_description( $field );

		}

		/**
		 * Displays a textarea for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_textarea( $field ) {

			$this->print_field_label( $field );
			$this->print_field_description_before( $field );

			echo '<textarea 
			class="' . esc_attr( $field['class'] ) . '" 
			style="' . esc_attr( $field['style'] ) . '"  
			name="' . esc_attr( $field['name'] ) . '" 
			id="' . esc_attr( $field['name'] ) . '" 
			placeholder="' . esc_attr( $field['placeholder'] ) . '" ' .
			     implode( ' ', $field['custom_attributes'] )
			     . '>'
			     . esc_textarea( $field['value'] ) .
			     '</textarea> ';

			$this->print_field_description( $field );

		}

		/**
		 *
		 */
		public function array_map_assoc( $callback, $array ) {
			$r = array();
			foreach ( $array as $key => $value ) {
				$r[ $key ] = $callback( $key, $value );
			}

			return $r;

		}

		/**
		 * Helper function
		 */
		public function print_custom_attr( $attr ) {

			echo implode( ',', $this->array_map_assoc( function ( $k, $v ) {
				return "$k ($v)";
			}, $attr ) );

		}

		/**
		 * Displays the html for a settings field
		 *
		 * @param array $args settings field args
		 *
		 * @return string
		 */
		function callback_html( $args ) {
			$this->print_field_description( $args );
		}


		/**
		 * Displays a file upload field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_file( $field ) {

			$label = isset( $field['options']['btn'] )
				? $field['options']['btn']
				: __( 'Select' );

			$this->print_field_label( $field );

			$this->print_field_description_before( $field );
			echo '<input 
		    type="url" 
		    class="' . esc_attr( $field['class'] ) . '" 
		    style="' . esc_attr( $field['style'] ) . '" 
		    name="' . esc_attr( $field['name'] ) . '" 
		    id="' . esc_attr( $field['name'] ) . '" 
		    value="' . esc_url_raw( $field['value'] ) . '" 
		    placeholder="' . esc_attr( $field['placeholder'] ) . '" '
			     . implode( ' ', $field['custom_attributes'] ) . ' /> ';

			echo '<input type="button" class="button boospot-browse-button" value="' . $label . '" />';
			$this->print_field_description( $field );

		}


		/**
		 * Generate: Uploader field
		 *
		 * @param array $field
		 *
		 * @source: https://mycyberuniverse.com/integration-wordpress-media-uploader-plugin-options-page.html
		 */
		public function callback_media( $field ) {

			// Set variables
			$default_image = isset( $field['default'] ) ? esc_url_raw( $field['default'] ) : 'https://www.placehold.it/115x115';
			$max_width     = isset( $field['options']['max_width'] ) ? absint( $field['options']['max_width'] ) : 150;
			$width         = isset( $field['options']['width'] ) ? absint( $field['options']['width'] ) : '';
			$height        = isset( $field['options']['height'] ) ? absint( $field['options']['height'] ) : '';
			$text          = isset( $field['options']['btn'] ) ? sanitize_text_field( $field['options']['btn'] ) : __( 'Upload' );


			$image_size = ( ! empty( $width ) && ! empty( $height ) ) ? array( $width, $height ) : 'thumbnail';

			if ( ! empty( $field['value'] ) ) {
				$image_attributes = wp_get_attachment_image_src( $field['value'], $image_size );
				$src              = $image_attributes[0];
				$value            = $field['value'];
			} else {
				$src   = $default_image;
				$value = '';
			}

			$image_style = ! is_array( $image_size ) ? "style='max-width:100%; height:auto;'" : "style='width:{$width}px; height:{$height}px;'";

			$max_width = $max_width . "px";

			$field['class'] .= ' upload form-field';
			$this->print_field_label( $field );

			// Print HTML field
			echo '
                <span class="' . esc_attr( $field['class'] ) . '" style="max-width:' . $max_width . ';">
                    <img data-src="' . $default_image . '" src="' . $src . '" ' . $image_style . '/>
                    <span>
                        <input type="hidden" name="' . $field['name'] . '" id="' . $field['name'] . '" value="' . $value . '" />
                        <button type="submit" class="boospot-image-upload button">' . $text . '</button>
                        <button type="submit" class="boospot-image-remove button">&times;</button>
                    </span>
                </span>
            ';
			$this->print_field_description_before( $field );
			$this->print_field_description( $field );

			echo "</p>";

			// free memory
			unset( $default_image, $max_width, $width, $height, $text, $image_size, $image_style, $value );

		}

		/**
		 * Displays a password field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_password( $field ) {

			$this->callback_text( $field );

		}

		/**
		 * Displays a color picker field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_color( $field ) {
			$field['class']             = $field['class'] . ' wp-color-picker-field';
			$field['custom_attributes'] = array_merge( $field['custom_attributes'], array(
				'data-alpha'         => "true",
				'data-default-color' => $field['default']
			) );

			$this->callback_text( $field );

		}


		/**
		 * Displays a select box for creating the pages select box
		 *
		 * @param array $args settings field args
		 */
		function callback_pages( $field ) {

			$this->print_field_label( $field );
			$this->print_field_description_before( $field );

			$dropdown_args = array(
				'selected'         => $field['value'],
				'name'             => $field['name'],
				'id'               => $field['name'],
				'echo'             => 1,
				'show_option_none' => '-- ' . __( 'Select' ) . ' --',
				'class'            => $field['class'], // string
			);

			wp_dropdown_pages( $dropdown_args );

			$this->print_field_description( $field );
		}

		function callback_posts( $field ) {
			$default_args = array(
				'post_type'   => 'post',
				'numberposts' => - 1
			);

			$posts_args = wp_parse_args( $field['options'], $default_args );

			$posts = get_posts( $posts_args );

			$options = array(
				'' => '-- ' . __( 'Select' ) . ' --'
			);

			foreach ( $posts as $post ) :
				setup_postdata( $post );
				$options[ $post->ID ] = esc_html( $post->post_title );
				wp_reset_postdata();
			endforeach;

			// free memory
			unset( $posts, $posts_args, $default_args );

			//$args['options'] is required by callback_select()
			$field['options'] = $options;

			$this->callback_select( $field );

		}


		public function var_dump_pretty( $var ) {
			echo "<pre>";
			var_dump( $var );
			echo "</pre>";
		}

		public function var_export_pretty( $var ) {
			echo "<pre>";
			var_export( $var );
			echo "</pre>";
		}

		/**
		 * Tabbable JavaScript codes & Initiate Color Picker
		 *
		 * This code uses localstorage for displaying active tabs
		 */
		public function script_general() {
			?>
            <script>
                jQuery(document).ready(function ($) {
                    //Initiate Color Picker
                    if ($('.wp-color-picker-field').length > 0) {
                        $('.wp-color-picker-field').wpColorPicker();
                    }


                    // For Files Upload
                    $('.boospot-browse-button').on('click', function (event) {
                        event.preventDefault();

                        var self = $(this);

                        // Create the media frame.
                        var file_frame = wp.media.frames.file_frame = wp.media({
                            title: self.data('uploader_title'),
                            button: {
                                text: self.data('uploader_button_text'),
                            },
                            multiple: false
                        });

                        file_frame.on('select', function () {
                            attachment = file_frame.state().get('selection').first().toJSON();
                            self.prev('input').val(attachment.url).change();
                        });

                        // Finally, open the modal
                        file_frame.open();
                    });


                    // Prevent page navigation for un-saved changes
                    $(function () {
                        var changed = false;

                        $('input, textarea, select, checkbox').change(function () {
                            changed = true;
                        });

                        $('.nav-tab-wrapper a').click(function () {
                            if (changed) {
                                window.onbeforeunload = function () {
                                    return "Changes you made may not be saved."
                                };
                            } else {
                                window.onbeforeunload = '';
                            }
                        });

                        $('.submit :input').click(function () {
                            window.onbeforeunload = '';
                        });
                    });


                    // The "Upload" button
                    $('.boospot-image-upload').click(function () {
                        var send_attachment_bkp = wp.media.editor.send.attachment;
                        var button = $(this);
                        wp.media.editor.send.attachment = function (props, attachment) {
                            $(button).parent().prev().attr('src', attachment.url);
                            if (attachment.id) {
                                $(button).prev().val(attachment.id);
                            }
                            wp.media.editor.send.attachment = send_attachment_bkp;
                        }
                        wp.media.editor.open(button);
                        return false;
                    });

                    // The "Remove" button (remove the value from input type='hidden')
                    $('.boospot-image-remove').click(function () {
                        var answer = confirm('Are you sure?');
                        if (true === answer) {
                            var src = $(this).parent().prev().attr('data-src');
                            $(this).parent().prev().attr('src', src);
                            $(this).prev().prev().val('');
                        }
                        return false;
                    });

                });
            </script>
			<?php
		}

		public function get_tabs() {

			return ( ! empty( $this->tabs ) && is_array( $this->tabs ) ) ? $this->tabs : array();

		}

		public function get_fields( $tab_id = null ) {

			if ( empty( $tab_id ) ) {
				return $this->fields;
			} else {
				return isset( $this->fields[ $tab_id ] ) ? $this->fields[ $tab_id ] : array();
			}

		}


	}

endif;