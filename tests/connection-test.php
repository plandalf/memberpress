<?php

defined('ABSPATH') || exit(1);
use Plandalf_Test as T;

function oauth_grant(): array
{
    return ['access_token' => 'oauth-new-access', 'refresh_token' => 'oauth-new-refresh', 'expires_in' => 3600, 'client_id' => '11111111-1111-4111-8111-111111111111'];
}

function fake_plandalf(string $mode = 'test', array $overrides = []): void
{
    T::route('/api/v1/site-connection', fn () => [200, array_replace([
        'organization' => ['id' => 1, 'name' => 'Acme'], 'client_id' => oauth_grant()['client_id'],
        'site_url' => home_url(), 'mode' => $mode, 'api_url' => 'https://plandalf.example',
        'host_url' => 'https://acme.example', 'domain' => 'acme.example', 'sdk_url' => 'https://acme.example/js/plandalf-sdk.js',
    ], $overrides)]);
    T::route('/api/v1/webhook_endpoints', fn ($args, $body) => [201, ['id' => 42, 'secret' => 'whsec_new', 'url' => $body['url']]]);
}

T::add('OAuth: discovers the account host and encrypts tokens without storing an API key', function () {
    Plandalf_Mepr_Settings::update(['oauth' => [], 'organization' => []]);
    fake_plandalf('live');
    T::true(Plandalf_Mepr_Connection::connect(oauth_grant(), 'https://plandalf.example'));
    T::true(Plandalf_Mepr_Settings::is_connected());
    T::same('', Plandalf_Mepr_Settings::get('api_key'));
    T::same('live', Plandalf_Mepr_Settings::mode());
    T::same('acme.example', Plandalf_Mepr_Settings::get('organization')['domain']);
    $stored = Plandalf_Mepr_Settings::get('oauth');
    T::false($stored['access_token'] === oauth_grant()['access_token']);
    T::same(oauth_grant()['access_token'], Plandalf_Mepr_Connection::access_token());
    T::same(oauth_grant()['refresh_token'], Plandalf_Mepr_Settings::unseal($stored['refresh_token']));
});

T::add('OAuth: fails closed for a wrong site, client, host, API URL or mode', function () {
    foreach ([['site_url' => 'https://wrong.example'], ['client_id' => 'wrong'], ['sdk_url' => 'https://wrong.example/js/plandalf-sdk.js'], ['api_url' => 'https://attacker.example'], ['mode' => 'invalid']] as $override) {
        fake_plandalf('test', $override);
        T::true(is_wp_error(Plandalf_Mepr_Connection::connect(oauth_grant(), 'https://plandalf.example')));
    }
    T::false((bool) array_filter(T::$requests, fn ($r) => str_contains($r['url'], 'webhook_endpoints')));
});

T::add('OAuth: reconnecting cannot silently switch the account or purchase mode', function () {
    T::connect('test');
    fake_plandalf('live');
    $result = Plandalf_Mepr_Connection::connect(oauth_grant(), 'https://plandalf.example');
    T::same('plandalf_account_changed', $result->get_error_code());
    T::same('test', Plandalf_Mepr_Settings::mode());
    T::false((bool) array_filter(T::$requests, fn ($r) => str_contains($r['url'], 'webhook_endpoints')));
});

T::add('OAuth: exchanges the code at the issuer using PKCE and the exact callback', function () {
    $client = oauth_grant()['client_id'];
    T::route('/oauth/token', function ($args, $body) use ($client) {
        T::same('authorization_code', $body['grant_type']);
        T::same(str_repeat('v', 64), $body['code_verifier']);
        T::same('https://members.example/callback?client_id='.$client, $body['redirect_uri']);
        T::same(0, $args['redirection']);
        T::false(isset($body['client_secret']));
        return [200, oauth_grant() + ['token_type' => 'Bearer']];
    });
    $grant = Plandalf_Mepr_Connection::exchange_code('https://plandalf.example', 'code', 'https://members.example/callback', $client, str_repeat('v', 64));
    T::same('oauth-new-access', $grant['access_token']);
    T::true(is_wp_error(Plandalf_Mepr_Connection::exchange_code('https://plandalf.example', '', 'x', $client, 'bad')));
});

T::add('OAuth: refresh rotates encrypted credentials and never sends the old access token to another host', function () {
    T::connect();
    $oauth = Plandalf_Mepr_Settings::get('oauth');
    $oauth['expires_at'] = time() - 1;
    Plandalf_Mepr_Settings::update(['oauth' => $oauth]);
    T::route('/oauth/token', function ($args, $body) {
        T::same('refresh_token', $body['grant_type']);
        T::same('oauth-refresh-test', $body['refresh_token']);
        return [200, oauth_grant() + ['token_type' => 'Bearer']];
    });
    T::same('oauth-new-access', Plandalf_Mepr_Connection::access_token());
    T::same('oauth-new-refresh', Plandalf_Mepr_Settings::unseal(Plandalf_Mepr_Settings::get('oauth')['refresh_token']));
});

T::add('OAuth: failed refresh does not fall back to a legacy API key', function () {
    T::connect();
    $oauth = Plandalf_Mepr_Settings::get('oauth');
    $oauth['expires_at'] = 0;
    Plandalf_Mepr_Settings::update(['oauth' => $oauth, 'api_key' => 'test_legacy']);
    T::route('/oauth/token', fn () => [400, ['error' => 'invalid_grant']]);
    T::true(is_wp_error(Plandalf_Mepr_Api::from_settings()->offers()));
    T::same(1, count(T::$requests));
});

T::add('OAuth: disconnect revokes the connection and clears its credentials', function () {
    T::connect();
    T::route('/api/v1/site-connection', fn () => [200, ['revoked' => true]]);
    T::true(Plandalf_Mepr_Connection::disconnect());
    T::false(Plandalf_Mepr_Settings::is_connected());
    T::same([], Plandalf_Mepr_Settings::get('oauth'));
    T::same('', Plandalf_Mepr_Settings::get('checkout_offer'));
    T::same('DELETE', T::$requests[0]['method']);
});

T::add('OAuth: a failed disconnect retains credentials so revocation can be retried', function () {
    T::connect();
    T::route('/api/v1/site-connection', fn () => [503, ['message' => 'Unavailable']]);
    T::true(is_wp_error(Plandalf_Mepr_Connection::disconnect()));
    T::true(Plandalf_Mepr_Settings::is_connected());
});

T::add('OAuth: old API key settings require reconnect and the UI has no manual key or host form', function () {
    Plandalf_Mepr_Settings::update(['api_key' => 'test_old', 'oauth' => []]);
    T::false(Plandalf_Mepr_Settings::is_connected());
    $source = file_get_contents(dirname(__DIR__).'/includes/class-plandalf-mepr-admin.php');
    T::false(str_contains($source, 'name="api_key"'));
    T::false(str_contains($source, 'name="api_base"'));
    T::false(str_contains($source, 'handle_connect_with_key'));
});

T::add('OAuth: the payment method stays hidden from MemberPress signup', function () {
    T::connect();
    $gateway = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);
    T::same(['stripe-gateway'], Plandalf_Mepr_Plugin::hide_from_checkout([$gateway, 'stripe-gateway']));
});

T::add('upgrade: a site on an older version gets its schema and daily check brought up to date once', function () {
    global $wpdb;
    update_option(Plandalf_Mepr_Plugin::INSTALLED_VERSION_OPTION, '0.0.1');

    Plandalf_Mepr_Plugin::maybe_upgrade();

    T::same(PLANDALF_MEPR_VERSION, get_option(Plandalf_Mepr_Plugin::INSTALLED_VERSION_OPTION));
    T::true((bool) $wpdb->get_var('SHOW COLUMNS FROM '.Plandalf_Mepr_Event_Log::table()." LIKE 'invoice'"), 'invoice column');
    T::true((bool) wp_next_scheduled(Plandalf_Mepr_Reconcile::HOOK), 'daily check scheduled');
});
