<?php
/*
Plugin Name: WCS Price Sync with Renewal Buffer
Plugin URI: https://invite.hk
Description: Automatically updates subscription prices to match current product prices when the product is updated, but only if there's a buffer time before the renewal reminder (based on your configured reminder timing). This prevents price changes after reminders are sent or imminent.
Version: 1.0
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
    // WC_Subscriptions is the correct main class name (still true in recent versions)
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
        // Get new price (for simple products; adjust for variables if needed).

        $transient_key = 'wc_price_change_lock_' . $post_id;

        // Get a lock / flag (expires quickly)
        $already_processed = get_transient( $transient_key );

        $new_price = $product->get_price('edit');
    
        // Compare prices
        if ( $already_processed ) {
            //error_log( 'Processing price change' );

            // Proceed assuming price might have changed.
            $buffer_days = (int) get_option( 'wcs_price_buffer_days', 0 );
            // Get all active subscriptions with this product.
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
            $current_time = current_time( 'timestamp', true ); // UTC timestamp.
            foreach ( $subscriptions as $subscription ) {
                $next_payment_time = $subscription->get_time( 'next_payment' );
                if ( ! $next_payment_time ) {
                    continue; // No next payment.
                }
                $buffer_time = $next_payment_time - ( $buffer_days * DAY_IN_SECONDS );
                if ( $current_time >= $buffer_time ) {
                    // Within buffer period: skip update to lock in price.
                    continue;
                }
                // Update the line item.
                foreach ( $subscription->get_items() as $item_id => $item ) {
                    if ( $item->get_product_id() == $post_id ) {
                        $quantity = $item->get_quantity();
                        // Remove old item.
                        $subscription->remove_item( $item_id );
                        // Add new with current product data (pulls new price).
                        
                        $subscription->add_product( $product, $quantity );
                        break;
                    }
                }
                // Recalculate and save.
                $subscription->calculate_taxes();
                $subscription->calculate_totals();
                $subscription->save();
                // Optional: Add note.
                $subscription->add_order_note( __( 'Subscription price updated to match current product price.', 'wcs-price-sync' ) );
                // Optional: Trigger notification if you have a system for it.
                // e.g., do_action( 'wcs_subscription_price_updated', $subscription );
            }delete_transient( $transient_key );
        } else {
            // First call → set flag for next invocation (very short TTL)
            set_transient( $transient_key, '1', 30 ); // 30 seconds is plenty
        }
    }

    // Add custom bulk action
    add_filter( 'bulk_actions-edit-shop_subscription', 'wcs_add_price_sync_bulk_action' );

    function wcs_add_price_sync_bulk_action( $bulk_actions ) {
        $bulk_actions['wcs_sync_prices'] = __( 'Sync Prices (with Buffer)', 'wcs-price-sync' );
        return $bulk_actions;
    }

    // Handle the bulk action
    add_filter( 'handle_bulk_actions-edit-shop_subscription', 'wcs_handle_price_sync_bulk_action', 10, 3 );

    function wcs_handle_price_sync_bulk_action( $redirect_to, $action, $post_ids ) {
        if ( $action !== 'wcs_sync_prices' ) {
            return $redirect_to;
        }

        $buffer_days = (int) get_option( 'wcs_price_buffer_days', 0 );
        $current_time = current_time( 'timestamp', true );
        $updated_count = 0;

        foreach ( $post_ids as $subscription_id ) {
            $subscription = wcs_get_subscription( $subscription_id );
            if ( ! $subscription || ! $subscription->has_status( 'active' ) ) {
                continue;
            }

            $next_payment_time = $subscription->get_time( 'next_payment' );
            if ( ! $next_payment_time ) {
                continue;
            }

            $buffer_time = $next_payment_time - ( $buffer_days * DAY_IN_SECONDS );
            if ( $current_time >= $buffer_time ) {
                continue; // Skip if within buffer
            }

            $updated = false;

            foreach ( $subscription->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product || ! WC_Subscriptions_Product::is_subscription( $product ) ) {
                    continue;
                }

                $new_price = $product->get_price( 'edit' );
                $quantity  = $item->get_quantity();

                $item->set_subtotal( $new_price * $quantity );
                $item->set_total( $new_price * $quantity );
                $item->update_meta_data( '_line_subtotal', wc_format_decimal( $new_price * $quantity ) );
                $item->update_meta_data( '_line_total', wc_format_decimal( $new_price * $quantity ) );
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

        $redirect_to = add_query_arg(
            array(
                'wcs_price_sync_updated' => $updated_count,
            ),
            $redirect_to
        );

        return $redirect_to;
    }

    // Optional: Admin notice after bulk action
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