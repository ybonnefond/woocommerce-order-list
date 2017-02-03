<?php

if( !defined('ABSPATH') ) {
	exit;
}

if( !class_exists('order_export_process') ) {

	class order_export_process {

		static $delimiter;

		/**
		 * Tells which fields to export.
		 *
		 * Also reset orders of fields according to which they were added
		 * in plugin.
		 *
		 * Refer this support link: https://wordpress.org/support/topic/change-order-5?replies=7#post-6818741
		 */
		static function export_options() {

			global $wpg_order_columns;

			$settings = wpg_order_export::get_settings_fields();
			$settings = apply_filters( 'wpg_export_options_settings', $settings );

			$fields = array();

			$setting_order = array();

			if( is_array( $settings ) ) {

				foreach( $settings as $setting ) {

					if( !is_array( $setting ) || empty($setting['id']) ){
						continue;
					}

					array_push( $setting_order, $setting['id'] );
				}

				$new_order = array();

				foreach( $setting_order as $_setting ) {

					if( empty( $wpg_order_columns[$_setting] ) ){
						continue;
					}

					$new_order[$_setting] = $wpg_order_columns[$_setting];
				}

				$wpg_order_columns = $new_order;
			}

			foreach( $wpg_order_columns as $key=>$val ) {

				$retireve = get_option( $key, 'no' );
				$fields[$key] = ( strtolower($retireve) === 'yes' ) ? true : false;
			}

			return $fields;
		}

		static function get_products() {
			$products = [];
			$args = array(
				'post_type' => 'product',
				'posts_per_page' => -1
			);

			$loop = new WP_Query( $args );

			while ( $loop->have_posts() ) : $loop->the_post();
				global $product;
				$products[$product->get_id()] = [
					'title' => $product->get_title()
				];
			endwhile;

			wp_reset_postdata();
			return $products;
		}

		/**
		 * Returns order details
		 */
		static function get_orders() {
			$products = static::get_products();

			$fields		=	self::export_options();
			$fields		=	array_filter( $fields, 'wpg_array_filter' );

			$headings	=	self::csv_heading($fields, $products);

			$delimiter	=	( empty( $_POST['wpg_delimiter'] ) || ( gettype( $_POST['wpg_delimiter'] ) !== 'string' ) ) ? ',' : $_POST['wpg_delimiter'][0];

			/**
			 * Filter : wpg_delimiter
			 * Filters the delimiter for exported csv file. Override user defined
			 * delimiter by using this filter.
			 */
			self::$delimiter = apply_filters( 'wpg_delimiter', $delimiter );

			/* Check which order statuses to export. */
			$order_statuses	=	( !empty( $_POST['order_status'] ) && is_array( $_POST['order_status'] ) ) ? $_POST['order_status'] : array_keys( wc_get_order_statuses() );

			$args = array( 'post_type'=>'shop_order', 'posts_per_page'=>-1, 'post_status'=> apply_filters( 'wpg_order_statuses', $order_statuses ) );
			$args['date_query'] = array( array( 'after'=>  filter_input( INPUT_POST, 'start_date', FILTER_DEFAULT ), 'before'=> filter_input( INPUT_POST, 'end_date', FILTER_DEFAULT ), 'inclusive' => true ) );

			$args = apply_filters( 'wsoe_query_args', $args );

			$orders = new WP_Query( $args );

			if( $orders->have_posts() ) {

				/**
				 * This will be file pointer
				 */
				$csv_file = self::create_csv_file();

				if( empty($csv_file) ) {
					return new WP_Error( 'not_writable', __( 'Unable to create csv file, upload folder not writable', 'woocommerce-order-list' ) );
				}

				static::addToFile( $csv_file, $headings);

				/**
				 * Loop over each order
				 */
				while( $orders->have_posts() ) {

					$csv_values = array();

					$orders->the_post();

					$order_details = new WC_Order( get_the_ID() );

					/**
					 * Check if we need to export product name.
					 * If yes, then create new row for each product.
					 */
					// if( array_key_exists( 'wc_settings_tab_product_name', $fields ) ) {

					self::add_fields_diff_row( $fields, $csv_values, $order_details );
					self::add_products_row($products, $csv_values, $order_details);
					static::addToFile( $csv_file, $csv_values);
				}
				wp_reset_postdata();

			}else {

				return new WP_Error( 'no_orders', __( 'No orders for specified duration.', 'woocommerce-order-list' ) );
			}
		}

		static function add_products_row($products, &$csv_values, $order_details) {
			$items = static::extract_order_products_quantities($order_details);
			foreach($products as $id => $product) {
				array_push( $csv_values, isset($items[$id]) ? $items[$id] : 0 );
			}
		}

		static function addToFile($csv_file, $csv_values) {
			array_walk($csv_values, function($value) {
				return '"' . str_replace('"', '', $value) . '"';
			});

			fputcsv( $csv_file, $csv_values, self::$delimiter );
		}

		static function extract_order_products_quantities($order_details) {
			$products = [];
			$lineItems = $order_details->get_items();
			foreach($lineItems as $lineItemId => $lineItem) {
				$productId = $lineItem['product_id'];
				if (!isset($products[$productId])) {
					$products[$productId] = 0;
				}
				$products[$productId] += $lineItem['qty'];
			}

			return $products;
		}

		/**
		 *
		 */
		static function add_fields_diff_row($fields, &$csv_values, $order_details) {

			/**
			 * Loop over fields and add value for corresponding field.
			 */
			foreach( $fields as $key =>$field ) {

				switch ( $key ) {

					/**
					 * Check if we need order ID.
					 */
					case 'wc_settings_tab_order_id':
						array_push( $csv_values, $order_details->get_order_number() );
					break;

					/**
					 * Check if we need customer name.
					 */
					case 'wc_settings_tab_customer_name':
						array_push( $csv_values, self::customer_name( get_the_ID() ) );
					break;

					/**
					 * Check if we need order amount.
					 */
					case 'wc_settings_tab_amount':
						array_push( $csv_values, $order_details->get_total() );
					break;

					/**
					 * Check if we need customer email.
					 */
					case 'wc_settings_tab_customer_email':
						array_push( $csv_values, self::customer_meta( get_the_ID(), '_billing_email' ) );
					break;

					/**
					 * Check if we need customer phone.
					 */
					case 'wc_settings_tab_customer_phone':
						array_push( $csv_values, 'tel: ' . self::customer_meta( get_the_ID(), '_billing_phone' ) );
					break;

					/**
					 * Check if we need order status.
					 */
					case 'wc_settings_tab_order_status':
						array_push( $csv_values, ucwords($order_details->get_status()) );
					break;

					default :
						/**
						 * Add values to CSV.
						 *
						 * @param array $csv_values Array of csv values, callback function should accept this argument by reference.
						 *
						 * @param Object $order_details WC_Order object
						 *
						 * @param String $key Current key in loop.
						 *
						 */
						do_action_ref_array( 'wpg_add_values_to_csv', array( &$csv_values, $order_details, $key, $fields, $item_id, $current_item ) );
					break;
				}
			}
		}

		/**
		 * Returns customer related meta.
		 * Basically it is just get_post_meta() function wrapper.
		 */
		static function customer_meta( $order_id , $meta = '' ) {

			if( empty( $order_id ) || empty( $meta ) )
				return '';

			return get_post_meta( $order_id, $meta, true );
		}

		static function get_product_variation ( $product_id, $order_details ) {

			$metadata = $order_details->has_meta( $product_id );

			$exclude_meta = apply_filters( 'woocommerce_hidden_order_itemmeta', array(
				'_qty',
				'_tax_class',
				'_product_id',
				'_variation_id',
				'_line_subtotal',
				'_line_subtotal_tax',
				'_line_total',
				'_line_tax',
			) );

			$variation_details = array();

			foreach( $metadata as $k => $meta ) {

				if( in_array( $meta['meta_key'], $exclude_meta ) ){
					continue;
				}

				// Skip serialised meta
				if ( is_serialized( $meta['meta_value'] ) ) {
					continue;
				}

				// Get attribute data
				if ( taxonomy_exists( wc_sanitize_taxonomy_name( $meta['meta_key'] ) ) ) {

					$term               = get_term_by( 'slug', $meta['meta_value'], wc_sanitize_taxonomy_name( $meta['meta_key'] ) );
					$meta['meta_key']   = wc_attribute_label( wc_sanitize_taxonomy_name( $meta['meta_key'] ) );
					$meta['meta_value'] = isset( $term->name ) ? $term->name : $meta['meta_value'];

				}else {
					$meta['meta_key']   = apply_filters( 'woocommerce_attribute_label', wc_attribute_label( $meta['meta_key'], $_product ), $meta['meta_key'] );
				}

				array_push( $variation_details, wp_kses_post( urldecode( $meta['meta_key'] ) ) .': '.wp_kses_post( urldecode( $meta['meta_value'] ) ) );
			}

			return $variation_details = implode( ' | ', $variation_details );
		}

		/**
		 * Returns customer name for particular order
		 * @param type $order_id
		 * @return string
		 */
		static function customer_name( $order_id ) {

			if( empty( $order_id ) ){
				return '';
			}

			$firstname = get_post_meta( $order_id, '_billing_first_name', true );
			$lastname  = get_post_meta( $order_id, '_billing_last_name', true );

			return trim( $firstname.' '. $lastname );
		}

		/**
		 * Makes first row for csv
		 */
		static function csv_heading( $fields, $products ) {

			if( !is_array( $fields ) ){
				return false;
			}

			global $wpg_order_columns;
			$headings = array();

			foreach( $fields as $key=>$val ) {

				if( $val === true && array_key_exists( $key, $wpg_order_columns ) ){
					array_push( $headings, $wpg_order_columns[$key] );
				}
			}

			foreach($products as $id => $product) {
				array_push( $headings, $product['title']);
			}

			return $headings;

		}

		/**
		 * Creates csv file in upload directory.
		 */
		static function create_csv_file() {

			$upload_dir = wp_upload_dir();
			$csv_file = fopen( $upload_dir['basedir']. '/order_export.csv', 'w+');
			return $csv_file;
		}
	}
}
