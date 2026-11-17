<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;

defined('ABSPATH') || exit;

/**
 * An Orboni checkout session, read back, onto its GiveWP donation. The one
 * place a donation changes for a payment: the webhook, the donor's return
 * and the scheduled check all come here with the session as Orboni shows it
 * now, so the donation follows the payment whatever arrives first, twice or
 * out of order.
 *
 * - complete + paid → Complete (GiveWP sends its receipt);
 * - on its way (processing), short of the amount (partially_paid) or held
 *   for the charity's review (held) → Processing, with a note saying what
 *   happened and what to do;
 * - expired with nothing paid → Abandoned, while the donation still waits;
 * - the donor's "Cancel and go back" with nothing paid → Cancelled;
 * - a completed donation is taken back only when Orboni says money that paid
 *   it left the chain (`payment_reversed`): Revoked, until the session is
 *   paid in full again.
 */
class Orboni_GiveWP_Sync
{
    /** What was last applied, "id:status:payment_status": the same state twice adds no second note. */
    public const META_STATE = '_orboni_state';

    /** The webhook events already taken, so a retry or a replay adds nothing twice. */
    public const META_EVENTS = '_orboni_events';

    public const EVENTS_KEPT = 30;

    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** The scheduled read-back: Action Scheduler, which GiveWP ships, or WP-Cron. */
    public const CHECK_HOOK = 'orboni_givewp_check_donation';

    /** Minutes after the session is made, then after it expires, that it is read back. */
    private const CHECK_AFTER_CREATED = [10];

    private const CHECK_AFTER_EXPIRY = [2, 30, 65];

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /** Seconds a lock left by a request that died is waited out. */
    private const LOCK_SECONDS = 60;

    public static function register()
    {
        add_action(self::CHECK_HOOK, [self::class, 'check']);
    }

    /**
     * {@see apply()} with the donation loaded fresh under a lock: the
     * webhook and the donor's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken, null the donation is busy
     */
    public static function apply_locked($donation_id, array $session, $event = '', $event_id = '')
    {
        return self::locked($donation_id, static function (Donation $donation) use ($session, $event, $event_id) {
            if ($event_id !== '') {
                $taken = give_get_meta($donation->id, self::META_EVENTS, true);
                $taken = is_array($taken) ? $taken : [];
                if (in_array($event_id, $taken, true)) {
                    return false;
                }
                $taken[] = $event_id;
                give_update_meta($donation->id, self::META_EVENTS, array_slice($taken, -self::EVENTS_KEPT));
            }

            self::apply($donation, $session, $event);

            return true;
        });
    }

    /**
     * The donor left the payment page and it was closed with nothing paid:
     * the donation is cancelled, if it still waits.
     *
     * @param  array<string, mixed>  $session  the closed session
     */
    public static function cancel_locked($donation_id, array $session)
    {
        return self::locked($donation_id, static function (Donation $donation) use ($session) {
            if (! self::belongs($donation, $session)) {
                return false;
            }
            $status = $donation->status->getValue();
            if ($status !== DonationStatus::PENDING && $status !== DonationStatus::ABANDONED) {
                return false;
            }

            give_update_meta($donation->id, self::META_STATE, $session['id'].':expired:unpaid');
            self::move($donation, DonationStatus::CANCELLED, __('The donor left the Orboni payment page without paying; it was closed.', 'orboni-for-givewp'));

            return true;
        });
    }

    /**
     * @return bool|null what `$work` returned; false with no such donation, null while it is busy
     */
    private static function locked($donation_id, callable $work)
    {
        $donation_id = (int) $donation_id;
        $lock = 'orboni_givewp_lock_'.$donation_id;
        // add_option() inserts or fails: one request at a time holds it.
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) > time() - self::LOCK_SECONDS) {
                return null;
            }
            update_option($lock, time(), false);
        }

        try {
            $donation = Donation::find($donation_id);

            return $donation ? $work($donation) : false;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Read-backs for a new session: the donation is right even when no
     * webhook is set up and the donor never comes back.
     */
    public static function schedule($donation_id, $expires_at)
    {
        $donation_id = (int) $donation_id;
        $times = [];
        foreach (self::CHECK_AFTER_CREATED as $minutes) {
            $times[] = time() + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (self::CHECK_AFTER_EXPIRY as $minutes) {
            $times[] = (int) $expires_at + $minutes * MINUTE_IN_SECONDS;
        }

        if (function_exists('as_schedule_single_action')) {
            $args = ['donation_id' => $donation_id];
            as_unschedule_all_actions(self::CHECK_HOOK, $args, 'orboni');
            foreach (array_unique($times) as $time) {
                as_schedule_single_action($time, self::CHECK_HOOK, $args, 'orboni');
            }

            return;
        }

        wp_clear_scheduled_hook(self::CHECK_HOOK, [$donation_id]);
        foreach (array_unique($times) as $time) {
            wp_schedule_single_event($time, self::CHECK_HOOK, [$donation_id]);
        }
    }

    /** A scheduled read-back. */
    public static function check($donation_id)
    {
        $donation = Donation::find((int) $donation_id);
        if (! $donation || $donation->gatewayId !== Orboni_GiveWP_Gateway::ID
            || Orboni_GiveWP_Gateway::is_paid_status($donation->status->getValue())) {
            return;
        }

        $session = Orboni_GiveWP_Gateway::read_session($donation);
        if ($session !== null) {
            self::apply_locked($donation->id, $session);
        }
    }

    /**
     * This donation's session: made for it by this site. The webhook checks
     * the same before reading anything back, and a session read back is
     * checked again here.
     *
     * @param  array<string, mixed>  $session
     */
    public static function belongs(Donation $donation, array $session)
    {
        $id = (string) ($session['id'] ?? '');

        return $donation->gatewayId === Orboni_GiveWP_Gateway::ID
            && $id !== ''
            && (string) give_get_meta($donation->id, Orboni_GiveWP_Gateway::META_SESSION, true) === $id
            && (string) ($session['client_reference_id'] ?? '') === (string) $donation->id
            && (string) (((array) ($session['metadata'] ?? []))['give_site'] ?? '') === Orboni_GiveWP_Gateway::site_key();
    }

    /**
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private static function apply(Donation $donation, array $session, $event = '')
    {
        $id = (string) ($session['id'] ?? '');
        if (! self::belongs($donation, $session)) {
            Orboni_GiveWP_Log::error(sprintf('Donation %d: session %s is not this donation\'s; left alone.', $donation->id, $id), ['donation_id' => $donation->id]);

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $state = $id.':'.$status.':'.$payment;
        $paid_here = $status === 'complete' && $payment === 'paid';
        $current = $donation->status->getValue();

        if (Orboni_GiveWP_Gateway::is_paid_status($current)) {
            self::after_paid($donation, $session, $event, $paid_here, $state);

            return;
        }

        if ((string) give_get_meta($donation->id, self::META_STATE, true) === $state) {
            return;
        }

        // Revoked after a reversal: only the session paid in full again brings it back.
        if ($current === DonationStatus::REVOKED && ! $paid_here) {
            return;
        }
        give_update_meta($donation->id, self::META_STATE, $state);

        if ($paid_here) {
            self::complete($donation, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                self::move($donation, DonationStatus::PROCESSING, __('A payment is on the chain and waiting for confirmations. The donation will be completed by itself once it is final.', 'orboni-for-givewp'));

                return;

            case 'partially_paid':
                self::move($donation, DonationStatus::PROCESSING, $status === 'expired'
                    ? sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('The payment page closed partly paid: %1$s of %2$s arrived in your wallet. Orboni will not complete this donation: change its amount to what arrived and complete it, or send the money back to the donor.', 'orboni-for-givewp'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    )
                    : sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('Partly paid so far: %1$s of %2$s. The payment page asks the donor for the rest.', 'orboni-for-givewp'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    ));

                return;

            case 'held':
                self::move($donation, DonationStatus::PROCESSING, sprintf(
                    /* translators: %s: why Orboni held the payment, e.g. "late" or "overpaid" */
                    __('The money is in your wallet but held for your review in the Orboni dashboard (reason: %s). Accept it there and this donation is completed.', 'orboni-for-givewp'),
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;
        }

        if ($status === 'expired' && $payment === 'unpaid' && $current === DonationStatus::PENDING) {
            self::move($donation, DonationStatus::ABANDONED, __('The Orboni payment page expired with nothing paid.', 'orboni-for-givewp'));
        }
    }

    /** @param  array<string, mixed>  $session */
    private static function complete(Donation $donation, array $session)
    {
        $asked = (int) ($session['amount_total'] ?? 0);
        $asked_in = strtoupper((string) ($session['currency'] ?? ''));
        $currency = strtoupper((string) $donation->amount->getCurrency()->getCode());
        $due = Orboni_GiveWP_Gateway::minor($donation->amount->formatToDecimal(), $currency);

        // The donation's amount was changed by hand after the page was opened.
        if ($asked_in !== $currency || $asked < $due) {
            self::move($donation, DonationStatus::PROCESSING, sprintf(
                /* translators: 1: the amount the session asked, with its currency, 2: the donation's amount, with its currency */
                __('Orboni completed a payment of %1$s, but this donation is for %2$s. Check it before completing it.', 'orboni-for-givewp'),
                Orboni_GiveWP_Gateway::format_minor($asked, $asked_in),
                Orboni_GiveWP_Gateway::format_minor($due, $currency)
            ));

            return;
        }

        DonationNote::create(['donationId' => $donation->id, 'content' => self::paid_note($session)]);
        $donation->status = DonationStatus::COMPLETE();
        $donation->gatewayTransactionId = (string) $session['id'];
        $donation->save();
        Orboni_GiveWP_Log::info(sprintf('Donation %d: paid by session %s.', $donation->id, $session['id']), ['donation_id' => $donation->id]);
    }

    /** @param  array<string, mixed>  $session */
    private static function after_paid(Donation $donation, array $session, $event, $paid_here, $state)
    {
        $id = (string) $session['id'];

        if ((string) $donation->gatewayTransactionId === $id) {
            if ($event === self::REVERSED && ! $paid_here && $donation->status->getValue() === DonationStatus::COMPLETE) {
                // Recorded, so the session paid in full again completes the donation again.
                give_update_meta($donation->id, self::META_STATE, $state);
                self::move($donation, DonationStatus::REVOKED, sprintf(
                    /* translators: %s: the amount still counted as received, with its currency */
                    __('Orboni reports that money which paid this donation left the chain (a reorganisation or a double spend); %s still counts as received. It is completed again once the Orboni dashboard shows it paid.', 'orboni-for-givewp'),
                    self::money($session, 'amount_received')
                ));
                Orboni_GiveWP_Log::error(sprintf('Donation %d: payment reversed on session %s.', $donation->id, $id), ['donation_id' => $donation->id]);
            } elseif ($event === self::EXTRA) {
                DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(
                    /* translators: %s: the amount received in all, with its currency */
                    __('More money arrived after this donation was paid: %s received in all. Orboni does not complete a donation twice; send the difference back from your wallet if the donor asks.', 'orboni-for-givewp'),
                    self::money($session, 'amount_received')
                )]);
            }
        }
    }

    /** A note, and the donation's status if it changes. */
    private static function move(Donation $donation, $status, $note)
    {
        DonationNote::create(['donationId' => $donation->id, 'content' => $note]);
        if ($donation->status->getValue() !== $status) {
            $donation->status = new DonationStatus($status);
            $donation->save();
        }
    }

    /** "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…." @param  array<string, mixed>  $session */
    private static function paid_note(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.__('(test payment)', 'orboni-for-givewp') : '';
        $late = ! empty($session['paid_late']) ? ' '.__('It arrived after the payment page expired.', 'orboni-for-givewp') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return sprintf(
                /* translators: 1: coin amount, 2: coin, 3: network, 4: the amount it counted for, with its currency, 5: session id */
                __('Paid with %1$s %2$s on %3$s (%4$s) through Orboni, session %5$s.', 'orboni-for-givewp'),
                (string) $payment['amount'],
                (string) $payment['coin'],
                self::CHAIN_NAMES[(string) ($payment['chain'] ?? '')] ?? ucfirst((string) ($payment['chain'] ?? '')),
                self::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return sprintf(
            /* translators: 1: the amount, with its currency, 2: session id */
            __('Paid %1$s through Orboni, session %2$s.', 'orboni-for-givewp'),
            self::money($session, 'amount_received'),
            (string) $session['id']
        ).$test.$late;
    }

    /**
     * One of the session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param  array<string, mixed>  $session
     */
    private static function money(array $session, $field)
    {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), Orboni_GiveWP_Gateway::decimals($currency), '.', '').' '.$currency;
    }
}
