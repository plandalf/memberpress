<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('checkout: a linked membership shows the site design selling its own price', function () {
    T::connect('test');
    $membership = T::membership('Gold', 29);
    T::linked($membership, 501, 'gold-monthly');

    T::true(Plandalf_Mepr_Links::sells_with_plandalf($membership->ID));

    $html = Plandalf_Mepr_Checkout::replace_signup_form('<form>mepr</form>', '/checkout/form', ['product' => $membership]);
    T::contains('data-plandalf-mount="design-offer-ABC"', $html);
    T::contains('data-plandalf-price="gold-monthly"', $html);
    T::contains('data-plandalf-continuity="tab"', $html);
    T::contains('data-plandalf-clear-session-on-purchase="true"', $html);
    T::contains('data-plandalf-mode="test"', $html, 'test key → test mode');
});

T::add('checkout: keeps the MemberPress form without a link, a design, a lookup key, or when opted out', function () {
    T::connect('live');
    $membership = T::membership('Gold', 29);
    $mepr = '<form>mepr</form>';
    $render = fn () => Plandalf_Mepr_Checkout::replace_signup_form($mepr, '/checkout/form', ['product' => $membership]);

    T::same($mepr, $render(), 'no link');

    T::linked($membership, 501, null);
    T::same($mepr, $render(), 'price without lookup key');

    T::linked($membership);
    T::true($render() !== $mepr, 'linked');
    T::false(str_contains($render(), 'data-plandalf-mode'), 'live key → no test mode');

    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_OPT_OUT, '1');
    T::same($mepr, $render(), 'opted out');
    delete_post_meta($membership->ID, Plandalf_Mepr_Links::META_OPT_OUT);

    Plandalf_Mepr_Settings::update(['replace_checkout' => false]);
    T::same($mepr, $render(), 'switched off site-wide');
});

T::add('checkout: a per-membership design and page price override the defaults', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_LINKED, [
        ['id' => 501, 'lookup_key' => 'gold-monthly', 'label' => 'monthly'],
        ['id' => 502, 'lookup_key' => 'gold-yearly', 'label' => 'yearly'],
    ]);
    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_PAGE_PRICE, 502);
    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_OFFER, 'special-design');
    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_DISPLAY, 'popup');

    $html = Plandalf_Mepr_Checkout::replace_signup_form('', '/checkout/form', ['product' => $membership]);
    T::contains('data-plandalf-present="special-design"', $html);
    T::contains('data-plandalf-price="gold-yearly"', $html);
    T::contains('data-plandalf-continuity="tab"', $html);
    T::contains('data-plandalf-clear-session-on-purchase="true"', $html);
});

T::add('links: the membership snapshot matches Plandalf price terms', function () {
    $monthly = T::membership('Gold', 29, 'months', ['trial' => true, 'trial_days' => 14, 'trial_amount' => 0]);
    $snapshot = Plandalf_Mepr_Links::snapshot($monthly);
    T::same(2900, $snapshot['amount_cents']);
    T::same('month', $snapshot['interval']);
    T::same(1, $snapshot['interval_count']);
    T::same(14, $snapshot['trial_period_days']);

    $lifetime = Plandalf_Mepr_Links::snapshot(T::membership('Lifetime', 199, 'lifetime'));
    T::same(null, $lifetime['interval']);
});

T::add('links: memberships with fixed expiry or paid trials can\'t be created in Plandalf automatically', function () {
    T::true(Plandalf_Mepr_Links::unsupported_reason(T::membership('Fixed', 10, 'lifetime', ['expire_type' => 'fixed'])) !== null);
    T::true(Plandalf_Mepr_Links::unsupported_reason(T::membership('Paid trial', 29, 'months', ['trial' => true, 'trial_days' => 7, 'trial_amount' => 1])) !== null);
    T::same(null, Plandalf_Mepr_Links::unsupported_reason(T::membership('Plain', 29)));
});

T::add('links: creating a price from a membership upserts the price and links it', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $calls = [];

    T::route('/api/v1/products', function ($args, $body) use (&$calls) {
        $calls['product'] = $body;

        return [200, ['id' => 9, 'lookup_key' => $body['lookup_key']]];
    });
    T::route('/api/v1/prices', function ($args, $body) use (&$calls) {
        $calls['price'] = $body;

        return [200, ['id' => 777, 'lookup_key' => $body['lookup_key']]];
    });
    T::route('/api/v1/links', function ($args, $body) use (&$calls, $membership) {
        if (($args['method'] ?? 'GET') === 'POST') {
            $calls['link'] = $body;

            return [201, ['id' => 1]];
        }

        return [200, ['data' => [['id' => 1, 'external_id' => (string) $membership->ID, 'price' => ['id' => 777, 'lookup_key' => 'memberpress:x', 'amount_cents' => 2900, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]], 'drift' => []]]]];
    });

    T::true(Plandalf_Mepr_Links::create_price_from($membership));

    T::same(2900, $calls['price']['amount_cents']);
    T::same(['interval' => 'month', 'interval_count' => 1], $calls['price']['recurring']);
    T::same(777, $calls['link']['price_id']);
    T::same((string) $membership->ID, $calls['link']['external_id']);
    T::same(2900, $calls['link']['snapshot']['amount_cents'], 'snapshot sent for drift');
    T::same([777], Plandalf_Mepr_Links::linked_price_ids($membership->ID), 'cache updated');
});

T::add('identity: the member token is an HS256 JWT with sub wp:<id> and the key id', function () {
    T::connect();
    $user_id = self_user('jwt@example.com');
    $token = Plandalf_Mepr_Jwt::for_user(get_userdata($user_id));
    [$header, $payload, $signature] = explode('.', $token);
    $decode = fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);

    T::same('site-key-abc', $decode($header)['kid']);
    T::same('wp:'.$user_id, $decode($payload)['sub']);
    T::same('jwt@example.com', $decode($payload)['email']);
    T::same(rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'test_testkey', true)), '+/', '-_'), '='), $signature);
});

T::add('lists: MemberPress\'s Subscriptions list shows which rows Plandalf bills', function () {
    T::connect();
    $gateway = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);

    $columns = Plandalf_Mepr_List_Columns::subscription_columns(['col_gateway' => 'Gateway', 'col_created_at' => 'Created'], 'col_', false);
    T::same(['col_gateway', 'col_plandalf', 'col_created_at'], array_keys($columns), 'placed after Gateway');

    ob_start();
    Plandalf_Mepr_List_Columns::subscription_cell('col_plandalf', (object) ['gateway' => $gateway, 'subscr_id' => 'sub_ABC:9']);
    $ours = ob_get_clean();
    T::contains('sub_ABC', $ours);
    T::false(str_contains($ours, ':9'), 'multi-membership suffix hidden');

    ob_start();
    Plandalf_Mepr_List_Columns::subscription_cell('col_plandalf', (object) ['gateway' => 'stripe-gw', 'subscr_id' => 'sub_other']);
    T::contains('—', ob_get_clean(), 'other gateways');

    ob_start();
    Plandalf_Mepr_List_Columns::subscription_cell('col_txn_plandalf', (object) ['gateway' => $gateway, 'trans_num' => 'INV-77']);
    T::contains('INV-77', ob_get_clean(), 'lifetime rows use the invoice number');
});

T::add('lists: MemberPress\'s Memberships list shows the connection state', function () {
    T::connect();
    $linked = T::membership('Gold', 29);
    T::linked($linked);
    $unlinked = T::membership('Silver', 19);

    ob_start();
    Plandalf_Mepr_List_Columns::membership_cell(Plandalf_Mepr_List_Columns::COLUMN, $linked->ID);
    T::contains('Plandalf checkout', ob_get_clean());

    ob_start();
    Plandalf_Mepr_List_Columns::membership_cell(Plandalf_Mepr_List_Columns::COLUMN, $unlinked->ID);
    T::contains('No Plandalf price', ob_get_clean());
});
