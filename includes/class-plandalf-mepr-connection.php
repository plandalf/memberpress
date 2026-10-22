<?php

defined('ABSPATH') || exit;

class Plandalf_Mepr_Connection
{
    public const EVENTS = ['invoice.paid', 'invoice.payment_failed', 'invoice.refunded', 'subscription.updated', 'subscription.canceled'];

    public static function connect(array $grant, string $issuer): true|WP_Error
    {
        $api = new Plandalf_Mepr_Api($grant['access_token'], $issuer);
        $connection = $api->connection();
        if (is_wp_error($connection)) {
            return $connection;
        }
        if (empty($connection['organization']['id'])
            || ($connection['client_id'] ?? '') !== $grant['client_id']
            || untrailingslashit($connection['site_url'] ?? '') !== untrailingslashit(home_url())
            || ! in_array($connection['mode'] ?? '', ['test', 'live'], true)
            || untrailingslashit($connection['api_url'] ?? '') !== untrailingslashit($issuer)
            || ! self::valid_host($connection)) {
            return new WP_Error('plandalf_connection_mismatch', __('The authorized account or host does not match this connection. Please reconnect.', 'plandalf-memberpress'));
        }
        $previous = Plandalf_Mepr_Settings::get('organization', []);
        if (! empty($previous['id']) && ((int) $previous['id'] !== (int) $connection['organization']['id']
            || ($previous['mode'] ?? '') !== $connection['mode'])) {
            $api->revoke();
            return new WP_Error('plandalf_account_changed', __('Choose the same Plandalf account and purchase mode as this site. Disconnect first if you intend to change accounts.', 'plandalf-memberpress'));
        }
        $endpoint = $api->register_endpoint(Plandalf_Mepr_Settings::events_url(), self::EVENTS,
            sprintf('MemberPress · %s', wp_parse_url(home_url(), PHP_URL_HOST)));
        if (is_wp_error($endpoint)) {
            return $endpoint;
        }
        if (empty($endpoint['id']) || empty($endpoint['secret']) || ($endpoint['url'] ?? '') !== Plandalf_Mepr_Settings::events_url()) {
            return new WP_Error('plandalf_endpoint', __('Plandalf could not register this site for purchase events.', 'plandalf-memberpress'));
        }
        if (Plandalf_Mepr_Settings::is_connected()) {
            $old = Plandalf_Mepr_Api::from_settings()->revoke();
            if (is_wp_error($old)) {
                $api->revoke();
                return $old;
            }
        }
        $oauth = self::credentials($grant, $issuer);
        Plandalf_Mepr_Settings::update([
            'api_key' => '', 'oauth' => $oauth, 'api_base' => $connection['api_url'],
            'organization' => [
                'id' => (int) $connection['organization']['id'], 'name' => $connection['organization']['name'],
                'mode' => $connection['mode'], 'sdk_url' => $connection['sdk_url'],
                'host_url' => $connection['host_url'], 'domain' => $connection['domain'],
            ],
            'endpoint' => ['id' => (int) $endpoint['id'], 'secret' => $endpoint['secret'], 'url' => $endpoint['url']],
            'checkout_offer' => '', 'checkout_offer_name' => '',
        ]);
        Plandalf_Mepr_Plugin::ensure_gateway();

        return true;
    }

    private static function valid_host(array $connection): bool
    {
        $host = wp_parse_url((string) ($connection['host_url'] ?? ''));
        $sdk = wp_parse_url((string) ($connection['sdk_url'] ?? ''));
        if (! is_array($host) || ! is_array($sdk) || empty($host['host']) || isset($host['user']) || isset($sdk['user'])) {
            return false;
        }
        $schemes = defined('PLANDALF_MEPR_OAUTH_ISSUER') ? ['https', 'http'] : ['https'];

        return in_array($host['scheme'] ?? '', $schemes, true)
            && ($host['scheme'] ?? '') === ($sdk['scheme'] ?? '')
            && $host['host'] === ($connection['domain'] ?? '') && $host['host'] === ($sdk['host'] ?? '')
            && ($host['port'] ?? null) === ($sdk['port'] ?? null)
            && ($sdk['path'] ?? '') === '/js/plandalf-sdk.js';
    }

    public static function exchange_code(string $issuer, string $code, string $return, string $client_id, string $verifier): array|WP_Error
    {
        if ($code === '' || ! preg_match('/^[a-f0-9-]{36}$/i', $client_id) || ! preg_match('/^[A-Za-z0-9_~.\-]{43,128}$/', $verifier)) {
            return new WP_Error('plandalf_no_code', __('The OAuth callback is incomplete. Start the connection again.', 'plandalf-memberpress'));
        }
        return self::token_request($issuer, [
            'grant_type' => 'authorization_code', 'client_id' => $client_id,
            'redirect_uri' => add_query_arg('client_id', $client_id, $return),
            'code_verifier' => $verifier, 'code' => $code,
        ]);
    }

    private static function token_request(string $issuer, array $body): array|WP_Error
    {
        $response = wp_remote_post(untrailingslashit($issuer).'/oauth/token', [
            'timeout' => 15, 'redirection' => 0,
            'headers' => ['Accept' => 'application/json'], 'body' => $body,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || ! is_array($data)
            || strtolower($data['token_type'] ?? '') !== 'bearer' || empty($data['access_token'])
            || empty($data['refresh_token']) || (int) ($data['expires_in'] ?? 0) < 1) {
            return new WP_Error('plandalf_oauth', __('Plandalf could not authorize this connection. Please reconnect.', 'plandalf-memberpress'));
        }

        return $data + ['client_id' => $body['client_id']];
    }

    private static function credentials(array $grant, string $issuer): array
    {
        return [
            'access_token' => Plandalf_Mepr_Settings::seal($grant['access_token']),
            'refresh_token' => Plandalf_Mepr_Settings::seal($grant['refresh_token']),
            'expires_at' => time() + (int) $grant['expires_in'],
            'client_id' => $grant['client_id'], 'issuer' => $issuer,
        ];
    }

    public static function access_token(): string|WP_Error
    {
        $oauth = Plandalf_Mepr_Settings::get('oauth', []);
        $token = Plandalf_Mepr_Settings::unseal($oauth['access_token'] ?? '');
        if ($token === '') {
            return new WP_Error('plandalf_not_connected', __('Connect with Plandalf to authorize this site.', 'plandalf-memberpress'));
        }
        if (($oauth['expires_at'] ?? 0) > time() + 60) {
            return $token;
        }
        $lock = 'plandalf_mepr_oauth_refresh_lock';
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) < time() - 30) {
                delete_option($lock);
            }
            return new WP_Error('plandalf_refreshing', __('The connection is refreshing. Please try again.', 'plandalf-memberpress'));
        }
        try {
            wp_cache_delete(Plandalf_Mepr_Settings::OPTION, 'options');
            $oauth = Plandalf_Mepr_Settings::get('oauth', []);
            if (($oauth['expires_at'] ?? 0) > time() + 60) {
                return Plandalf_Mepr_Settings::unseal($oauth['access_token']);
            }
            $grant = self::token_request($oauth['issuer'], [
                'grant_type' => 'refresh_token', 'client_id' => $oauth['client_id'],
                'refresh_token' => Plandalf_Mepr_Settings::unseal($oauth['refresh_token'] ?? ''),
            ]);
            if (is_wp_error($grant)) {
                return $grant;
            }
            Plandalf_Mepr_Settings::update(['oauth' => self::credentials($grant, $oauth['issuer'])]);

            return $grant['access_token'];
        } finally {
            delete_option($lock);
        }
    }

    public static function disconnect(): true|WP_Error
    {
        if (Plandalf_Mepr_Settings::is_connected()) {
            $result = Plandalf_Mepr_Api::from_settings()->revoke();
            if (is_wp_error($result)) {
                return $result;
            }
        }
        Plandalf_Mepr_Settings::update([
            'api_key' => '', 'oauth' => [], 'organization' => [], 'endpoint' => [],
            'checkout_offer' => '', 'checkout_offer_name' => '',
        ]);

        return true;
    }
}
