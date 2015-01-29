<?php

if( !defined('ABSPATH') ) exit;

if( !class_exists('order_export_process') ) {

	class order_export_process {

		/**
		 * Tells which fields to export
		 */
		static function export_options() {

			global $wpg_order_columns;
			$fields = array();

			foreach( $wpg_order_columns as $key=>$val ) {

				$retireve = get_option( $key, 'yes' );
				$fields[$key] = ( strtolower($retireve) === 'yes' ) ? true : false;
			}
			
			return $fields;
		}

		/**
		 * Returns order details
		 */
		static function get_orders() {

			$fields		=	self::export_options();
			$headings	=	self::csv_heading($fields);

			$args = array( 'post_type'=>'shop_order', 'posts_per_page'=>-1, 'post_status'=> apply_filters( 'wpg_order_statuses', array_keys( wc_get_order_statuses() ) ) );
			$args['date_query'] = array( array( 'after'=>  filter_input( INPUT_POST, 'start_date', FILTER_DEFAULT ), 'before'=> filter_input( INPUT_POST, 'end_date', FILTER_DEFAULT ), 'inclusive' => true ) );

			$orders = new WP_Query( $args );

			if( $orders->have_posts() ) {

				/**
				 * This will be file pointer
				 */
				$csv_file = self::create_csv_file();
				
				if( empty($csv_file) ) {
					return new WP_Error( 'not_writable', __( 'Unable to create csv file, upload folder not writable' ) );
				}

				fputcsv( $csv_file, $headings );

				while( $orders->have_posts() ) {

					$csv_values = array();

					$orders->the_post();
					$order_details = new WC_Order( get_the_ID() );

					/**
					 * Check if we need customer name.
					 */
					if( !empty( $fields['wc_settings_tab_customer_name'] ) && $fields['wc_settings_tab_customer_name'] === true )
						array_push( $csv_values, self::customer_name( get_the_ID() ) );

					/**
					 * Check if we need product info.
					 */
					if( !empty( $fields['wc_settings_tab_product_info'] ) && $fields['wc_settings_tab_product_info'] === true )
						array_push( $csv_values, self::product_info( $order_details ) );

					/**
					 * Check if we need product info.
					 */
					if( !empty( $fields['wc_settings_tab_amount'] ) && $fields['wc_settings_tab_amount'] === true )
						array_push( $csv_values, $order_details->get_total() );
					
					/**
					 * Check if we need product info.
					 */
					if( !empty( $fields['wc_settings_tab_customer_email'] ) && $fields['wc_settings_tab_customer_email'] === true )
						array_push( $csv_values, self::customer_meta( get_the_ID(), '_billing_email' ) );

					/**
					 * Check if we need product info.
					 */
					if( !empty( $fields['wc_settings_tab_customer_email'] ) && $fields['wc_settings_tab_customer_email'] === true )
						array_push( $csv_values, self::customer_meta( get_the_ID(), '_billing_phone' ) );

					if( !empty( $fields['wc_settings_tab_order_status'] ) && $fields['wc_settings_tab_order_status'] === true ){
						array_push( $csv_values, ucwords($order_details->get_status()) );
					}

					/**
					 * Perform some action before writing to csv.
					 * Callback functions hooked to this action should accept a reference pointer to $csv_values.
					 */
					do_action_ref_array( 'wpg_before_csv_write', array( &$csv_values ) );

					fputcsv( $csv_file, $csv_values );
				}
				wp_reset_postdata();
			}else {

				return new WP_Error( 'no_orders', __( 'No orders for specified duration.' ) );
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

		/**
		 * Returns list of product names for an order
		 * @param type $order_details
		 * @return string.
		 */
		static function product_info( $order_details ) {
			
			if( !is_a( $order_details, 'WC_Order' ) ){
				return '';
			}
			
			$items_list = array();
			
			$items = $order_details->get_items();
			
			if ( !empty( $items ) ) {
				
				foreach( $items as $item ) {
					
					$item_name = (string)$item['qty']. ' '.$item['name'];
					
					if( !empty( $item['variation_id'] ) ) {
						$variation_data = new WC_Product_Variation( $item['variation_id']);
						$variation_detail = woocommerce_get_formatted_variation( $variation_data->variation_data, true );
						
						$item_name .= ' ( '.$variation_detail.' )';
					}
					
					array_push($items_list, $item_name);
				}
			}
			
			return $items_list = implode( ', ', $items_list);
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
		static function csv_heading( $fields ) {

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

			return $headings;

		}

		/**
		 * Creates csb file in upload directory.
		 */
		static function create_csv_file() {

			$upload_dir = wp_upload_dir();
			return $csv_file = fopen( $upload_dir['basedir']. '/order_export.csv', 'w+');
		}
	}
}