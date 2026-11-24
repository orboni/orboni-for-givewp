<?php

/**
 * Plugin Name:       Orboni for GiveWP
 * Plugin URI:        https://orboni.com/docs/givewp
 * Description:       Accept crypto donations — USDT, USDC, BTC, ETH and more — straight to your own wallets. Orboni never holds your money.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  give
 * Author:            Orboni
 * Author URI:        https://orboni.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orboni-for-givewp
 */
defined('ABSPATH') || exit;

define('ORBONI_GIVEWP_VERSION', '1.0.1');
define('ORBONI_GIVEWP_FILE', __FILE__);
define('ORBONI_GIVEWP_DIR', plugin_dir_path(__FILE__));
define('ORBONI_GIVEWP_URL', plugin_dir_url(__FILE__));

/** The GiveWP this gateway is written against: its gateway API, with the visual builder's forms. */
define('ORBONI_GIVEWP_MIN_GIVE', '3.0.0');

// Hooked as the file loads: GiveWP 3.x registers its gateways while it boots, before plugins_loaded reaches us.
add_action('givewp_register_payment_gateway', 'orboni_givewp_register_gateway');
add_action('plugins_loaded', 'orboni_givewp_init');

/** Loads the plugin's classes once GiveWP 3.0+ is there; false while it is not. */
function orboni_givewp_load()
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    if (! defined('GIVE_VERSION') || version_compare(GIVE_VERSION, ORBONI_GIVEWP_MIN_GIVE, '<')
        || ! class_exists('\Give\Framework\PaymentGateways\PaymentGateway')) {
        return $loaded = false;
    }

    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-log.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-api.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-signature.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-settings.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-sync.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-gateway.php';
    require_once ORBONI_GIVEWP_DIR.'includes/class-orboni-givewp-webhook.php';

    return $loaded = true;
}

/** @param  object  $registrar  GiveWP's PaymentGatewayRegister */
function orboni_givewp_register_gateway($registrar)
{
    if (orboni_givewp_load()) {
        $registrar->registerGateway(Orboni_GiveWP_Gateway::class);
    }
}

/** The settings, the webhook, the scheduled checks and the forms' gateway lists. */
function orboni_givewp_init()
{
    if (! orboni_givewp_load()) {
        add_action('admin_notices', 'orboni_givewp_missing_notice');

        return;
    }

    Orboni_GiveWP_Settings::register();
    Orboni_GiveWP_Gateway::register_hooks();
    Orboni_GiveWP_Webhook::register();
    Orboni_GiveWP_Sync::register();
}

/** Shown on the Plugins screen only, to whoever can activate plugins, while GiveWP is missing or older than 3.0. */
function orboni_givewp_missing_notice()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (! $screen || $screen->id !== 'plugins' || ! current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
        /* translators: %s: the least GiveWP version, e.g. 3.0.0 */
        __('Orboni for GiveWP needs GiveWP %s or later, active.', 'orboni-for-givewp'),
        ORBONI_GIVEWP_MIN_GIVE
    )).'</p></div>';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    $url = admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=orboni');
    array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'orboni-for-givewp').'</a>');

    return $links;
});
