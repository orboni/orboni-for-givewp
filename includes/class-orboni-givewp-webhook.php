<?php

use Give\Donations\Models\Donation;

defined('ABSPATH') || exit;

/**
 * `POST /wp-json/orboni-givewp/v1/webhook`: Orboni's events for this site,
 * from a live endpoint or a test one. The signature is checked against
 * either signing secret, the event matched to its donation, and the session
 * read back from Orboni before anything changes — the donation follows the
 * session as it is now, never an old event's copy of it.
 *
 * Answers: 200 for an event taken or one that is not this site's (Orboni
 * sends every event of a project to each of its endpoints, and a non-2xx
 * would be sent again for days); 401 for a bad signature; 400 for a body that
 * is no event; 503 while the site cannot decide yet (no signing secret
 * saved, Orboni not answering, the donation busy) — Orboni retries it later.
 */
class Orboni_GiveWP_Webhook
{
    /** Apart from the other Orboni plugins' routes: a site may run several. */
    public const ROUTE_NAMESPACE = 'orboni-givewp/v1';

    public const ROUTE = '/webhook';

    public static function register()
    {
        add_action('rest_api_init', static function () {
            register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                // Orboni's server, not a signed-in user: the signature is the check.
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url()
    {
        return rest_url(self::ROUTE_NAMESPACE.self::ROUTE);
    }

    public static function handle(WP_REST_Request $request)
    {
        $secrets = Orboni_GiveWP_Settings::webhook_secrets();
        if ($secrets === []) {
            return self::answer(503, 'not_configured');
        }

        $body = (string) $request->get_body();
        $header = $request->get_header('webhook_signature');
        $signed = false;
        foreach ($secrets as $secret) {
            $signed = $signed || Orboni_GiveWP_Signature::verify($secret, $body, $header);
        }
        if (! $signed) {
            Orboni_GiveWP_Log::error('Webhook refused: the signature matches neither signing secret saved on this site.');

            return self::answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            return self::answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (strpos($type, 'checkout.session.') !== 0) {
            return self::answer(200, 'ignored');
        }

        $id = (string) ($object['id'] ?? '');
        $reference = (string) ($object['client_reference_id'] ?? '');
        $site = (string) (((array) ($object['metadata'] ?? []))['give_site'] ?? '');
        if (preg_match(Orboni_GiveWP_Api::SESSION_ID, $id) !== 1 || ! ctype_digit($reference) || $site !== Orboni_GiveWP_Gateway::site_key()) {
            // Another site's, or a payment link's: not ours to act on.
            return self::answer(200, 'ignored');
        }

        $donation = Donation::find((int) $reference);
        if (! $donation || $donation->gatewayId !== Orboni_GiveWP_Gateway::ID
            || (string) give_get_meta($donation->id, Orboni_GiveWP_Gateway::META_SESSION, true) !== $id) {
            Orboni_GiveWP_Log::info(sprintf('Webhook %s for session %s: no donation on this site has it.', $type, $id));

            return self::answer(200, 'ignored');
        }

        $key = Orboni_GiveWP_Settings::key_for_session($id);
        if ($key === '') {
            return self::answer(503, 'not_configured');
        }

        $session = (new Orboni_GiveWP_Api($key))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_GiveWP_Log::error(sprintf('Webhook %s, donation %d: could not read session %s back: %s', $type, $donation->id, $id, $session->get_error_message()), ['donation_id' => $donation->id]);

            return self::answer(503, 'retry_later');
        }

        $taken = Orboni_GiveWP_Sync::apply_locked($donation->id, $session, $type, (string) ($event['id'] ?? ''));
        if ($taken === null) {
            return self::answer(503, 'busy');
        }

        Orboni_GiveWP_Log::info(sprintf('Webhook %s, donation %d: %s.', $type, $donation->id, $taken ? 'applied' : 'already taken'), ['donation_id' => $donation->id]);

        return self::answer(200, $taken ? 'received' : 'duplicate');
    }

    private static function answer($code, $result)
    {
        return new WP_REST_Response(['result' => $result], $code);
    }
}
