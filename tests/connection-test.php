<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

function fake_plandalf(string $mode = 'test'): void
{
    T::route('/api/v1/organization', fn () => [200, [
        'id' => 1, 'name' => 'Acme', 'api_key' => ['id' => 'site-key', 'mode' => $mode], 'sdk_url' => 'https://acme.example/js/plandalf-sdk.js',
    ]]);
    T::route('/api/v1/webhook_endpoints', fn ($args, $body) => ($args['method'] ?? 'GET') === 'DELETE'
        ? [200, ['deleted' => true]]
        : [201, ['id' => 42, 'secret' => 'whsec_new', 'url' => $body['url'], 'events' => $body['events']]]);
}

T::add('connect: verifies the key, registers this site for events, adds the Plandalf payment method', function () {
    fake_plandalf('live');

    T::true(Plandalf_Mepr_Connection::connect('live_abc', 'https://plandalf.example'));

    $settings = Plandalf_Mepr_Settings::all();
    T::true(Plandalf_Mepr_Settings::is_connected());
    T::same('live', $settings['organization']['mode']);
    T::same('whsec_new', $settings['endpoint']['secret']);

    $registration = current(array_filter(T::$requests, fn ($r) => str_contains($r['url'], 'webhook_endpoints') && $r['method'] === 'POST'));
    T::same(rest_url('plandalf/v1/events'), $registration['body']['url']);
    T::true(in_array('invoice.paid', $registration['body']['events'], true));
    T::true(Plandalf_Mepr_Plugin::gateway() instanceof MeprPlandalfGateway);
});

T::add('connect: the Plandalf payment method is never offered on the MemberPress signup form', function () {
    T::connect();
    $gateway = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);

    T::true(has_filter('mepr_options_helper_payment_methods', [Plandalf_Mepr_Plugin::class, 'hide_from_checkout']) !== false, 'hooked');
    T::same(['stripe-gateway'], Plandalf_Mepr_Plugin::hide_from_checkout([$gateway, 'stripe-gateway']));
});

T::add('connect: the one-click code exchange stores the key; a failed exchange explains itself', function () {
    T::route('/api/connect/exchange', fn ($args, $body) => $body['code'] === 'good-code'
        ? [200, ['api_key' => 'test_from_code', 'api_base' => 'https://plandalf.example']]
        : [422, ['error' => 'This connection code is invalid or has expired.']]);

    $grant = Plandalf_Mepr_Connection::exchange_code('https://plandalf.example', 'good-code', admin_url('admin-post.php?action=plandalf_mepr_connected'));
    T::same('test_from_code', $grant['api_key']);

    $failed = Plandalf_Mepr_Connection::exchange_code('https://plandalf.example', 'bad-code', 'x');
    T::true(is_wp_error($failed));
    T::contains('expired', $failed->get_error_message());
});

T::add('connect: disconnecting removes the endpoint and forgets the account\'s design', function () {
    T::connect();
    fake_plandalf();

    Plandalf_Mepr_Connection::disconnect();

    T::false(Plandalf_Mepr_Settings::is_connected());
    T::same('', Plandalf_Mepr_Settings::get('checkout_offer'));
    T::true((bool) array_filter(T::$requests, fn ($r) => $r['method'] === 'DELETE' && str_contains($r['url'], 'webhook_endpoints/7')));
});

T::add('upgrade: a site on an older version gets its schema and daily check brought up to date once', function () {
    global $wpdb;
    update_option(Plandalf_Mepr_Plugin::INSTALLED_VERSION_OPTION, '0.0.1');

    Plandalf_Mepr_Plugin::maybe_upgrade();

    T::same(PLANDALF_MEPR_VERSION, get_option(Plandalf_Mepr_Plugin::INSTALLED_VERSION_OPTION));
    T::true((bool) $wpdb->get_var('SHOW COLUMNS FROM '.Plandalf_Mepr_Event_Log::table()." LIKE 'invoice'"), 'invoice column');
    T::true((bool) wp_next_scheduled(Plandalf_Mepr_Reconcile::HOOK), 'daily check scheduled');
});
