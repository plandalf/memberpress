<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('list columns: subscription table accepts MemberPress hook arguments', function () {
    $columns = apply_filters('mepr-admin-subscriptions-cols', [
        'col_name' => 'Name',
        'col_gateway' => 'Gateway',
        'col_created_at' => 'Created On',
    ], 'col_', []);

    T::same(['col_name', 'col_gateway', 'col_plandalf', 'col_created_at'], array_keys($columns));
});

T::add('price links: Memberships list opens the Plandalf product rather than the price lookup key', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $product_key = 'memberpress:'.$membership->ID;
    update_post_meta($membership->ID, Plandalf_Mepr_Links::META_LINKED, [[
        'id' => 501,
        'lookup_key' => 'gold-yearly-test',
        'product_lookup_key' => $product_key,
        'label' => 'Gold yearly · USD 290.00 / year',
    ]]);

    ob_start();
    Plandalf_Mepr_List_Columns::membership_cell(Plandalf_Mepr_List_Columns::COLUMN, $membership->ID);
    $html = ob_get_clean();

    T::contains('https://plandalf.example/products/memberpress%3A'.$membership->ID, $html);
    T::contains('Prices managed in Plandalf', $html);
    T::false(str_contains($html, '/products/gold-yearly-test'));
});

T::add('price links: membership editor explains and links to Plandalf pricing', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $product_key = 'memberpress:'.$membership->ID;
    T::route('/api/v1/links?', static fn () => [200, ['data' => [[
        'id' => 1,
        'external_id' => (string) $membership->ID,
        'price' => [
            'id' => 501,
            'lookup_key' => 'gold-yearly-test',
            'product' => ['name' => 'Gold', 'lookup_key' => $product_key],
            'amount_cents' => 29000,
            'currency' => 'usd',
            'recurring' => ['interval' => 'year', 'interval_count' => 1],
        ],
        'drift' => [],
    ]]]]);
    T::route('/api/v1/prices', static fn () => [200, ['data' => []]]);

    ob_start();
    Plandalf_Mepr_Membership_Tab::page($membership);
    $html = ob_get_clean();

    T::contains('Prices and billing schedules are configured in Plandalf', $html);
    T::contains('https://plandalf.example/products/memberpress%3A'.$membership->ID, $html);
    T::contains('View price in Plandalf', $html);
});

T::add('price links: Plandalf overview refreshes the Memberships list cache', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $other_membership = T::membership('Silver', 19);
    T::linked($other_membership, 502, 'silver-monthly');
    $product_key = 'memberpress:'.$membership->ID;
    T::route('/api/v1/links?', static fn () => [200, ['data' => [[
        'id' => 1,
        'external_id' => (string) $membership->ID,
        'price' => [
            'id' => 501,
            'lookup_key' => 'gold-yearly-test',
            'product' => ['name' => 'Gold', 'lookup_key' => $product_key],
            'amount_cents' => 29000,
            'currency' => 'usd',
            'recurring' => ['interval' => 'year', 'interval_count' => 1],
        ],
        'drift' => [],
    ]]]]);

    ob_start();
    Plandalf_Mepr_Admin::render();
    $html = ob_get_clean();

    T::contains('https://plandalf.example/products/memberpress%3A'.$membership->ID, $html);
    T::same($product_key, Plandalf_Mepr_Links::linked_prices($membership->ID)[0]['product_lookup_key']);
    T::same([502], Plandalf_Mepr_Links::linked_price_ids($other_membership->ID), 'an unreturned link is not removed from the local cache');
});
