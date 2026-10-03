<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

function plandalf_campaign_fixture(bool $enabled = false): MeprProduct
{
    T::connect('test');
    $membership = T::membership('Campaign membership', 29);
    T::linked($membership, 501, 'gold-monthly');
    T::route('/links?', fn () => [200, ['data' => [['id' => 91, 'role' => 'grants', 'price' => ['id' => 501, 'lookup_key' => 'gold-monthly', 'amount_cents' => 2900, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]]);
    T::route('/links/91/promo-bindings', fn () => [200, ['promo' => 'launch', 'binding' => ['link_id' => 91], 'checkout_enabled' => $enabled]]);
    T::route('/promos/launch/catalog-binding', fn () => [200, ['promo' => 'launch', 'binding' => ['link_id' => 91], 'offer' => 'design-offer-ABC', 'checkout_enabled' => $enabled]]);

    return $membership;
}

T::add('campaign: saving an unavailable mapping keeps fixed pricing and refuses selection', function () {
    $m = plandalf_campaign_fixture();
    T::false(is_wp_error(Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch')));
    T::false(Plandalf_Mepr_Campaign::selected($m->ID));
    T::true(is_wp_error(Plandalf_Mepr_Campaign::select($m->ID)));
    $html = Plandalf_Mepr_Checkout::replace_signup_form('original', '/checkout/form', ['product' => $m]);
    T::contains('data-plandalf-price="gold-monthly"', $html);
});

T::add('campaign: linked-price ownership is required before saving', function () {
    $m = plandalf_campaign_fixture();
    T::true(is_wp_error(Plandalf_Mepr_Campaign::save($m->ID, 92, 'launch')));
    T::same([], Plandalf_Mepr_Campaign::saved($m->ID));
});

T::add('campaign: failed remote save preserves the existing local selection', function () {
    $m = plandalf_campaign_fixture();
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    $before = Plandalf_Mepr_Campaign::saved($m->ID);
    T::route('/links/91/promo-bindings', fn () => [422, ['message' => 'Tier no longer grants membership']]);
    T::true(is_wp_error(Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch')));
    T::same($before, Plandalf_Mepr_Campaign::saved($m->ID));
});

T::add('campaign: inline and popup purchases emit mapping attributes without a fixed price', function () {
    $m = plandalf_campaign_fixture(true);
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    T::false(is_wp_error(Plandalf_Mepr_Campaign::select($m->ID)));
    foreach (['inline', 'popup', 'page'] as $display) {
        update_post_meta($m->ID, Plandalf_Mepr_Links::META_DISPLAY, $display);
        $html = Plandalf_Mepr_Checkout::replace_signup_form('original', '/checkout/form', ['product' => $m]);
        T::contains('data-plandalf-catalog-link="91"', $html);
        T::contains('data-plandalf-apply-promo="launch"', $html);
        T::contains('data-plandalf-mode="test"', $html);
        T::false(str_contains($html, 'data-plandalf-price='));
    }
    T::contains('data-plandalf-catalog-link="91"', Plandalf_Mepr_Checkout::shortcode(['membership' => $m->ID]));
});

T::add('campaign: unavailable selected campaign does not fall back to a fixed price or MemberPress form', function () {
    $m = plandalf_campaign_fixture(true);
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    Plandalf_Mepr_Campaign::select($m->ID);
    T::route('/promos/launch/catalog-binding', fn () => [200, ['promo' => 'launch', 'binding' => ['link_id' => 91], 'offer' => 'design-offer-ABC', 'checkout_enabled' => false]]);
    $html = Plandalf_Mepr_Checkout::replace_signup_form('original form', '/checkout/form', ['product' => $m]);
    T::contains('campaign is unavailable', $html);
    T::false(str_contains($html, 'original form'));
    T::false(str_contains($html, 'data-plandalf-price='));
});

T::add('campaign: changed design and removed links invalidate the selection', function () {
    $m = plandalf_campaign_fixture(true);
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    update_post_meta($m->ID, Plandalf_Mepr_Links::META_OFFER, 'other-design');
    T::true(is_wp_error(Plandalf_Mepr_Campaign::status($m->ID)));
    delete_post_meta($m->ID, Plandalf_Mepr_Links::META_OFFER);
    T::route('/links?', fn () => [200, ['data' => []]]);
    T::true(is_wp_error(Plandalf_Mepr_Campaign::status($m->ID)));
});

T::add('campaign: forgetting restores fixed pricing without deleting remote campaign data', function () {
    $m = plandalf_campaign_fixture(true);
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    Plandalf_Mepr_Campaign::select($m->ID);
    Plandalf_Mepr_Campaign::forget($m->ID);
    T::false(Plandalf_Mepr_Campaign::selected($m->ID));
    T::same([], Plandalf_Mepr_Campaign::saved($m->ID));
    T::contains('data-plandalf-price="gold-monthly"', Plandalf_Mepr_Checkout::replace_signup_form('original', '/checkout/form', ['product' => $m]));
});

T::add('campaign: server errors and mapping identity mismatches keep the page unavailable', function () {
    $m = plandalf_campaign_fixture(true);
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    Plandalf_Mepr_Campaign::select($m->ID);
    foreach ([
        [503, ['message' => 'Service unavailable']],
        [200, ['promo' => 'other', 'binding' => ['link_id' => 91], 'offer' => 'design-offer-ABC', 'checkout_enabled' => true]],
        [200, ['promo' => 'launch', 'binding' => ['link_id' => 92], 'offer' => 'design-offer-ABC', 'checkout_enabled' => true]],
        [200, ['promo' => 'launch', 'binding' => ['link_id' => 91], 'offer' => 'other-design', 'checkout_enabled' => true]],
    ] as $response) {
        T::route('/promos/launch/catalog-binding', fn () => $response);
        T::contains('campaign is unavailable', Plandalf_Mepr_Checkout::shortcode(['membership' => $m->ID]));
    }
});

T::add('campaign: membership save requires nonce and edit permission', function () {
    $m = plandalf_campaign_fixture();
    $_POST = ['plandalf_action' => 'campaign-save', 'plandalf_campaign_slug' => 'launch', 'plandalf_campaign_link' => 91];
    Plandalf_Mepr_Membership_Tab::save($m);
    T::same([], Plandalf_Mepr_Campaign::saved($m->ID));
    $_POST['_plandalf_nonce'] = wp_create_nonce('plandalf_mepr_membership');
    Plandalf_Mepr_Membership_Tab::save($m);
    T::same([], Plandalf_Mepr_Campaign::saved($m->ID));
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    T::true(count($admins) === 1, 'local admin fixture exists');
    wp_set_current_user((int) $admins[0]);
    $_POST['_plandalf_nonce'] = wp_create_nonce('plandalf_mepr_membership');
    Plandalf_Mepr_Membership_Tab::save($m);
    T::same('launch', Plandalf_Mepr_Campaign::saved($m->ID)['promo']);
    T::false(Plandalf_Mepr_Campaign::selected($m->ID));
});

T::add('campaign: admin controls show saved but unavailable status', function () {
    $m = plandalf_campaign_fixture();
    Plandalf_Mepr_Campaign::save($m->ID, 91, 'launch');
    T::route('/prices', fn () => [200, ['data' => []]]);
    ob_start();
    Plandalf_Mepr_Membership_Tab::page($m);
    $html = ob_get_clean();
    T::contains('Mapping saved. Campaign checkout is not available yet.', $html);
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $button = (new DOMXPath($dom))->query('//button[@value="campaign-select"]')->item(0);
    T::true($button instanceof DOMElement && $button->hasAttribute('disabled'));
    T::contains('value="launch"', $html);
});
