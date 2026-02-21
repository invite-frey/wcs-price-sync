<?php
/*
Plugin Name: WCS Price Sync
Plugin URI: https://github.com/invite-frey/wcs-price-sync-with-renewal-buffer
Description: Automatically updates subscription prices to match current product prices, with buffer protection to avoid changes too close to the next renewal. Includes a manual bulk-sync action on the subscriptions list.
Version: 3.0
Author: Invite Services / Frey Mansikkaniemi
Author URI: https://invite.hk
License: GPL v2 or later
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/* =========================================================================
 * ADMIN NOTICES
 * ====================================================================== */

function its_wcs_price_sync_admin_notice_woocommerce_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Price Sync</strong> requires
            <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>
            to be installed and activated.
        </p>
    </div>
    <?php
}

function its_wcs_price_sync_admin_notice_subscriptions_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Price Sync</strong> requires
            <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a>
            (premium) to be installed and activated.
        </p>
    </div>
    <?php
}


/* =========================================================================
 * HELPER: NOTIFICATION BUFFER
 *
 * Returns the WCS renewal-reminder offset in seconds. Used as a price-sync
 * buffer: we never update a subscription price inside this window so a
 * customer never receives a reminder quoting a different price to the one
 * that will actually be charged.
 * ====================================================================== */

function wcs_get_notification_buffer_seconds() {
    if ( ! class_exists( 'WC_Subscriptions_Admin' ) || ! class_exists( 'WC_Subscriptions_Email_Notifications' ) ) {
        return 0;
    }

    $setting_option = get_option(
        WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string,
        array(
            'number' => 3,
            'unit'   => 'days',
        )
    );

    if ( ! isset( $setting_option['unit'] ) || ! isset( $setting_option['number'] ) ) {
        return 3 * DAY_IN_SECONDS;
    }

    switch ( $setting_option['unit'] ) {
        case 'days':
            return $setting_option['number'] * DAY_IN_SECONDS;
        case 'weeks':
            return $setting_option['number'] * WEEK_IN_SECONDS;
        case 'months':
            return $setting_option['number'] * MONTH_IN_SECONDS;
        case 'years':
            return $setting_option['number'] * YEAR_IN_SECONDS;
        default:
            return 3 * DAY_IN_SECONDS;
    }
}


/* =========================================================================
 * PLUGIN INIT
 * ====================================================================== */

add_action( 'plugins_loaded', 'its_wcs_price_sync_init', 5 );

function its_wcs_price_sync_init() {

    if ( ! function_exists( 'WC' ) || ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'its_wcs_price_sync_admin_notice_woocommerce_missing' );
        return;
    }

    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        add_action( 'admin_notices', 'its_wcs_price_sync_admin_notice_subscriptions_missing' );
        return;
    }

    // Sync prices when a product is saved.
    add_action( 'woocommerce_before_product_object_save', 'wcs_sync_subscription_prices_with_buffer', 20, 2 );

    // Bulk-action UI and handler.
    add_action( 'admin_footer-edit.php',  'wcs_add_price_sync_bulk_action_script' );
    add_action( 'admin_init',             'wcs_intercept_bulk_action', 1 );
    add_action( 'admin_notices',          'wcs_price_sync_bulk_notice' );
}


/* =========================================================================
 * AUTOMATIC PRICE SYNC ON PRODUCT SAVE
 *
 * Double-save pattern: WooCommerce fires woocommerce_before_product_object_save
 * twice for a single admin "Update" click (once for the draft autosave, once
 * for the real save). We use a short-lived transient to ignore the first call
 * and only act on the second, which carries the definitive new price.
 * ====================================================================== */

function wcs_sync_subscription_prices_with_buffer( $product, $data_store ) {

    if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! WC_Subscriptions_Product::is_subscription( $product->get_id() ) ) {
        return;
    }

    $post_id       = $product->get_id();
    $transient_key = 'wc_price_change_lock_' . $post_id;

    if ( get_transient( $transient_key ) ) {

        // Second save — act now.
        $buffer_seconds = wcs_get_notification_buffer_seconds();

        $subscriptions = wcs_get_subscriptions( array(
            'product_id'             => $post_id,
            'subscription_status'    => 'active',
            'subscriptions_per_page' => -1,
        ) );

        if ( empty( $subscriptions ) ) {
            delete_transient( $transient_key );
            return;
        }

        $current_time = current_time( 'timestamp', true );

        foreach ( $subscriptions as $subscription ) {
            $next_payment_time = $subscription->get_time( 'next_payment' );
            if ( ! $next_payment_time ) {
                continue;
            }

            // Skip if within the notification buffer window.
            if ( $current_time >= ( $next_payment_time - $buffer_seconds ) ) {
                continue;
            }

            foreach ( $subscription->get_items() as $item_id => $item ) {
                if ( (int) $item->get_product_id() === (int) $post_id ) {
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
        // First save — set the lock and wait for the second.
        set_transient( $transient_key, '1', 30 );
    }
}


/* =========================================================================
 * BULK ACTION: SYNC PRICES (WITH BUFFER)
 * ====================================================================== */

function wcs_add_price_sync_bulk_action_script() {
    global $post_type;

    if ( 'shop_subscription' !== $post_type ) {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery( document ).ready( function ( $ ) {
            var label = '<?php echo esc_js( __( 'Sync Prices (with Buffer)', 'wcs-price-sync' ) ); ?>';
            $( '<option>' ).val( 'wcs_sync_prices' ).text( label ).appendTo( "select[name='action']" );
            $( '<option>' ).val( 'wcs_sync_prices' ).text( label ).appendTo( "select[name='action2']" );
        } );
    </script>
    <?php
}

function wcs_intercept_bulk_action() {

    $action = '';
    if ( isset( $_REQUEST['action'] ) && 'wcs_sync_prices' === $_REQUEST['action'] ) {
        $action = 'wcs_sync_prices';
    } elseif ( isset( $_REQUEST['action2'] ) && 'wcs_sync_prices' === $_REQUEST['action2'] ) {
        $action = 'wcs_sync_prices';
    }

    if ( 'wcs_sync_prices' !== $action ) {
        return;
    }

    if ( ! isset( $_REQUEST['post_type'] ) || 'shop_subscription' !== $_REQUEST['post_type'] ) {
        return;
    }

    if ( ! isset( $_REQUEST['post'] ) || ! is_array( $_REQUEST['post'] ) ) {
        return;
    }

    check_admin_referer( 'bulk-posts' );

    $post_ids       = array_map( 'intval', $_REQUEST['post'] );
    $buffer_seconds = wcs_get_notification_buffer_seconds();
    $current_time   = current_time( 'timestamp', true );
    $updated_count  = 0;

    foreach ( $post_ids as $subscription_id ) {

        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription || ! $subscription->has_status( 'active' ) ) {
            continue;
        }

        $next_payment_time = $subscription->get_time( 'next_payment' );
        if ( ! $next_payment_time ) {
            continue;
        }

        if ( $current_time >= ( $next_payment_time - $buffer_seconds ) ) {
            continue; // Within buffer — skip.
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

    $sendback = remove_query_arg(
        array( 'action', 'action2', 'post', '_wpnonce', '_wp_http_referer', 'bulk_action', '_wcs_product', '_payment_method', '_customer_user', 'paged', 's' ),
        wp_get_referer()
    );
    if ( ! $sendback ) {
        $sendback = add_query_arg( array( 'post_type' => 'shop_subscription', 'post_status' => 'wc-active' ), admin_url( 'edit.php' ) );
    }
    $sendback = add_query_arg( 'wcs_price_sync_updated', $updated_count, $sendback );

    wp_redirect( $sendback );
    exit;
}

function wcs_price_sync_bulk_notice() {
    if ( ! isset( $_GET['wcs_price_sync_updated'] ) || ! is_admin() ) {
        return;
    }

    $count = intval( $_GET['wcs_price_sync_updated'] );
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html( sprintf(
            _n( '%s subscription price synced.', '%s subscription prices synced.', $count, 'wcs-price-sync' ),
            $count
        ) )
    );
}
