<?php

/**
 * Plugin Name: Custom Frame Shipping
 * Plugin URI: https://github.com/omukiguy/techiepress-dhl-shipping
 * Author: Isaac Smith
 * Author URI: https://github.com/omukiguy/techiepress-dhl-shipping
 * Description: Techipress DHL Shipping plugin
 * Version: 0.0.1
 */
 
 add_action( 'woocommerce_shipping_init', 'techiepress_dhl_shipping_init' );
 
 function techiepress_dhl_shipping_init() {
     if ( ! class_exists( 'WC_CUSTOM_FRAME_SHIPPING') ) {
         class WC_CUSTOM_FRAME_SHIPPING extends WC_Shipping_Method {
            
            public function __construct() {
                $this->id                 = 'custom_frame_shipping'; // Id for your shipping method. Should be uunique.
				$this->method_title       = __( 'Custom Frame Shipping' );  // Title shown in admin
				$this->method_description = __( 'Custom Frame Shipping: Wood boxes built by hand to perfectly suit the fragile nature of quality frames.' ); // Description shown in admin

				$this->enabled            = "yes"; // This can be added as an setting but hardcoded here
				$this->title              = "Custom-Built Box Shipping"; // This can be added as an setting but hardcoded here

				$this->init();
            }
            
            public function init() {
                // Load the settings API
				$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
				$this->init_settings(); // This is part of the settings API. Loads settings you previously init.

				// Save settings in admin if you have any defined
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            
            public function calculate_shipping( $package = array()) {
                $cost = 15;     // TEMPORARILY HARDCODED
                $dimensions_by_frame = array(); 
                $destination_details = $package['destination']; 

                foreach ($package['contents'] as $item_id => $values) {
                    $_product = $values['data'];
                    for ($i = 0; $i < $values['quantity']; $i++) {
                        array_push(
                            $dimensions_by_frame,
                            array(
                                'weight' => $_product->get_weight(),
                                'height' => $_product->get_height(),
                                'length' => $_product->get_length(),
                                'width' => $_product->get_width(),
                                'profile_width' => $_product->get_attributes()['profile_width']['options'][0]
                            )
                        );
                    }
                }
                
                // Currently using a temporary rate.. Calculate box shipping rate based on the dimensions of each frame here. 
                
                $rate = array(
					'label' => $this->title,
					'cost' => $cost, 
					'calc_tax' => 'per_item'
				);

				// Register the rate
				$this->add_rate( $rate );
                
            }
         }
     }
 }
 
 add_filter( 'woocommerce_shipping_methods', 'add_custom_frame_shipping_method');
 
 function add_custom_frame_shipping_method( $methods ) {
    $methods['custom_frame_shipping'] = 'WC_CUSTOM_FRAME_SHIPPING';
    return $methods;
 }