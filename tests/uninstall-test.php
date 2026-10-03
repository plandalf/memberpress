<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

require_once dirname(__DIR__).'/includes/class-plandalf-mepr-uninstall.php';

T::add('uninstall: unregisters the event endpoint and forgets the site, keeping MemberPress records', function () {
    T::route('/api/v1/webhook_endpoints', fn () => [200, ['deleted' => true]]);
    T::connect();
    $gateway_id = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);
    $membership = T::membership('Gold', 29.00);
    T::linked($membership);
    update_post_meta($membership->ID, '_plandalf_display', 'popup');
    $member = wp_insert_user(['user_login' => 'uninstall-'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'uninstall@example.com']);
    update_user_meta($member, '_plandalf_after_password', home_url('/gold'));
    set_transient('plandalf_mepr_notice_1', ['type' => 'success', 'message' => 'x'], 60);
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'plandalf_mepr_reconcile');

    Plandalf_Mepr_Uninstall::forget_site();

    $deleted = current(array_filter(T::$requests, fn ($r) => str_contains($r['url'], 'webhook_endpoints/7') && $r['method'] === 'DELETE'));
    T::true($deleted !== false, 'endpoint unregistered');
    foreach (Plandalf_Mepr_Uninstall::OPTIONS as $option) {
        T::false(get_option($option), $option);
    }
    T::false(get_transient('plandalf_mepr_notice_1'), 'transients');
    T::same('', get_post_meta($membership->ID, '_plandalf_display', true));
    T::same('', get_user_meta($member, '_plandalf_after_password', true));
    T::false(wp_next_scheduled('plandalf_mepr_reconcile'), 'daily check unscheduled');

    T::true(get_post($membership->ID) instanceof WP_Post, 'membership kept');
    T::true(isset(MeprOptions::fetch()->integrations[$gateway_id]), 'payment method entry kept');
});

T::add('reinstall: reuses the Plandalf payment method the earlier install left', function () {
    T::connect();
    $gateway_id = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);
    $before = count(MeprOptions::fetch()->integrations);

    delete_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION);
    Plandalf_Mepr_Plugin::ensure_gateway();

    T::same($gateway_id, (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION));
    T::same($before, count(MeprOptions::fetch()->integrations), 'no second Plandalf payment method');
});
