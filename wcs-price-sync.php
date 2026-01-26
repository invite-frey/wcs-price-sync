<?php
/*
Plugin Name: WCS Price Sync with Renewal Buffer
Plugin URI: https://invite.hk
Description: Automatically updates subscription prices to match current product prices when the product is updated, but only if there's a buffer time before the renewal reminder (based on your configured reminder timing). This prevents price changes after reminders are sent or imminent.
Version: 1.4
Author: Frey Mansikkaniemi
Author URI: https://frey.hk
License: GPL v2 or later
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}


/**
 * Admin notice: WooCommerce missing
 */
function its_wcs_price_sync_admin_notice_woocommerce_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Price Sync with Renewal Buffer</strong> requires <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a> 
            to be installed and activated.
        </p>
    </div>
    <?php
}

/**
 * Admin notice: Subscriptions missing
 */
function its_wcs_price_sync_admin_notice_subscriptions_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Price Sync with Renewal Buffer</strong> requires <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a> 
            (premium) to be installed and activated.
        </p>
    </div>
    <?php
}

add_action( 'plugins_loaded', 'its_wcs_price_sync_init', 20 );
function its_wcs_price_sync_init() {

    // 1. WooCommerce check
    if ( ! function_exists( 'WC' ) || ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'its_wcs_price_sync_admin_notice_woocommerce_missing' );
        return;
    }

    // 2. WooCommerce Subscriptions check
    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        add_action( 'admin_notices', 'its_wcs_price_sync_admin_notice_subscriptions_missing' );
        return;
    }

    // Add setting to WooCommerce Subscriptions settings page.
    add_filter( 'woocommerce_subscription_settings', 'wcs_add_price_buffer_setting' );
    function wcs_add_price_buffer_setting( $settings ) {
        $settings[] = array(
            'name'     => __( 'Price Update Buffer Days', 'wcs-price-sync' ),
            'desc'     => __( 'Number of days before the next payment date to lock in prices and prevent updates. Set this to match your Renewal Reminder Timing (e.g., if reminders are sent 7 days before renewal, set to 7).', 'wcs-price-sync' ),
            'id'       => 'wcs_price_buffer_days',
            'type'     => 'number',
            'default'  => '0',
            'desc_tip' => true,
        );
        return $settings;
    }
    
    // Hook into product save to sync prices with buffer check.
    add_action( 'woocommerce_before_product_object_save', 'wcs_sync_subscription_prices_with_buffer', 20, 2 );
    function wcs_sync_subscription_prices_with_buffer( $product, $data_store ) {

        if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! WC_Subscriptions_Product::is_subscription( $product->get_id() ) ) {
            return; // Only subscription products.
        }
        $post_id = $product->get_id();

        $transient_key = 'wc_price_change_lock_' . $post_id;
        $already_processed = get_transient( $transient_key );
        $new_price = $product->get_price('edit');
    
        if ( $already_processed ) {
            $buffer_days = (int) get_option( 'wcs_price_buffer_days', 0 );
            $subscriptions = wcs_get_subscriptions( 
                array(
                    'product_id'           => $post_id,
                    'subscription_status'  => 'active',
                    'subscriptions_per_page' => -1,
                ) 
            );
            if ( empty( $subscriptions ) ) {
                return;
            }
            $current_time = current_time( 'timestamp', true );
            foreach ( $subscriptions as $subscription ) {
                $next_payment_time = $subscription->get_time( 'next_payment' );
                if ( ! $next_payment_time ) {
                    continue;
                }
                $buffer_time = $next_payment_time - ( $buffer_days * DAY_IN_SECONDS );
                if ( $current_time >= $buffer_time ) {
                    continue;
                }
                foreach ( $subscription->get_items() as $item_id => $item ) {
                    if ( $item->get_product_id() == $post_id ) {
                        $quantity = $item->get_quantity();
                        $subscription->remove_item( $item_id );
                        $subscription->add_product( $product, $quantity );
                        break;
                    }
                }
                $subscription->calculate_taxes();
                $subscription->calculate_totals();
                $subscription->save();
                $subscription->add_order_note( __( 'Subscription price updated to match current product price.', 'wcs-price-sync' ) );
            }
            delete_transient( $transient_key );
        } else {
            set_transient( $transient_key, '1', 30 );
        }
    }

    // Add custom bulk action using JavaScript
    add_action( 'admin_footer-edit.php', 'wcs_add_price_sync_bulk_action_script' );
    
    function wcs_add_price_sync_bulk_action_script() {
        global $post_type;
        
        if ( 'shop_subscription' !== $post_type ) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('<option>').val('wcs_sync_prices').text('<?php _e( 'Sync Prices (with Buffer)', 'wcs-price-sync' ); ?>').appendTo("select[name='action']");
                $('<option>').val('wcs_sync_prices').text('<?php _e( 'Sync Prices (with Buffer)', 'wcs-price-sync' ); ?>').appendTo("select[name='action2']");
            });
        </script>
        <?php
    }

    // Intercept the request early - before WooCommerce redirects
    add_action( 'admin_init', 'wcs_intercept_bulk_action', 1 );
    
    function wcs_intercept_bulk_action() {
        // Check if this is our bulk action
        if ( ! isset( $_REQUEST['action'] ) && ! isset( $_REQUEST['action2'] ) ) {
            return;
        }
        
        $action = '';
        if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'wcs_sync_prices' ) {
            $action = 'wcs_sync_prices';
        } elseif ( isset( $_REQUEST['action2'] ) && $_REQUEST['action2'] === 'wcs_sync_prices' ) {
            $action = 'wcs_sync_prices';
        }
        
        if ( $action !== 'wcs_sync_prices' ) {
            return;
        }
        
        // Make sure we're on the right page
        if ( ! isset( $_REQUEST['post_type'] ) || $_REQUEST['post_type'] !== 'shop_subscription' ) {
            return;
        }
        
        // Security check
        if ( ! isset( $_REQUEST['post'] ) || ! is_array( $_REQUEST['post'] ) ) {
            error_log( "No posts selected" );
            return;
        }
        
        check_admin_referer( 'bulk-posts' );
        
        // Get selected subscription IDs
        $post_ids = array_map( 'intval', $_REQUEST['post'] );
        $buffer_days = (int) get_option( 'wcs_price_buffer_days', 0 );
        $current_time = current_time( 'timestamp', true );
        $updated_count = 0;

        foreach ( $post_ids as $subscription_id ) {
            
            $subscription = wcs_get_subscription( $subscription_id );
            if ( ! $subscription ) {
                error_log( "Failed to get subscription object for ID: {$subscription_id}" );
                continue;
            }
            
            if ( ! $subscription->has_status( 'active' ) ) {
                //Subscription is not active
                continue;
            }

            $next_payment_time = $subscription->get_time( 'next_payment' );
            if ( ! $next_payment_time ) {
                //Subscription has no next payment date
                continue;
            }

            $buffer_time = $next_payment_time - ( $buffer_days * DAY_IN_SECONDS );
            if ( $current_time >= $buffer_time ) {
                //Subscription is within buffer period
                continue;
            }

            $updated = false;

            foreach ( $subscription->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product ) {
                    error_log( "No product found for item in subscription {$subscription_id}" );
                    continue;
                }
                
                if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
                    error_log( "Product {$product->get_id()} is not a subscription product" );
                    continue;
                }

                $old_price = $item->get_total() / $item->get_quantity();
                $new_price = $product->get_price( 'edit' );
                $quantity  = $item->get_quantity();
                $item->set_subtotal( $new_price * $quantity );
                $item->set_total( $new_price * $quantity );
                $item->save();

                $updated = true;
            }

            if ( $updated ) {
                $subscription->calculate_totals( true );
                $subscription->save();
                $subscription->add_order_note( __( 'Manual price sync applied (with buffer respected).', 'wcs-price-sync' ) );
                $updated_count++;

            }
        }
        

        // Redirect back to the subscriptions list with success message
        $sendback = remove_query_arg( array( 'action', 'action2', 'post', '_wpnonce', '_wp_http_referer', 'bulk_action', '_wcs_product', '_payment_method', '_customer_user', 'paged', 's' ), wp_get_referer() );
        if ( ! $sendback ) {
            $sendback = admin_url( 'edit.php' );
            $sendback = add_query_arg( 'post_type', 'shop_subscription', $sendback );
            $sendback = add_query_arg( 'post_status', 'wc-active', $sendback );
        }
        $sendback = add_query_arg( 'wcs_price_sync_updated', $updated_count, $sendback );
        
        wp_redirect( $sendback );
        exit;
    }

    // Admin notice after bulk action
    add_action( 'admin_notices', 'wcs_price_sync_bulk_notice' );

    function wcs_price_sync_bulk_notice() {
        if ( ! isset( $_GET['wcs_price_sync_updated'] ) || ! is_admin() ) {
            return;
        }

        $count = intval( $_GET['wcs_price_sync_updated'] );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( _n( '%s subscription price synced.', '%s subscriptions prices synced.', $count, 'wcs-price-sync' ), $count ) )
        );
    }
}