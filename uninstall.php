<?php

// Deleted from the Plugins screen: the settings go (the keys with them).
// Donations keep their notes and Orboni's session ids, which are the charity's records.
defined('WP_UNINSTALL_PLUGIN') || exit;

$orboni_givewp_settings = get_option('give_settings');
if (is_array($orboni_givewp_settings)) {
    foreach (['orboni_live_secret_key', 'orboni_live_webhook_secret', 'orboni_test_secret_key', 'orboni_test_webhook_secret', 'orboni_debug'] as $orboni_givewp_key) {
        unset($orboni_givewp_settings[$orboni_givewp_key]);
    }
    // The option-based forms' list and the visual builder's.
    foreach (['gateways', 'gateways_v3', 'gateways_label', 'gateways_label_v3'] as $orboni_givewp_list) {
        if (isset($orboni_givewp_settings[$orboni_givewp_list]) && is_array($orboni_givewp_settings[$orboni_givewp_list])) {
            unset($orboni_givewp_settings[$orboni_givewp_list]['orboni']);
        }
    }
    update_option('give_settings', $orboni_givewp_settings);
}

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('orboni_givewp_check_donation', [], 'orboni');
}
wp_unschedule_hook('orboni_givewp_check_donation');
