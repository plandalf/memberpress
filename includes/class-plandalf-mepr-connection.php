<?php

defined('ABSPATH') || exit;

/**
 * Connect = verify the API key, then register this site's event endpoint so
 * no webhook URL is ever copied by hand. Reconnecting replaces the previous
 * endpoint; disconnecting deletes it from Plandalf.
 */
class Plandalf_Mepr_Connection
{
    /** Events the plugin acts on. */
    public const EVENTS = [
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.refunded',
        'subscription.updated',
        'subscription.canceled',
    ];

    public static function connect(string $api_key, string $api_base): true|WP_Error
    {
        $api_base = untrailingslashit(esc_url_raw($api_base ?: Plandalf_Mepr_Settings::DEFAULT_API_BASE));
        $api = new Plandalf_Mepr_Api($api_key, $api_base);

        $organization = $api->organization();
        if (is_wp_error($organization)) {
            return $organization;
        }

        if (empty($organization['api_key']['id'])) {
            return new WP_Error('plandalf_key_type', __('Use a Plandalf API key (live_… or test_…), not an OAuth token.', 'plandalf-memberpress'));
        }

        self::remove_endpoint();

        $endpoint = $api->register_endpoint(
            Plandalf_Mepr_Settings::events_url(),
            self::EVENTS,
            sprintf('MemberPress · %s', wp_parse_url(home_url(), PHP_URL_HOST))
        );
        if (is_wp_error($endpoint)) {
            return $endpoint;
        }

        Plandalf_Mepr_Settings::update([
            'api_key' => $api_key,
            'api_base' => $api_base,
            'organization' => [
                'id' => (int) $organization['id'],
                'name' => (string) ($organization['name'] ?? ''),
                'api_key_id' => (string) $organization['api_key']['id'],
                'mode' => (string) ($organization['api_key']['mode'] ?? 'live'),
                'sdk_url' => (string) ($organization['sdk_url'] ?? ''),
            ],
            'endpoint' => [
                'id' => (int) $endpoint['id'],
                'secret' => (string) $endpoint['secret'],
                'url' => (string) $endpoint['url'],
            ],
        ]);

        Plandalf_Mepr_Plugin::ensure_gateway();

        return true;
    }

    /**
     * Trade the single-use code from Plandalf's consent screen for the site's
     * API key (server to server, so the key never passes through a browser).
     *
     * @return array{api_key: string, api_base: string}|WP_Error
     */
    public static function exchange_code(string $api_base, string $code, string $return): array|WP_Error
    {
        if ($code === '') {
            return new WP_Error('plandalf_no_code', __('Plandalf didn\'t send a connection code. Try again.', 'plandalf-memberpress'));
        }

        $response = wp_remote_post(untrailingslashit($api_base).'/api/connect/exchange', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['code' => $code, 'return' => $return]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || empty($body['api_key'])) {
            return new WP_Error('plandalf_exchange', (string) ($body['error'] ?? __('Plandalf couldn\'t complete the connection. Try again.', 'plandalf-memberpress')));
        }

        return [
            'api_key' => (string) $body['api_key'],
            'api_base' => untrailingslashit((string) ($body['api_base'] ?? $api_base)),
        ];
    }

    public static function disconnect(): void
    {
        self::remove_endpoint();
        // The checkout design is an offer in that Plandalf account, so it goes too.
        Plandalf_Mepr_Settings::update([
            'api_key' => '',
            'organization' => [],
            'endpoint' => [],
            'checkout_offer' => '',
            'checkout_offer_name' => '',
        ]);
    }

    private static function remove_endpoint(): void
    {
        $endpoint_id = (int) (Plandalf_Mepr_Settings::get('endpoint')['id'] ?? 0);
        if ($endpoint_id > 0) {
            Plandalf_Mepr_Api::from_settings()->delete_endpoint($endpoint_id);
        }
    }
}
