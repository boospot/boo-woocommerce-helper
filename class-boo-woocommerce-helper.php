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

		public $plugin_basename = '';

		public $action_links = array();

		public $config_menu = array();

		public $field_types = array();

		protected $is_tabs = false;

		public $slug;

		protected $active_tab;

		protected $tabs_count;

		protected $tabs_ids;

		protected $fields_ids;

		// flag for options processing
		protected $is_settings_saved_once = false;

		protected $sanitized_data;

		protected $prefix = '';

		protected $is_simple_options = true;

		/**
		 * settings tabs array
		 *
		 * @var array
		 */
		protected $fields_tabs = array();

		/**
		 * Settings fields array
		 *
		 * @var array
		 */
		protected $tabs_fields = array();

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

		}

		/**
		 *
		 */
		public function save_tab_fields( $post_id ) {

			$product = wc_get_product( $post_id );

			foreach ( $this->get_tabs_fields() as $tab_id => $fields ) {

				foreach ( $fields as $field ) {
					$dirty_value = isset( $_POST[ $field['id'] ] ) ? $_POST[ $field['id'] ] : '';
					$clean_value = call_user_func(
						( is_callable( $field['sanitize_callback'] ) )
							? $field['sanitize_callback']
							: $this->get_sanitize_callback_method( $field['type'] ),
						$dirty_value
					);
					$product->update_meta_data( $field['id'], $clean_value );
				}

			}

			$product->save();

		}

		/**
		 * Display tab contents
		 */
		public function display_tab_fields() {
			global $post;


			$tabs = $this->get_fields_tabs();

			foreach ( $this->get_tabs_fields() as $tab_id => $fields ) {
				$this->tab_start( $tab_id );

				foreach ( $fields as $field ) {

					if ( $post->ID ) {
						$field['value'] = get_post_meta( $post->ID, $field['id'], true );
					}

					printf( '<p class="form-field %1$s %s"><label for="%1$s">%3$s</label>',
						sanitize_html_class( $field['id'] ) . '_field ',
						sanitize_html_class( $field['wrapper_class'] ),
						wp_kses_post( $field['label'] )
					);
					call_user_func(
						( is_callable( $field['callback'] ) )
							? $field['callback']
							: $this->get_field_markup_callback_method( $field['type'] ),
						$field
					);

					printf( '</p>' );
				}

				$this->tab_end();
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

			foreach ( $this->get_fields_tabs() as $tab ) {
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
		 * Custom Product tabs
		 */
		public function custom_product_tabs() {


		}


		public function setup_hooks() {

			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

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


			$this->set_active_tab();


		}

		/**
		 *
		 */
		public function set_active_tab() {
			$this->active_tab =
				( isset( $_GET['tab'] ) )
					? sanitize_key( $_GET['tab'] )
					: $this->fields_tabs[0]['id'];
		}


		public function get_default_settings_url() {

			if ( $this->config_menu['submenu'] ) {
				$options_base_file_name = $this->config_menu['parent'];
				if ( in_array( $options_base_file_name, array(
					'options-general.php',
					'edit-comments.php',
					'plugins.php',
					'edit.php',
					'upload.php',
					'themes.php',
					'users.php',
					'tools.php'
				) ) ) {
					return admin_url( "{$options_base_file_name}?page={$this->config_menu['slug']}" );
				} else {
					return admin_url( "{$options_base_file_name}&page={$this->config_menu['slug']}" );
				}
			} else {
				return admin_url( "admin.php?page={$this->config_menu['slug']}" );
			}


		}

		public function get_default_settings_link() {

			return array(
				'<a href="' . $this->get_default_settings_url() . '">' . __( 'Settings' ) . '</a>',
			);

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

			foreach ( $this->tabs_fields as $tabs_fields ) {
				foreach ( $tabs_fields as $field ) {
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
		 * Set settings tabs
		 *
		 * @param array $tabs setting tabs array
		 */
		function set_tabs( array $tabs ) {

			$this->fields_tabs = array_merge_recursive( $this->fields_tabs, $tabs );

			$this->normalize_tabs();

			$this->tabs_count = count( $this->fields_tabs );
			$this->tabs_ids   = array_values( $this->fields_tabs );

			return $this;
		}

		/**
		 * Set settings fields
		 *
		 * @param array $fields settings fields array
		 */
		public function set_fields( $fields ) {
			$this->tabs_fields = array_merge_recursive( $this->tabs_fields, $fields );
			$this->normalize_fields();
			$this->setup_hooks();

			return $this;

		}

		public function get_markup_placeholder( $placeholder ) {
			return ' placeholder="' . esc_html( $placeholder ) . '" ';
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

		public function get_default_field_args( $field, $tab = 'default' ) {

			return array(
				'id'                => $field['id'],
				'label'             => '',
				'desc'              => '',
				'type'              => 'text',
				'wrapper_class'     => '',
				'placeholder'       => '',
				'default'           => '',
				'options'           => array(),
				'callback'          => '',
				'sanitize_callback' => '',
				'value'             => '',
				'show_in_rest'      => true,
				'class'             => $field['id'],
				'std'               => '',
				'size'              => 'regular',
				// Auto Calculated
				'name'              => $this->prefix . $field['id'],
				'label_for'         => $this->prefix . $field['id'],
				'tab'               => $tab,

			);
		}

		/**
		 *
		 */
		public function normalize_tabs() {

			foreach ( $this->fields_tabs as $index => $tab ) {

				$this->fields_tabs[ $index ] = wp_parse_args(
					$tab,
					$this->get_default_tabs_args( $tab )
				);
			}

		}

		public function normalize_fields() {

			foreach ( $this->tabs_fields as $tab_id => $fields ) {
				if ( is_array( $fields ) && ! empty( $fields ) ) {
					foreach ( $fields as $i => $field ) {
						$this->tabs_fields[ $tab_id ][ $i ] =
							wp_parse_args(
								$field,
								$this->get_default_field_args( $field, $tab_id )
							);
					}
				}

			}


		}


		/**
		 *
		 */
		public function get_page_id_for_tabs( $tab_id = '' ) {
//			return $this->config_menu['slug'] . '_' . $tab_id;
			return $this->config_menu['slug'];
		}

		public function get_options_group( $tab_id = '' ) {
			return str_replace( '-', '_', $this->slug ) . "_" . $tab_id;
//			return str_replace( '-', '_', $this->slug );
		}

		function display_page() {

			// Save Default options in DB with default values
//			$this->set_default_db_options();

			if ( 'options-general.php' != $this->config_menu['parent'] ) {
				settings_errors();
			}

			echo '<div class="wrap">';
			echo "<h1>" . get_admin_page_title() . "</h1>";

			// If Debug is ON
			if ( $this->debug ) {
				echo "<b>TYPES of fields</b>";
				$this->var_dump_pretty( $this->get_field_types() );

				if ( $this->is_tabs ) {
					echo "<b>Active Tab Options Array</b>";
					$this->var_dump_pretty( get_option( $this->active_tab ) );

				}
			}

			?>
            <div class="metabox-holder">
				<?php
				if ( $this->is_tabs ) {
					$this->show_navigation();
				}
				?>
                <form method="post" action="options.php">
					<?php

					if ( $this->is_tabs ) {
						foreach ( $this->fields_tabs as $tab ) :
							if ( $tab['id'] !== $this->active_tab ) {
								continue;
							}

							// for tabs
							tabs_fields( $this->get_options_group( $tab['id'] ) );
							do_fields_tabs( $this->get_page_id_for_tabs( $tab['id'] ) );
						endforeach; // end foreach

					} else {
						// for tab-less
						tabs_fields( $this->get_options_group() );
						do_fields_tabs( $this->get_page_id_for_tabs() );

					}

					?>
                    <div style="padding-left: 10px">
						<?php submit_button(); ?>
                    </div>

                </form>
            </div>
			<?php

			// Call General Scripts
			$this->script_general();
			?>
            </div>
			<?php
		}

		public function add_settings_tab() {

			//register settings tabs
			foreach ( $this->fields_tabs as $tab ) {

				if ( $this->is_tabs ) {
					if ( $tab['id'] !== $this->active_tab ) {
						continue;
					}
				}

				// Callback for tab Description
				if ( isset( $tab['callback'] ) && is_callable( $tab['callback'] ) ) {
					$callback = $tab['callback'];
				} else if ( isset( $tab['desc'] ) && ! empty( $tab['desc'] ) ) {
					$callback = function () use ( $tab ) {
						echo "<div class='inside'>" . esc_html( $tab['desc'] ) . "</div>";
					};
				} else {
					$callback = null;
				}

//				add_settings_tab(
//					$tab['id'],
//					$tab['title'],
//					$callback,
//					$this->get_page_id_for_tabs( $tab['id'] ) // page
//				);

			}

		}

		public function add_settings_field_loop() {

			//register settings fields
			foreach ( $this->tabs_fields as $tab_id => $fields ) {

				if ( $this->is_tabs ) {
					if ( $tab_id !== $this->active_tab ) {
						continue;
					}
				}

				foreach ( $fields as $field ) :

					$field['value'] = get_option( $field['name'], $field['default'] );

					add_settings_field(
						$field['name'],
						$field['label'],
						( is_callable( $field['callback'] ) )
							? $field['callback']
							: $this->get_field_markup_callback_method( $field['type'] ),
						$this->get_page_id_for_tabs( $tab_id ), // page
						$tab_id, // tab
						$field  // args
					);
				endforeach;
			}

		}

		public function register_settings() {
			// creates our settings in the options table
			foreach ( $this->tabs_fields as $tab_id => $fields ) :

				foreach ( $fields as $field ) :

					register_setting(
						( $this->is_tabs ) ?
							$this->get_options_group( $field['tab'] )
							: $this->get_options_group(), // options_group
						$field['name'], // options_id
						array(
//								'type'              => $field['type'],
							'description'       => $field['desc'],
							'sanitize_callback' => ( is_callable( $field['sanitize_callback'] ) )
								? $field['sanitize_callback']
								: $this->get_sanitize_callback_method( $field['type'] ),
							'show_in_rest'      => $field['show_in_rest'],
							'default'           => $field['default'],
						)
					);
				endforeach;
			endforeach;
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
			return ( $value === '1' ) ? 1 : 0;
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
		function callback_text( $args ) {

			$html = sprintf(
				'<input 
                        type="%1$s" 
                        class="%2$s-text %8$s" 
                        id="%3$s[%4$s]" 
                        name="%7$s" 
                        value="%5$s"
                        %6$s
                        />',
				$args['type'],
				$args['size'],
				$args['tab'],
				$args['id'],
				$args['value'],
				$this->get_markup_placeholder( $args['placeholder'] ),
				$args['name'],
				$args['class']
			);
			$html .= $this->get_field_description( $args );

			echo $html;
			unset( $html );
		}


		/**
		 * Initialize and registers the settings tabs and fileds to WordPress
		 *
		 * Usually this should be called at `admin_init` hook.
		 *
		 * This function gets the initiated settings tabs and fields. Then
		 * registers them to WordPress and ready for use.
		 */
		function admin_init() {

			$this->add_settings_tab();
//			$this->add_settings_field_loop();
//			$this->register_settings();


		}

		/**
		 * Get field description for display
		 *
		 * @param array $args settings field args
		 */
		public function get_field_description( $args ) {
			return sprintf( '<p class="description">%s</p>', $args['desc'] );
		}


		/**
		 * Displays a url field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_url( $args ) {
			$this->callback_text( $args );
		}

		/**
		 * Displays a number field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_number( $args ) {
			$min  = ( isset( $args['options']['min'] ) && ! empty( $args['options']['min'] ) ) ? ' min="' . $args['options']['min'] . '"' : '';
			$max  = ( isset( $args['options']['max'] ) && ! empty( $args['options']['max'] ) ) ? ' max="' . $args['options']['max'] . '"' : '';
			$step = ( isset( $args['options']['step'] ) && ! empty( $args['options']['step'] ) ) ? ' step="' . $args['options']['step'] . '"' : '';

			$html = sprintf(
				'<input
                        type="%1$s"
                        class="%2$s-text"
                        id="%3$s[%4$s]"
                        name="%10$s"
                        value="%5$s"
                        %6$s
                        %7$s
                        %8$s
                        %9$s
                        />',
				$args['type'],
				$args['size'],
				$args['tab'],
				$args['id'],
				$args['value'],
				$this->get_markup_placeholder( $args['placeholder'] ),
				$min,
				$max,
				$step,
				$args['name']
			);
			$html .= $this->get_field_description( $args );
			echo $html;

			unset( $html, $min, $max, $step );
		}

		/**
		 * Displays a checkbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_checkbox( $args ) {


			$html = '<fieldset>';
			$html .= sprintf( '<label for="%1$s[%2$s]">', $args['tab'], $args['id'] );
			$html .= sprintf( '<input type="checkbox" class="checkbox" id="%1$s[%2$s]" name="%4$s" value="1" %3$s />', $args['tab'], $args['id'], checked( $args['value'], '1', false ), $args['name'] );
			$html .= sprintf( '%1$s</label>', $args['desc'] );
			$html .= '</fieldset>';

			echo $html;
		}

		/**
		 * Displays a multicheckbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_multicheck( $args ) {
			$value = $args['value'];
			if ( empty( $value ) ) {
				$value = $args['default'];
			}

			$html = '<fieldset>';
			foreach ( $args['options'] as $key => $label ) {
				$checked = isset( $value[ $key ] ) ? $value[ $key ] : '0';
				$html    .= sprintf( '<label for="%1$s[%2$s][%3$s]">', $args['tab'], $args['id'], $key );
				$html    .= sprintf( '<input type="checkbox" class="checkbox" id="%1$s[%2$s][%3$s]" name="%5$s[%3$s]" value="%3$s" %4$s />', $args['tab'], $args['id'], $key, checked( $checked, $key, false ), $args['name'] );
				$html    .= sprintf( '%1$s</label><br>', $label );
			}

			$html .= $this->get_field_description( $args );
			$html .= '</fieldset>';
			echo $html;
			unset( $value, $html );
		}

		/**
		 * Displays a radio button for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_radio( $args ) {

			$value = $args['value'];

			if ( empty( $value ) ) {
				$value = is_array( $args['default'] ) ? $args['default'] : array();
			}

			$html = '<fieldset>';

			foreach ( $args['options'] as $key => $label ) {

				$html .= sprintf( '<label for="%1$s[%2$s][%3$s]">', $args['tab'], $args['id'], $key );
				$html .= sprintf( '<input type="radio" class="radio" id="%1$s[%2$s][%3$s]" name="%5$s" value="%3$s" %4$s />', $args['tab'], $args['id'], $key, checked( $args['value'], $key, false ), $args['name'] );
				$html .= sprintf( '%1$s</label><br>', $label );
			}

			$html .= $this->get_field_description( $args );
			$html .= '</fieldset>';

			echo $html;
			unset( $value, $html );
		}

		/**
		 * Displays a selectbox for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_select( $args ) {

			$html = sprintf( '<select class="%1$s-text %5$s" name="%4$s" id="%2$s[%3$s]">', $args['size'], $args['tab'], $args['id'], $args['name'], $args['class'] );

			foreach ( $args['options'] as $key => $label ) {
				$html .=
					sprintf( '<option value="%1s"%2s>%3s</option>',
						$key,
						selected( $args['value'], $key, false ),
						$label
					);
			}

			$html .= sprintf( '</select>' );
			$html .= $this->get_field_description( $args );

			echo $html;
			unset( $html );
		}

		/**
		 * Displays a textarea for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_textarea( $args ) {

			$html = sprintf(
				'<textarea 
                        rows="5" 
                        cols="55" 
                        class="%1$s-text" 
                        id="%2$s" 
                        name="%5$s"
                        %3$s
                        >%4$s</textarea>',
				$args['size'], $args['id'], $this->get_markup_placeholder( $args['placeholder'] ), $args['value'], $args['name'] );
			$html .= $this->get_field_description( $args );

			echo $html;
		}

		/**
		 * Displays the html for a settings field
		 *
		 * @param array $args settings field args
		 *
		 * @return string
		 */
		function callback_html( $args ) {
			echo $this->get_field_description( $args );
		}


		/**
		 * Displays a file upload field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_file( $args ) {

			$label = isset( $args['options']['btn'] )
				? $args['options']['btn']
				: __( 'Select' );

			$html = sprintf( '<input type="url" class="%1$s-text wpsa-url" id="%2$s[%3$s]" name="%5$s" value="%4$s"/>', $args['size'], $args['tab'], $args['id'], $args['value'], $args['name'] );
			$html .= '<input type="button" class="button boospot-browse-button" value="' . $label . '" />';
			$html .= $this->get_field_description( $args );

			echo $html;
		}


		/**
		 * Generate: Uploader field
		 *
		 * @param array $args
		 *
		 * @source: https://mycyberuniverse.com/integration-wordpress-media-uploader-plugin-options-page.html
		 */
		public function callback_media( $args ) {

			// Set variables
			$default_image = isset( $args['default'] ) ? esc_url_raw( $args['default'] ) : 'https://www.placehold.it/115x115';
			$max_width     = isset( $args['options']['max_width'] ) ? absint( $args['options']['max_width'] ) : 150;
			$width         = isset( $args['options']['width'] ) ? absint( $args['options']['width'] ) : '';
			$height        = isset( $args['options']['height'] ) ? absint( $args['options']['height'] ) : '';
			$text          = isset( $args['options']['btn'] ) ? sanitize_text_field( $args['options']['btn'] ) : __( 'Upload' );


			$image_size = ( ! empty( $width ) && ! empty( $height ) ) ? array( $width, $height ) : 'thumbnail';

			if ( ! empty( $args['value'] ) ) {
				$image_attributes = wp_get_attachment_image_src( $args['value'], $image_size );
				$src              = $image_attributes[0];
				$value            = $args['value'];
			} else {
				$src   = $default_image;
				$value = '';
			}

			$image_style = ! is_array( $image_size ) ? "style='max-width:100%; height:auto;'" : "style='width:{$width}px; height:{$height}px;'";

			$max_width = $max_width . "px";
			// Print HTML field
			echo '
                <div class="upload" style="max-width:' . $max_width . ';">
                    <img data-src="' . $default_image . '" src="' . $src . '" ' . $image_style . '/>
                    <div>
                        <input type="hidden" name="' . $args['name'] . '" id="' . $args['name'] . '" value="' . $value . '" />
                        <button type="submit" class="boospot-image-upload button">' . $text . '</button>
                        <button type="submit" class="boospot-image-remove button">&times;</button>
                    </div>
                </div>
            ';

			$this->get_field_description( $args );

			// free memory
			unset( $default_image, $max_width, $width, $height, $text, $image_size, $image_style, $value );

		}

		/**
		 * Displays a password field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_password( $args ) {

			$html = sprintf( '<input type="password" class="%1$s-text" id="%2$s[%3$s]" name="%5$s" value="%4$s"/>', $args['size'], $args['tab'], $args['id'], $args['value'], $args['name'] );
			$html .= $this->get_field_description( $args );

			echo $html;
		}

		/**
		 * Displays a color picker field for a settings field
		 *
		 * @param array $args settings field args
		 */
		function callback_color( $args ) {
			$html = sprintf( '<input type="text" class="%1$s-text wp-color-picker-field" data-alpha="true" id="%2$s[%3$s]" name="%6$s" value="%4$s" data-default-color="%5$s" />', $args['size'], $args['tab'], $args['id'], $args['value'], $args['default'], $args['name'] );
			$html .= $this->get_field_description( $args );

			echo $html;
		}


		/**
		 * Displays a select box for creating the pages select box
		 *
		 * @param array $args settings field args
		 */
		function callback_pages( $args ) {
			$size          = $args['size'];
			$css_classes   = $args['class'];
			$dropdown_args = array(
				'selected'         => $args['value'],
				'name'             => $args['name'],
				'id'               => $args['tab'] . '[' . $args['id'] . ']',
				'echo'             => 1,
				'show_option_none' => '-- ' . __( 'Select' ) . ' --',
				'class'            => "{$size}-text $css_classes", // string
			);
			wp_dropdown_pages( $dropdown_args );

		}

		function callback_posts( $args ) {
			$default_args = array(
				'post_type'   => 'post',
				'numberposts' => - 1
			);

			$posts_args = wp_parse_args( $args['options'], $default_args );

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
			$args['options'] = $options;

			$this->callback_select( $args );

		}

		/**
		 * Show navigations as tab
		 *
		 * Shows all the settings tab labels as tab
		 */
		function show_navigation() {

			$settings_page = $this->get_default_settings_url();

			$count = count( $this->fields_tabs );

			// don't show the navigation if only one tab exists
			if ( $count === 1 ) {
				return;
			}


			$html = '<h2 class="nav-tab-wrapper">';

			foreach ( $this->fields_tabs as $tab ) {
				$active_class = ( $tab['id'] == $this->active_tab ) ? 'nav-tab-active' : '';
				$html         .= sprintf( '<a href="%3$s&tab=%1$s" class="nav-tab %4$s" id="%1$s-tab">%2$s</a>', $tab['id'], $tab['title'], $settings_page, $active_class );
			}

			$html .= '</h2>';

			echo $html;
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
                            self.prev('.wpsa-url').val(attachment.url).change();
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
                        if (answer == true) {
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

		public function get_fields_tabs() {

			return ( ! empty( $this->fields_tabs ) ) ? $this->fields_tabs : array();

		}

		public function get_tabs_fields() {

			return $this->tabs_fields;
		}

		public function get_tabs_fields_ids() {

			foreach ( $this->tabs_fields as $tabs_fields ) {
				foreach ( $tabs_fields as $field ) {
					$this->fields_ids[] = $field['id'];
				}
			}

			return $this->fields_ids;
		}


	}

endif;