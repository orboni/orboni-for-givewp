<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;

defined('ABSPATH') || exit;

/**
 * "Crypto" on a donation form: the donor is sent to Orboni's payment page,
 * picks a coin and pays to the charity's own wallet; the donation is
 * completed by the signed webhook (`Orboni_GiveWP_Webhook`), by reading the
 * session back when the donor returns, or by a scheduled read-back,
 * whichever comes first. One-time donations only: a coin cannot be charged
 * again by itself, so recurring donations never offer it.
 */
class Orboni_GiveWP_Gateway extends PaymentGateway
{
    public const ID = 'orboni';

    /**
     * The currencies Orboni takes an amount in, and the decimals of each
     * one's smallest unit (ISO 4217): Orboni turns an amount into dollars at
     * the rate of the day, the ECB's or the market's.
     */
    public const CURRENCIES = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here for dollars, by Orboni in any other currency. */
    public const MIN_CENTS = 100;

    public const META_SESSION = '_orboni_session_id';

    /** The amount the session asks, in the currency's smallest unit, and its currency: "2500usd". */
    public const META_AMOUNT = '_orboni_amount';

    /**
     * Where GiveWP sends the donor after paying and after cancelling. Kept
     * on the donation, not in the way back's own address: GiveWP 3.x and 4.x
     * encode a URL passed through a gateway route differently.
     */
    public const META_RETURN = '_orboni_return_urls';

    /** @var list<string> GiveWP's signed routes: the donor's way back from the payment page. */
    public $secureRouteMethods = ['handleReturn', 'handleCancel'];

    /** WordPress hooks beside GiveWP's own registration. */
    public static function register_hooks()
    {
        // The option-based forms' list, and the visual builder's own.
        add_filter('give_enabled_payment_gateways', [self::class, 'offer'], 10, 2);
        add_filter('givewp_donation_form_enabled_gateways', [self::class, 'offer'], 10, 2);
    }

    public static function id(): string
    {
        return self::ID;
    }

    public function getId(): string
    {
        return self::id();
    }

    public function getName(): string
    {
        return __('Orboni (crypto)', 'orboni-for-givewp');
    }

    public function getPaymentMethodLabel(): string
    {
        return __('Crypto (USDT, USDC, BTC and more)', 'orboni-for-givewp');
    }

    /**
     * Forms offer crypto only where it can work: a key saved for the mode
     * GiveWP is in, and a currency Orboni takes. The admin's lists show it
     * whenever it is switched on.
     *
     * @param  array<string, mixed>  $gateways  gateway arrays, or the visual builder's gateway objects
     */
    public static function offer($gateways, $form_id = 0)
    {
        if (! is_array($gateways) || ! isset($gateways[self::ID]) || (is_admin() && ! wp_doing_ajax())) {
            return $gateways;
        }

        $currency = function_exists('give_get_currency') ? give_get_currency((int) $form_id) : '';
        if (Orboni_GiveWP_Settings::secret_key() === '' || ! self::supports_currency($currency)) {
            unset($gateways[self::ID]);
        }

        return $gateways;
    }

    /** In place of card fields on a form of the older kind (GiveWP's option-based forms). */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        // GiveWP says test mode is on by itself, above the gateways.
        return '<fieldset class="no-fields orboni-givewp-note"><p>'.esc_html(self::how_it_works()).'</p></fieldset>';
    }

    /** The visual builder's forms: the gateway's few words, drawn by the script. */
    public function enqueueScript(int $formId)
    {
        wp_enqueue_script(
            'orboni-givewp',
            ORBONI_GIVEWP_URL.'assets/js/orboni-givewp.js',
            ['react'],
            ORBONI_GIVEWP_VERSION,
            true
        );
    }

    /** @return array<string, string> */
    public function formSettings(int $formId): array
    {
        return ['message' => self::how_it_works()];
    }

    private static function how_it_works()
    {
        return __('You will be taken to a secure payment page to choose a coin and pay from your wallet or exchange.', 'orboni-for-givewp');
    }

    /**
     * The donation is made and pending: off to Orboni's payment page.
     *
     * @param  array<string, mixed>  $gatewayData
     *
     * @throws PaymentGatewayException
     */
    public function createPayment(Donation $donation, $gatewayData)
    {
        $mode = $donation->mode->getValue() === 'test' ? 'test' : 'live';
        $secret = Orboni_GiveWP_Settings::secret_key($mode);
        $currency = strtoupper((string) $donation->amount->getCurrency()->getCode());

        if ($secret === '') {
            Orboni_GiveWP_Log::error(sprintf('Donation %d: no %s secret key saved.', $donation->id, $mode), ['donation_id' => $donation->id]);
            self::refuse($donation, self::unavailable(), $mode === 'test'
                ? __('Orboni: no test secret key is saved, so no payment page was made.', 'orboni-for-givewp')
                : __('Orboni: no live secret key is saved, so no payment page was made.', 'orboni-for-givewp'));
        }
        if (! self::supports_currency($currency)) {
            self::refuse($donation, sprintf(
                /* translators: %s: the donation's currency code, e.g. ARS */
                __('Crypto donations are not taken in %s.', 'orboni-for-givewp'),
                $currency
            ));
        }

        $amount = self::minor($donation->amount->formatToDecimal(), $currency);
        if ($currency === 'USD' && $amount < self::MIN_CENTS) {
            self::refuse($donation, __('This donation is below the minimum for a crypto payment ($1).', 'orboni-for-givewp'));
        }

        $success = self::url_arg($gatewayData['successUrl'] ?? '');
        give_update_meta($donation->id, self::META_RETURN, [
            'success' => $success,
            'cancel' => self::url_arg($gatewayData['cancelUrl'] ?? '') ?: $success,
        ]);
        $body = self::session_body($donation, $amount, $currency, [
            'success_url' => $this->generateSecureGatewayRouteUrl('handleReturn', $donation->id, ['donation-id' => $donation->id]),
            'cancel_url' => $this->generateSecureGatewayRouteUrl('handleCancel', $donation->id, ['donation-id' => $donation->id]),
        ]);

        $session = (new Orboni_GiveWP_Api($secret))->create_session($body, self::idempotency_key($donation, $amount, $currency));
        if (is_wp_error($session)) {
            $data = $session->get_error_data();
            $type = is_array($data) ? (string) ($data['type'] ?? '') : '';
            $param = is_array($data) ? (string) ($data['param'] ?? '') : '';
            Orboni_GiveWP_Log::error(sprintf('Donation %d: Orboni refused a session (%s): %s', $donation->id, $type, $session->get_error_message()), ['donation_id' => $donation->id]);

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            if ($type === 'amount_too_small' || ($type === 'invalid_request_error' && $param === 'amount')) {
                self::refuse($donation, __('This donation is below the minimum amount for a crypto payment.', 'orboni-for-givewp'));
            }

            self::refuse($donation, self::unavailable(), sprintf(
                /* translators: %s: Orboni's error message */
                __('Orboni refused to make a payment page: %s', 'orboni-for-givewp'),
                $session->get_error_message()
            ));
        }
        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Orboni_GiveWP_Log::error(sprintf('Donation %d: Orboni answered without a session id or url.', $donation->id), ['donation_id' => $donation->id]);
            self::refuse($donation, self::unavailable());
        }

        $expires = (int) ($session['expires_at'] ?? 0) ?: time() + HOUR_IN_SECONDS;
        give_update_meta($donation->id, self::META_SESSION, $session['id']);
        give_update_meta($donation->id, self::META_AMOUNT, $amount.strtolower($currency));
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                /* translators: %s: Orboni checkout session id */
                __('Donor sent to the Orboni payment page (session %s).', 'orboni-for-givewp'),
                $session['id']
            ),
        ]);
        Orboni_GiveWP_Sync::schedule($donation->id, $expires);
        Orboni_GiveWP_Log::info(sprintf('Donation %d: session %s for %s.', $donation->id, $session['id'], self::format_minor($amount, $currency)), ['donation_id' => $donation->id]);

        return new RedirectOffsite($session['url']);
    }

    /**
     * No refund through Orboni: the money is in the charity's wallet, and it
     * is sent back from there. GiveWP then offers no "refund at the gateway"
     * box when a donation is marked Refunded.
     */
    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * GiveWP 3.x's gateway contract asks for it; never called while
     * supportsRefund() says no.
     *
     * @throws PaymentGatewayException
     */
    public function refundDonation(Donation $donation)
    {
        throw new PaymentGatewayException(esc_html__('Orboni does not hold the money, so it cannot send it back: refund the donor from your wallet.', 'orboni-for-givewp'));
    }

    /**
     * The donor is back from the payment page: the session is read once, so
     * the donation is right on the receipt even before the webhook comes, or
     * without one.
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function handleReturn(array $queryParams): RedirectResponse
    {
        $donation = Donation::find((int) ($queryParams['donation-id'] ?? 0));
        if (! $donation || $donation->gatewayId !== self::ID) {
            return new RedirectResponse(home_url('/'));
        }

        $session = self::read_session($donation);
        if ($session !== null) {
            Orboni_GiveWP_Sync::apply_locked($donation->id, $session);
        }

        return new RedirectResponse(self::return_url($donation, 'success'));
    }

    /**
     * The payment page's "Cancel and go back", and its "Back to the store"
     * once it expired. With nothing paid the page is closed on Orboni (money
     * sent to it later is held for the charity, never pays a cancelled
     * donation) and the donation cancelled; the donor is back on the form. A
     * payment already on its way is applied, and the donor sees the receipt.
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function handleCancel(array $queryParams): RedirectResponse
    {
        $donation = Donation::find((int) ($queryParams['donation-id'] ?? 0));
        if (! $donation || $donation->gatewayId !== self::ID) {
            return new RedirectResponse(home_url('/'));
        }
        $back = self::return_url($donation, 'cancel');

        $session = self::read_session($donation);
        if ($session !== null && ($session['status'] ?? '') === 'open' && ($session['payment_status'] ?? '') === 'unpaid') {
            $closed = (new Orboni_GiveWP_Api(Orboni_GiveWP_Settings::key_for_session($session['id'])))->expire_session($session['id']);
            if (is_wp_error($closed)) {
                Orboni_GiveWP_Log::error(sprintf('Donation %d: could not close session %s: %s', $donation->id, $session['id'], $closed->get_error_message()), ['donation_id' => $donation->id]);
                $session = null;
            } else {
                $session = $closed;
            }
        }

        if ($session === null) {
            return new RedirectResponse($back);
        }

        if (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid') {
            Orboni_GiveWP_Sync::cancel_locked($donation->id, $session);

            return new RedirectResponse($back);
        }

        // Something was paid: the donation follows it, and the donor sees where it stands.
        Orboni_GiveWP_Sync::apply_locked($donation->id, $session);

        return new RedirectResponse(self::return_url($donation, 'success'));
    }

    /** GiveWP's own page for the donor: "success" (the receipt) or "cancel" (back to the form). */
    private static function return_url(Donation $donation, $which)
    {
        $urls = give_get_meta($donation->id, self::META_RETURN, true);
        $urls = is_array($urls) ? $urls : [];

        return self::url_arg($urls[$which] ?? '') ?: (self::url_arg($urls['success'] ?? '') ?: home_url('/'));
    }

    /**
     * The donation's session as Orboni shows it now; null when there is none
     * or Orboni could not be asked.
     *
     * @return array<string, mixed>|null
     */
    public static function read_session(Donation $donation)
    {
        $id = (string) give_get_meta($donation->id, self::META_SESSION, true);
        $secret = Orboni_GiveWP_Settings::key_for_session($id);
        if ($id === '' || $secret === '') {
            return null;
        }

        $session = (new Orboni_GiveWP_Api($secret))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_GiveWP_Log::error(sprintf('Donation %d: could not read session %s: %s', $donation->id, $id, $session->get_error_message()), ['donation_id' => $donation->id]);

            return null;
        }

        return $session;
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, mixed>
     */
    private static function session_body(Donation $donation, $amount, $currency, array $urls)
    {
        $email = (string) $donation->email;
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit((string) $donation->formTitle !== '' ? (string) $donation->formTitle : __('Donation', 'orboni-for-givewp'), 250),
            'details' => self::fit(sprintf(
                /* translators: 1: donation number, 2: site name */
                __('Donation #%1$s to %2$s', 'orboni-for-givewp'),
                (string) $donation->id,
                $site
            ), 500),
            'client_reference_id' => (string) $donation->id,
            'customer_email' => is_email($email) ? $email : null,
            'locale' => str_replace('_', '-', determine_locale()),
            'success_url' => $urls['success_url'],
            'cancel_url' => $urls['cancel_url'],
            'metadata' => [
                'give_donation_id' => (string) $donation->id,
                'give_form_id' => (string) $donation->formId,
                'give_site' => self::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * A return address GiveWP handed over: the visual builder's forms pass it
     * encoded once more than the older forms do ("http%3A%2F%2F…").
     */
    private static function url_arg($url)
    {
        $url = (string) $url;
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = rawurldecode($url);
        }

        return preg_match('#^https?://#i', $url) === 1 ? esc_url_raw($url) : '';
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)), " \t\n\r\0\x0B");
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    /**
     * The site, the donation and its amount. The donation's own purchase key
     * is in it too: a site restored from a backup numbers its donations
     * again, and Orboni keeps a key a day.
     */
    private static function idempotency_key(Donation $donation, $amount, $currency)
    {
        $instance = substr(hash('sha256', (string) $donation->purchaseKey), 0, 8);

        return sprintf('give-%s-%d-%s-%d%s', self::site_key(), (int) $donation->id, $instance, $amount, strtolower($currency));
    }

    /**
     * No payment page: the donation is marked failed with why, and the donor
     * is told on the form. GiveWP leaves a refused donation pending
     * otherwise, its own gateways fail it the same way.
     *
     * @return never
     *
     * @throws PaymentGatewayException
     */
    private static function refuse(Donation $donation, $message, $note = '')
    {
        DonationNote::create(['donationId' => $donation->id, 'content' => $note !== '' ? $note : $message]);
        $donation->status = DonationStatus::FAILED();
        $donation->save();

        throw new PaymentGatewayException(esc_html($message));
    }

    private static function unavailable()
    {
        return __('Crypto donations are not available right now. Please try again in a minute or choose another way to give.', 'orboni-for-givewp');
    }

    /** Completed, or past it (refunded): a payment never changes these but a reversal. */
    public static function is_paid_status($status)
    {
        return in_array((string) $status, [DonationStatus::COMPLETE, DonationStatus::REFUNDED], true);
    }

    public static function supports_currency($currency)
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    public static function decimals($currency)
    {
        return self::CURRENCIES[strtoupper((string) $currency)] ?? 2;
    }

    /** An amount in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY. */
    public static function minor($amount, $currency)
    {
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format_minor($amount, $currency)
    {
        $decimals = self::decimals($currency);

        return number_format(((int) $amount) / (10 ** $decimals), $decimals, '.', '').' '.strtoupper((string) $currency);
    }

    /**
     * This site among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two sites' donation 42 never meet.
     */
    public static function site_key()
    {
        return substr(hash('sha256', (string) home_url()), 0, 12);
    }
}
