<?php
/*
Plugin Name: WCS Price Sync with Dual Renewal Notifications
Plugin URI: https://invite.hk
Description: Automatically updates subscription prices to match current product prices with buffer protection. Also extends WCS to send two renewal notifications: one at the configured timing and another at half that time.
Version: 2.2
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
            <strong>WCS Price Sync with Dual Renewal Notifications</strong> requires <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a> 
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
            <strong>WCS Price Sync with Dual Renewal Notifications</strong> requires <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a> 
            (premium) to be installed and activated.
        </p>
    </div>
    <?php
}

/**
 * Get the buffer time in seconds from WCS notification settings.
 * 
 * @return int Buffer time in seconds.
 */
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
        return 3 * DAY_IN_SECONDS; // Default to 3 days
    }

    switch ( $setting_option['unit'] ) {
        case 'days':
            return ( $setting_option['number'] * DAY_IN_SECONDS );
        case 'weeks':
            return ( $setting_option['number'] * WEEK_IN_SECONDS );
        case 'months':
            return ( $setting_option['number'] * MONTH_IN_SECONDS );
        case 'years':
            return ( $setting_option['number'] * YEAR_IN_SECONDS );
        default:
            return 3 * DAY_IN_SECONDS;
    }
}

/**
 * Initialize the extended notification scheduler class after WCS is fully loaded.
 */
function its_define_extended_notification_class() {
    // Only define the class if the parent class exists
    if ( ! class_exists( 'WCS_Action_Scheduler_Customer_Notifications' ) ) {
        error_log("WCS_Action_Scheduler_Customer_Notifications does not exist");
        return;
    }
    
    error_log("Extended class defined.");
    /**
     * Extended notification scheduler that adds a reminder notification at half the timing.
     */
    class WCS_Action_Scheduler_Customer_Notifications_Extended extends WCS_Action_Scheduler_Customer_Notifications {

        /**
         * Additional notification actions for the second reminder.
         */
        protected static $additional_notification_actions = [
            'woocommerce_scheduled_subscription_customer_notification_renewal_reminder',
        ];

        /**
         * Override to schedule both standard and reminder notifications.
         * 
         * @param WC_Subscription $subscription
         * @param string $notification_type
         */
        protected function schedule_notification( $subscription, $notification_type ) {
            error_log( "WCS Extended Scheduler: schedule_notification called for subscription {$subscription->get_id()}, type: {$notification_type}" );
            
            // Call parent to schedule the standard notification
            parent::schedule_notification( $subscription, $notification_type );

            // If this is a renewal notification, also schedule the reminder one
            if ( $notification_type === 'next_payment' ) {
                $this->schedule_reminder_renewal_notification( $subscription );
            }
        }

        /**
         * Schedule an reminder renewal notification at half the standard timing.
         *
         * @param WC_Subscription $subscription
         */
        protected function schedule_reminder_renewal_notification( $subscription ) {
            $subscription_id = $subscription->get_id();
            
            error_log( "WCS reminder Notification [{$subscription_id}]: schedule_reminder_renewal_notification called" );
            
            if ( ! WC_Subscriptions_Email_Notifications::notifications_globally_enabled() ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: Notifications globally disabled" );
                return;
            }

            if ( ! $subscription->has_status( [ 'active', 'pending-cancel' ] ) ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: Status is " . $subscription->get_status() );
                return;
            }

            if ( self::is_subscription_period_too_short( $subscription ) ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: Subscription period too short" );
                return;
            }

            $event_date = $subscription->get_date( 'next_payment' );
            if ( ! $event_date ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: No next payment date" );
                return;
            }

            // Get the standard notification timestamp
            $standard_timestamp = $this->subtract_time_offset( $event_date, $subscription, 'next_payment' );
            
            // Calculate reminder notification time (halfway between expiry and standard notification)
            $next_payment_time = $subscription->get_time( 'next_payment' );
            $time_before_renewal = $next_payment_time - $standard_timestamp;
            $reminder_timestamp = $standard_timestamp + ( $time_before_renewal / 2 );

            error_log( sprintf(
                "WCS reminder Notification [{$subscription_id}]: Next payment: %s, Standard notification: %s, reminder notification: %s, Time before renewal: %s seconds",
                date( 'Y-m-d H:i:s', $next_payment_time ),
                date( 'Y-m-d H:i:s', $standard_timestamp ),
                date( 'Y-m-d H:i:s', $reminder_timestamp ),
                $time_before_renewal
            ) );

            // Only schedule if it's in the future
            if ( $reminder_timestamp <= time() ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: reminder timestamp is in the past (current time: " . date('Y-m-d H:i:s') . ")" );
                return;
            }

            $action = 'woocommerce_scheduled_subscription_customer_notification_renewal_reminder';
            $action_args = self::get_action_args( $subscription );

            $next_scheduled = as_next_scheduled_action( $action, $action_args, self::$notifications_as_group );

            if ( $reminder_timestamp === $next_scheduled ) {
                error_log( "WCS reminder Notification [{$subscription_id}]: Already scheduled for this exact time" );
                return;
            }

            // Use the parent's unschedule method to clear old ones
            $this->unschedule_actions( $action, $action_args );
            
            // Schedule the new action
            $scheduled_id = as_schedule_single_action( $reminder_timestamp, $action, $action_args, self::$notifications_as_group );
            
            error_log( "WCS reminder Notification [{$subscription_id}]: Successfully scheduled action ID {$scheduled_id} for " . date( 'Y-m-d H:i:s', $reminder_timestamp ) );
        }

        /**
         * Override to handle reminder renewal notification cleanup.
         */
        public function unschedule_all_notifications( $subscription = null, $exceptions = [] ) {
            // Call parent
            parent::unschedule_all_notifications( $subscription, $exceptions );

            // Also unschedule our additional notification
            foreach ( self::$additional_notification_actions as $action ) {
                if ( in_array( $action, $exceptions, true ) ) {
                    continue;
                }

                $this->unschedule_actions( $action, self::get_action_args( $subscription ) );
            }
        }

        /**
         * Override to include reminder renewal notification in status updates.
         */
        public function update_status( $subscription, $new_status, $old_status ) {
            parent::update_status( $subscription, $new_status, $old_status );

            // Handle reminder renewal notification based on status
            switch ( $new_status ) {
                case 'active':
                    // reminder notification will be scheduled via maybe_schedule_notification
                    break;
                case 'pending-cancel':
                    // Unschedule reminder renewal notification
                    $this->unschedule_actions( 
                        'woocommerce_scheduled_subscription_customer_notification_renewal_reminder', 
                        self::get_action_args( $subscription ) 
                    );
                    break;
                case 'on-hold':
                case 'cancelled':
                case 'switched':
                case 'expired':
                case 'trash':
                    // Already handled by unschedule_all_notifications in parent
                    break;
            }
        }
    }

    // Make sure our extended class was defined
    if ( ! class_exists( 'WCS_Action_Scheduler_Customer_Notifications_Extended' ) ) {
        error_log( 'WCS Price Sync: Extended notification class not found. Cannot replace scheduler.' );
        return;
    }

    // Remove the default scheduler hooks

    $wcs_core = WC_Subscriptions_Core_Plugin::instance();

    if(! $wcs_core ){
        error_log("Unable to access WC_Subscriptions_Core_Plugin");
    }

    $default_scheduler = $wcs_core->notifications_scheduler ; //wcs_get_notification_scheduler();
    if ( $default_scheduler ) {
        remove_action( 'woocommerce_before_subscription_object_save', [ $default_scheduler, 'update_notifications' ], 10 );
    }
    
    // Get the extended scheduler instance
    $extended_scheduler = new WCS_Action_Scheduler_Customer_Notifications_Extended();
    
    // Replace it in the WCS system
    $wcs_core->notification_scheduler = $extended_scheduler;
    
    // Re-add the hook with our extended scheduler
    add_action( 'woocommerce_before_subscription_object_save', [ $extended_scheduler, 'update_notifications' ], 10, 2 );

}

add_action( 'plugins_loaded', 'its_wcs_price_sync_init', 5 );
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

    // 3. Define the extended class after WCS classes are loaded
    add_action( 'plugins_loaded', 'its_define_extended_notification_class', 10 );
    
    // Hook into product save to sync prices with buffer check.
    add_action( 'woocommerce_before_product_object_save', 'wcs_sync_subscription_prices_with_buffer', 20, 2 );
    
    // Add custom bulk action using JavaScript
    add_action( 'admin_footer-edit.php', 'wcs_add_price_sync_bulk_action_script' );
    
    // Intercept the request reminder - before WooCommerce redirects
    add_action( 'admin_init', 'wcs_intercept_bulk_action', 1 );
    
    // Admin notice after bulk action
    add_action( 'admin_notices', 'wcs_price_sync_bulk_notice' );

    // Hook the reminder renewal notification action to send the email
    add_action( 'woocommerce_scheduled_subscription_customer_notification_renewal_reminder', 'its_send_reminder_renewal_email', 10, 1 );

}


/**
 * Send the reminder renewal notification email.
 *
 * @param int $subscription_id
 */
function its_send_reminder_renewal_email( $subscription_id ) {
    $subscription = wcs_get_subscription( $subscription_id );
    
    if ( ! $subscription ) {
        return;
    }

    // Get the next payment date for the email
    $next_payment_date = $subscription->get_date( 'next_payment' );
    
    if ( ! $next_payment_date ) {
        return;
    }

    /**
     * Allow customization of the reminder renewal notification.
     * 
     * By default, this will use the same email template as the standard renewal notification,
     * but you can filter it to use a custom template or modify the content.
     */
    do_action( 'woocommerce_subscription_customer_notification_renewal_reminder', $subscription, $next_payment_date );
    
    // Fallback: If no custom handler is hooked, use the standard renewal notification
    if ( ! has_action( 'woocommerce_subscription_customer_notification_renewal_reminder' ) ) {
        do_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', $subscription_id );
    }
}

function wcs_sync_subscription_prices_with_buffer( $product, $data_store ) {

    if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! WC_Subscriptions_Product::is_subscription( $product->get_id() ) ) {
        return; // Only subscription products.
    }
    $post_id = $product->get_id();

    $transient_key = 'wc_price_change_lock_' . $post_id;
    $already_processed = get_transient( $transient_key );
    $new_price = $product->get_price('edit');

    if ( $already_processed ) {
        // Get buffer time from WCS notification settings
        $buffer_seconds = wcs_get_notification_buffer_seconds();
        
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
            $buffer_time = $next_payment_time - $buffer_seconds;
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
    
    // Get buffer time from WCS notification settings
    $buffer_seconds = wcs_get_notification_buffer_seconds();
    
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

        $buffer_time = $next_payment_time - $buffer_seconds;
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