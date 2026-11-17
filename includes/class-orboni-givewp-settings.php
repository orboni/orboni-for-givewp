<?php

defined('ABSPATH') || exit;

/**
 * Donations → Settings → Payment Gateways → Orboni. A live key and a test
 * key, as GiveWP's own gateways have: GiveWP's test mode decides which one a
 * new donation is made with, and the session's own id which one reads it
 * back. Each mode has its own webhook endpoint in Orboni, so its own
 * signing secret.
 */
class Orboni_GiveWP_Settings
{
    public const SECTION = 'orboni';

    public const SECRET_KEY = [
        'live' => '/^sk_live_[A-Za-z0-9]+$/',
        'test' => '/^sk_test_[A-Za-z0-9]+$/',
    ];

    public const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    /** Every option the plugin keeps in `give_settings`, for uninstall.php. */
    public const OPTIONS = [
        'orboni_live_secret_key', 'orboni_live_webhook_secret',
        'orboni_test_secret_key', 'orboni_test_webhook_secret',
        'orboni_debug',
    ];

    public static function register()
    {
        add_filter('give_get_sections_gateways', [self::class, 'add_section']);
        add_filter('give_get_settings_gateways', [self::class, 'add_settings']);
        foreach (['live', 'test'] as $mode) {
            add_filter('give_admin_settings_sanitize_option_orboni_'.$mode.'_secret_key', static function ($value) use ($mode) {
                return self::keep_if_wrong('orboni_'.$mode.'_secret_key', $value, self::SECRET_KEY[$mode], $mode === 'live'
                    ? __('The live secret key starts with sk_live_. A test key or a publishable key (pk_…) will not work here; the saved key was kept.', 'orboni-for-givewp')
                    : __('The test secret key starts with sk_test_. A live key or a publishable key (pk_…) will not work here; the saved key was kept.', 'orboni-for-givewp'));
            });
            add_filter('give_admin_settings_sanitize_option_orboni_'.$mode.'_webhook_secret', static function ($value) use ($mode) {
                return self::keep_if_wrong('orboni_'.$mode.'_webhook_secret', $value, self::WEBHOOK_SECRET, __('A webhook signing secret starts with whsec_; the saved one was kept.', 'orboni-for-givewp'));
            });
        }
        add_action('admin_notices', [self::class, 'notices']);
    }

    /** "test" while GiveWP's test mode is on, "live" otherwise. */
    public static function mode()
    {
        return function_exists('give_is_test_mode') && give_is_test_mode() ? 'test' : 'live';
    }

    /** The secret key of a mode, the current one by default. */
    public static function secret_key($mode = null)
    {
        $mode = $mode === 'test' || $mode === 'live' ? $mode : self::mode();

        return trim((string) give_get_option('orboni_'.$mode.'_secret_key', ''), " \t\n\r\0\x0B");
    }

    /** The key that can read a session: a cs_test_ session needs the test key. */
    public static function key_for_session($session_id)
    {
        return self::secret_key(strpos((string) $session_id, 'cs_test_') === 0 ? 'test' : 'live');
    }

    /** @return list<string> the signing secrets saved, live first */
    public static function webhook_secrets()
    {
        $secrets = [];
        foreach (['live', 'test'] as $mode) {
            $secret = trim((string) give_get_option('orboni_'.$mode.'_webhook_secret', ''), " \t\n\r\0\x0B");
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        return $secrets;
    }

    public static function debug()
    {
        return give_is_setting_enabled((string) give_get_option('orboni_debug', 'disabled'));
    }

    /** @param  array<string, string>  $sections */
    public static function add_section($sections)
    {
        $sections[self::SECTION] = __('Orboni', 'orboni-for-givewp');

        return $sections;
    }

    /**
     * @param  array<int, array<string, mixed>>  $settings
     * @return array<int, array<string, mixed>>
     */
    public static function add_settings($settings)
    {
        if (! function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== self::SECTION) {
            return $settings;
        }

        $webhook = '<code>'.esc_html(Orboni_GiveWP_Webhook::url()).'</code>';

        return [
            ['type' => 'title', 'id' => 'orboni_settings'],
            [
                'name' => __('Live secret key', 'orboni-for-givewp'),
                'desc' => __('In your Orboni dashboard, under Developers → API keys (sk_live_…). Used while GiveWP\'s test mode is off.', 'orboni-for-givewp'),
                'id' => 'orboni_live_secret_key',
                'type' => 'api_key',
            ],
            [
                'name' => __('Live webhook signing secret', 'orboni-for-givewp'),
                'desc' => sprintf(
                    /* translators: %s: this site's webhook address */
                    __('In your Orboni dashboard, under Developers → Webhooks, add an endpoint with the address %s and paste its signing secret (whsec_…) here. Without it a donation is completed when the donor comes back to your site, or by a check a few minutes later.', 'orboni-for-givewp'),
                    $webhook
                ),
                'id' => 'orboni_live_webhook_secret',
                'type' => 'api_key',
            ],
            [
                'name' => __('Test secret key', 'orboni-for-givewp'),
                'desc' => __('A test key (sk_test_…), used while GiveWP\'s test mode is on: no real money is taken, and the payment page can simulate a payment.', 'orboni-for-givewp'),
                'id' => 'orboni_test_secret_key',
                'type' => 'api_key',
            ],
            [
                'name' => __('Test webhook signing secret', 'orboni-for-givewp'),
                'desc' => sprintf(
                    /* translators: %s: this site's webhook address */
                    __('The signing secret of a test-mode endpoint with the same address, %s.', 'orboni-for-givewp'),
                    $webhook
                ),
                'id' => 'orboni_test_webhook_secret',
                'type' => 'api_key',
            ],
            [
                'name' => __('Debug log', 'orboni-for-givewp'),
                'desc' => __('Write requests to Orboni and webhooks to Donations → Tools → Logs. Errors are written either way.', 'orboni-for-givewp'),
                'id' => 'orboni_debug',
                'type' => 'radio_inline',
                'default' => 'disabled',
                'options' => [
                    'enabled' => __('Enabled', 'orboni-for-givewp'),
                    'disabled' => __('Disabled', 'orboni-for-givewp'),
                ],
            ],
            [
                'name' => __('Orboni for GiveWP guide', 'orboni-for-givewp'),
                'id' => 'orboni_docs_link',
                'url' => 'https://orboni.com/docs/givewp',
                'title' => __('Orboni for GiveWP', 'orboni-for-givewp'),
                'type' => 'give_docs_link',
            ],
            ['type' => 'sectionend', 'id' => 'orboni_settings'],
        ];
    }

    /** A key or a secret pasted in the wrong field keeps the saved one, with a word why. */
    private static function keep_if_wrong($option, $value, $pattern, $message)
    {
        $value = trim((string) $value, " \t\n\r\0\x0B");
        if ($value === '' || preg_match($pattern, $value) === 1) {
            return $value;
        }
        if (class_exists('Give_Admin_Settings')) {
            Give_Admin_Settings::add_error('orboni-'.$option, $message);
        }

        return (string) give_get_option($option, '');
    }

    /** On the gateway's own settings: the mode in use, and a currency Orboni does not take. */
    public static function notices()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- which settings screen is open, read only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($page !== 'give-settings' || $section !== self::SECTION || ! current_user_can('manage_give_settings')) {
            return;
        }

        $currency = strtoupper((string) give_get_currency());
        if (! Orboni_GiveWP_Gateway::supports_currency($currency)) {
            echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
                /* translators: 1: the site's currency code, e.g. ARS, 2: the codes Orboni takes, comma-separated */
                __('Orboni does not take donations in %1$s, this site\'s currency: crypto will not be offered on your forms. It takes %2$s.', 'orboni-for-givewp'),
                $currency,
                implode(', ', array_keys(Orboni_GiveWP_Gateway::CURRENCIES))
            )).'</p></div>';
        }

        $mode = self::mode();
        if (self::secret_key($mode) === '') {
            echo '<div class="notice notice-warning"><p>'.esc_html($mode === 'test'
                ? __('GiveWP is in test mode and no test secret key is saved: crypto is not offered on your forms until one is.', 'orboni-for-givewp')
                : __('No live secret key is saved: crypto is not offered on your forms until one is.', 'orboni-for-givewp')).'</p></div>';
        } elseif ($mode === 'test') {
            echo '<div class="notice notice-info"><p>'.esc_html__('GiveWP is in test mode: donations use the test key and take no real money. Open the payment page while signed in to your Orboni dashboard to simulate a payment.', 'orboni-for-givewp').'</p></div>';
        }
    }
}
