<?php

defined('ABSPATH') || exit;

/**
 * REST receiver for signed Plandalf events:
 *   POST /wp-json/plandalf/v1/events
 *
 * 1. Verify `Plandalf-Signature` against the endpoint secret (and reject
 *    timestamps older than five minutes).
 * 2. Skip events already applied or ignored (Plandalf retries, duplicates).
 * 3. Hand the event to Plandalf_Mepr_Fulfillment and log the outcome.
 *
 * Unexpected errors answer 500 so Plandalf retries later.
 */
class Plandalf_Mepr_Events
{
    public const TOLERANCE_SECONDS = 300;

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('plandalf/v1', '/events', [
                'methods' => 'POST',
                'callback' => [self::class, 'receive'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('plandalf/v1', '/purchase-status', [
                'methods' => 'GET',
                'callback' => [self::class, 'purchase_status'],
                'permission_callback' => '__return_true',
                'args' => [
                    'invoice' => ['required' => true, 'type' => 'string'],
                    'ref' => ['required' => false, 'type' => 'string'],
                ],
            ]);
        });
    }

    public static function receive(WP_REST_Request $request): WP_REST_Response
    {
        $secret = (string) (Plandalf_Mepr_Settings::get('endpoint')['secret'] ?? '');
        $body = $request->get_body();

        if ($secret === '' || ! self::verify($secret, (string) $request->get_header('plandalf-signature'), $body)) {
            return new WP_REST_Response(['error' => 'invalid signature'], 401);
        }

        $event = json_decode($body, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return new WP_REST_Response(['error' => 'malformed event'], 400);
        }

        $result = Plandalf_Mepr_Fulfillment::with_lock(static fn () => self::apply_verified_event($event));
        if (is_wp_error($result)) {
            $response = new WP_REST_Response(['error' => 'membership processing busy'], 503);
            $response->header('Retry-After', '5');

            return $response;
        }

        return $result;
    }

    /** The deduplication check and its final record must share the fulfilment lock. */
    private static function apply_verified_event(array $event): WP_REST_Response
    {
        $previous = Plandalf_Mepr_Event_Log::find((string) $event['id']);
        if ($previous && $previous['status'] !== Plandalf_Mepr_Event_Log::FAILED) {
            return new WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        try {
            /**
             * Fires for every verified event, including ones the plugin ignores.
             *
             * @param  array<string, mixed>  $event
             */
            do_action('plandalf_mepr_event', $event);

            [$status, $message] = Plandalf_Mepr_Fulfillment::handle($event);
            Plandalf_Mepr_Event_Log::record($event, $status, $message);

            return new WP_REST_Response(['received' => true, 'status' => $status], 200);
        } catch (Throwable $e) {
            try {
                Plandalf_Mepr_Event_Log::record($event, Plandalf_Mepr_Event_Log::FAILED, $e->getMessage());
            } catch (Throwable) {
                // Never acknowledge an event that could not be persisted.
            }

            return new WP_REST_Response(['error' => 'processing failed'], 500);
        }
    }

    /**
     * Lets the thank-you page wait for the event: has the invoice been applied?
     * Answers only yes/no — plus, once, the set-password link for an account
     * this purchase created, to whoever presents the purchase's private
     * invoice id (`ref`).
     */
    public static function purchase_status(WP_REST_Request $request): WP_REST_Response
    {
        $invoice = sanitize_text_field((string) $request->get_param('invoice'));
        $applied = $invoice !== '' && Plandalf_Mepr_Event_Log::invoice_applied($invoice);
        $body = ['applied' => $applied];

        $ref = sanitize_text_field((string) $request->get_param('ref'));
        if ($applied && $ref !== '' && ! is_user_logged_in()) {
            $link = Plandalf_Mepr_Password_Setup::claim($ref);
            if ($link) {
                $body['set_password_url'] = $link;
            }
        }

        $response = new WP_REST_Response($body, 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function verify(string $secret, string $header, string $body, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $parts['v1']);
    }
}
