<?php
/*
Plugin Name: mobo-core
Description: بروزرسانی خودکار محصولات از https://mobomobo.ir/
Version: 7.3
Author: Pedram Karimi
Author URI: http://github.com/PedramDev/
// Requires PHP: <=8.1.0
Plugin URI: https://github.com/PedramDev/moboplugin.com/releases/latest
Requires WooCommerce: >=6.0
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('MOBO_CORE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MOBO_CORE_WEBHOOK_FILE_DIR', plugin_dir_path(__FILE__) . 'webhook-files/');

require  __DIR__ . '/inc/index.php';
require  __DIR__ . '/pages/index.php';

$mobo_active_debug = intval(get_option('mobo_active_debug', false));

function trace_log($obj = null)
{
    global $mobo_active_debug;
    if ($mobo_active_debug) {
        $backtrace = debug_backtrace();
        $caller = $backtrace[0];
        
        if (isset($obj) && !empty($obj)) {
            // Ensure the object is logged as a string
            if (is_string($obj) || is_numeric($obj)) {
                error_log($obj);
            } else {
                error_log(print_r($obj, true)); // Log arrays/objects more clearly
            }
        } else {
            error_log('Trace log called from: ' . basename($caller['file']) . ' on line: ' . $caller['line']);
        }
    }
}

add_filter('http_request_args', function ($args) {
    $args['sslverify'] = false;
    return $args;
});


add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mobo_core_action_links');

function mobo_core_action_links($links)
{
    $settings_link = '<a href="options-general.php?page=mobo_core_admin">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add admin menu
add_action('admin_menu', 'mobo_core_admin_menu');

function mobo_core_admin_menu()
{
    add_menu_page('تنظیمات موبو', 'تنظیمات موبو', 'manage_options', 'mobo_core_admin', 'mobo_core_admin_page');
    add_submenu_page('mobo_core_admin', 'همگام سازی', 'همگام سازی', 'manage_options', 'mobo_core_sync', 'mobo_core_sync_page');
    add_submenu_page('mobo_core_admin', 'تعمیرات', 'تعمیرات', 'manage_options', 'mobo_core_fixer', 'mobo_core_fixer_page');
}



add_filter('cron_schedules', 'custom_cron_schedule');
function custom_cron_schedule($schedules)
{
    $schedules['mobo_core_product_interval'] = array(
        'interval' => 40,
        'display'  => 'Every 40 sec',
    );
    $schedules['mobo_core_categories_interval'] = array(
        'interval' => 600,
        'display'  => 'Every 500 sec',
    );
    $schedules['mobo_core_read_webhook_interval'] = array(
        'interval' => 60,
        'display'  => 'Every 60 sec',
    );
    return $schedules;
}

add_action('mobo_core_read_webhook_interval', 'mobo_core_read_webhook_interval');

if (!wp_next_scheduled('mobo_core_read_webhook_interval')) {
    // Schedule the event to run every minute
    wp_schedule_event(time(), 'mobo_core_read_webhook_interval', 'mobo_core_read_webhook_interval');
}


if (!wp_next_scheduled('mobo_core_sync_products_24_event')) {
    $timestamp = strtotime('2:00:00'); // Set time for 2 AM
    wp_schedule_event($timestamp , 'daily', 'mobo_core_sync_products_24_event');
}



function mobo_isLicenseExpired()
{
    $apiFunc = new \MoboCore\ApiFunctions(); // Replace with your API function class
    $info = $apiFunc->getLicenseInfo();

    if ($info['isExpired']) {
?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $info['message']; ?></p>
        </div>
    <?php

        return true;
    } else {
    ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo $info['message']; ?></p>
        </div>
<?php
        return false;
    }
}


#region DEBUG
add_action('woocommerce_before_single_product', function () {
    if ( ! is_product() ) {
        return;
    }

    global $product;

    if ( ! $product instanceof \WC_Product_Variable ) {
        return;
    }

    if ( $product->get_meta('product_guid') !== 'd6578ffc-21c9-4afa-aee8-7f4f624d5b98' ) {
        // Only debug this specific product
        return;
    }

    trace_log('=== VARIATION DEBUG START ===');
    foreach ( $product->get_children() as $child_id ) {
        $v = wc_get_product($child_id);
        trace_log(wc_print_r([
            'id'             => $child_id,
            'variant_guid'   => get_post_meta($child_id, 'variant_guid', true),
            'attrs'          => $v->get_attributes(),
            'regular_price'  => $v->get_regular_price(),
            'sale_price'     => $v->get_sale_price(),
            'price'          => $v->get_price(),
            'manage_stock'   => $v->get_manage_stock(),
            'stock_qty'      => $v->get_stock_quantity(),
            'stock_status'   => $v->get_stock_status(),
            'is_in_stock'    => $v->is_in_stock(),
            'is_purchasable' => $v->is_purchasable(),
        ], true));
    }
    trace_log('=== VARIATION DEBUG END ===');
});

#endregion