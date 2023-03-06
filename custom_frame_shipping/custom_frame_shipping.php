<?php

/**
 * Plugin Name: Custom Frame Shipping Calculator
 * Plugin URI: https://github.com/issmith962/CustomFrameShippingCalculator
 * Author: Isaac Smith
 * Author URI: https://github.com/issmith962/CustomFrameShippingCalculator
 * Description: Uses the UPS Shipping API to estimate the shipping costs for frame packages of various custom sizes. 
 * Version: 0.0.1
 */
 
 add_action( 'woocommerce_shipping_init', 'custom_frame_shipping_init' );
 
 function custom_frame_shipping_init() {
     if ( ! class_exists( 'WC_CUSTOM_FRAME_SHIPPING') ) {
         class WC_CUSTOM_FRAME_SHIPPING extends WC_Shipping_Method {
            private static $UPS_TOKEN = ''; 
            private const TEST_BASE_PATH = 'https://wwwcie.ups.com/'; 
            private const UPS_BASE_PATH = 'https://onlinetools.ups.com/'; 
            private const RATE_API_VERSION = 'v2205'; 

            public function __construct($instance_id = 0) {
                parent::__construct($instance_id); 
                $this->id                 = 'custom_frame_shipping'; 
				$this->method_title       = ( 'Custom Frame Shipping' );  // Title shown in admin
				$this->method_description = ( 'Custom Frame Shipping Calculator:  Uses the UPS Shipping API to estimate the shipping costs for frame packages of various custom sizes.'); // Description shown in admin
                $this->supports = array(
                    'shipping-zones', 
                    'settings', 
                    'instance-settings',
                    'instance-settings-model', 
                ); 

				$this->enabled            = "yes"; // This can be added as an setting but hardcoded here
				$this->title              = "Ground"; // This can be added as an setting but hardcoded here

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
                $frames = array(); 
                $destination_details = $package['destination']; 
                
                foreach ($package['contents'] as $items) {
                    $_product = $items['data'];
                    for ($i = 0; $i < $items['quantity']; $i++) {
                        $frame = array(
                            'price' => $_product->get_price(), 
                            'weight' => $_product->get_weight(),
                            'height' => $_product->get_height(),
                            'length' => $_product->get_length(),
                            'width' => $_product->get_width(),
                        ); 
                        array_push($frames, $frame);  
                    }
                }

                $boxes = array(); 
                foreach($frames as $frame) {
                    array_push($boxes, WC_CUSTOM_FRAME_SHIPPING::get_box_details($frame));   
                }

                $total_shipping_cost = 0; 
                foreach ($boxes as $box) {
                    $item_shipping_cost = WC_CUSTOM_FRAME_SHIPPING::get_shipping_cost($box, $destination_details); 
                    if ($item_shipping_cost == null) {
                        return null; 
                    }

                    $total_shipping_cost += $item_shipping_cost; 
                }

                $rate = array(
					'label' => $this->title,
					'cost' => $total_shipping_cost, 
					'calc_tax' => 'per_item'
				);

				// Register the rate
				$this->add_rate( $rate );
            }
            
            private static function get_box_details($frame) {
                // Include padding offsets for packaging. 
                return array(
                    'length' => $frame['length'] + FRAME_PADDING_LENGTH_OFFSET,
                    'width' => $frame['width'] + FRAME_PADDING_WIDTH_OFFSET,
                    'height' => $frame['height'] + FRAME_PADDING_HEIGHT_OFFSET, 
                    'weight' => $frame['weight'], 
                    'price' => $frame['price'], 
                ); 
            }

            private function get_shipping_cost($box, $destination) {
                // If we don't have a current token, get one
                if (WC_CUSTOM_FRAME_SHIPPING::$UPS_TOKEN == null || WC_CUSTOM_FRAME_SHIPPING::$UPS_TOKEN == '') {
                    $this->request_ups_token(); 
                }

                $error_message = null; 
                $rate_quote = $this->get_rate_quote($box, $destination, $error_message); 

                // If not successful, try one more time just in case the request token was expired
                if ($error_message != null) {
                    error_log(print_r($error_message)); 
                    $this->request_ups_token();     
                    $rate_quote = $this->get_rate_quote($box, $destination, $error_message); 

                    // If it failed again, log the error before exiting...  
                    if ($error_message != null) {
                        error_log(print_r($error_message));
                        return null;   
                    }
                }

                return max($rate_quote, MINIMUM_SHIPPING_CHARGE);  
            }

            private function request_ups_token() {
                // Request an OAuth token from UPS
                $url = self::UPS_BASE_PATH . '/security/v1/oauth/token';
                $response = wp_remote_post($url, array(
                    'method' => 'POST', 
                    'headers' => array(
                        'Authorization' => 'Basic ' . UPS_BASIC_AUTHORIZATION, 
                        'accept' => 'application/json', 
                        'x-merchant-id' => UPS_MERCHANT_ID, 
                        'Content-Type' => 'application/x-www-form-urlencoded', 
                    ), 
                    'body' => array(
                        'grant_type' => 'client_credentials', 
                    ), 
                )); 

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message(); 
                    WC_CUSTOM_FRAME_SHIPPING::$UPS_TOKEN = ''; 
                    sleep(1); 
                } else {
                    $newToken = json_decode($response['body'], TRUE)['access_token'];
                    WC_CUSTOM_FRAME_SHIPPING::$UPS_TOKEN = $newToken; 
                }
            }

            private function get_rate_quote($box, $destination, &$error_message) {
                $error_message = null; 

                $url = self::UPS_BASE_PATH . 'api/rating/' . self::RATE_API_VERSION . '/rate'; 
                $request_body = array(
                    'RateRequest' => array(
                        'Shipment' => array(
                            'Shipper' => array(
                                'Address' => array(
                                    'AddressLine' => array(
                                        WC()->countries->get_base_address(), 
                                        WC()->countries->get_base_address_2(), 
                                    ), 
                                    'City' => WC()->countries->get_base_city(), 
                                    'StateProvinceCode' => WC()->countries->get_base_state(), 
                                    'PostalCode' => WC()->countries->get_base_postcode(), 
                                    'CountryCode' => WC()->countries->get_base_country(), 
                                ), 
                            ), 
                            'ShipFrom' => array(
                                'Address' => array(
                                    'AddressLine' => array(
                                        WC()->countries->get_base_address(), 
                                        WC()->countries->get_base_address_2(), 
                                    ), 
                                    'City' => WC()->countries->get_base_city(), 
                                    'StateProvinceCode' => WC()->countries->get_base_state(), 
                                    'PostalCode' => WC()->countries->get_base_postcode(), 
                                    'CountryCode' => WC()->countries->get_base_country(), 
                                ), 
                            ), 
                            'ShipTo' => array(
                                'Address' => array(
                                    'AddressLine' => array(
                                        $destination['address'], 
                                        $destination['address_1'], 
                                        $destination['address_2'], 
                                    ), 
                                    'City' => $destination['city'], 
                                    'StateProvinceCode' => $destination['state'], 
                                    'PostalCode' => $destination['postcode'], 
                                    'CountryCode' => $destination['country'], 
                                ), 
                            ), 
                            'Service' => array(
                                'Code' => '03', 
                                'Description' => 'Ground', 
                            ), 
                            'NumOfPieces' => '1', 
                            'Package' => array(
                                'PackagingType' => array(
                                    'Code' => '02', 
                                    'Description' => 'Packaging', 
                                ), 
                                'Dimensions' => array(
                                    'UnitOfMeasurement' => array(
                                        'Code' => 'IN', 
                                        'Description' => 'Inches',
                                    ), 
                                    'Length' => strval($box['length']), 
                                    'Width' => strval($box['width']), 
                                    'Height' => strval($box['height']), 
                                ), 
                                'PackageWeight' => array(
                                    'UnitOfMeasurement' => array(
                                        'Code' => 'LBS', 
                                        'Description' => 'Pounds', 
                                    ), 
                                    'Weight' => strval($box['weight']), 
                                ), 
                            ), 
                        ), 
                    ), 
                ); 

                $response = wp_remote_post($url, array(
                    'method' => 'POST', 
                    'headers' => array(
                        'Content-Type' => 'application/json', 
                        'Authorization' => 'Bearer ' . WC_CUSTOM_FRAME_SHIPPING::$UPS_TOKEN, 
                        'transactionSrc' => 'Custom Framing', 
                    ), 
                    'body' => json_encode($request_body), 
                ));
                
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message(); 
                    return -1; 
                }

                $rate_response = json_decode($response['body'], TRUE)['RateResponse'];
                if ($rate_response['Response']['ResponseStatus']['Code'] != '1') {
                    // Return the amount as -1, just to temporarily distinguish from free shipping. 
                    $error_message = $rate_response['Response']['ResponseStatus']['Description']; 
                    return -1; 
                } else {
                    $rate = $rate_response['RatedShipment']['TotalCharges']['MonetaryValue'];
                    return floatval($rate);
                }
            }
         }
     }
 }
 
 add_filter( 'woocommerce_shipping_methods', 'add_custom_frame_shipping_method');
 
 function add_custom_frame_shipping_method( $methods ) {
    $methods['custom_frame_shipping'] = 'WC_CUSTOM_FRAME_SHIPPING';
    return $methods;
 }