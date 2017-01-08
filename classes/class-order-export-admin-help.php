<?php
/**
 * Add content to help tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if( !class_exists('wsoe_admin_help') ) :

	class wsoe_admin_help {

		/**
		 * Hook in tabs.
		 */
		public function __construct() {
			add_action( "current_screen", array( $this, 'add_tabs' ), 100 );
		}

		function add_tabs() {

			$screen = get_current_screen();
			$wc_screen_id = sanitize_title( __( 'WooCommerce', 'woocommerce' ) );
			$wsoe_tab = ( !empty( $_GET['tab'] ) && $_GET['tab'] == 'order_export' ) ? true : false;

			if( $screen->id === $wc_screen_id . '_page_wc-settings' && $wsoe_tab ) {

				$screen->add_help_tab( array(
					'id'        => 'wsoe_help_tab',
					'title'     => __( 'Order Export', 'woocommerce' ),
					'content'   =>

						'<p>'. __('Please use following steps to use the plugin.', 'woocommerce-order-list') .'</p>'.
						'<ul>'.
							'<li>'.__( 'Choose the fields you want to export.', 'woocommerce-order-list' ).'</li>'.
							'<li>'.__( 'Click Save Settings button at the bottom of the page.', 'woocommerce-order-list' ).'</li>'.
							'<li>'.__( 'Click Advanced Options to explore more controls.', 'woocommerce-order-list' ).'</li>'.
							'<li>'.__( 'Select the duration for which you want to export orders.', 'woocommerce-order-list' ).'</li>'.
							'<li>'.__( 'Click Export Order button.', 'woocommerce-order-list' ).'</li>'.
						'</ul>'

				) );
			}
		}

	}

	return new wsoe_admin_help();

endif;
