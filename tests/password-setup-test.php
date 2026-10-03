<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('password: a new member gets one set-password link — emailed and handed to the thank-you page once', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $invoice));

    $emails = T::mail_to($invoice['customer']['email']);
    $set_password = array_values(array_filter($emails, fn ($m) => str_contains($m['subject'], 'Set Your New Password')));
    T::same(1, count($set_password), 'one set-password email');

    $request = new WP_REST_Request('GET', '/plandalf/v1/purchase-status');
    $request->set_param('invoice', $invoice['number']);
    T::false(isset(Plandalf_Mepr_Events::purchase_status($request)->get_data()['set_password_url']), 'no ref, no link');

    $request->set_param('ref', '01JWRONGWRONGWRONGWRONGWRO');
    T::false(isset(Plandalf_Mepr_Events::purchase_status($request)->get_data()['set_password_url']), 'wrong ref');

    $request->set_param('ref', $invoice['id']);
    $link = Plandalf_Mepr_Events::purchase_status($request)->get_data()['set_password_url'] ?? '';
    T::contains('action=reset_password', $link);
    T::contains(str_replace('&', '&#038;', parse_url($link, PHP_URL_QUERY)), $set_password[0]['message'], 'same link as the email');

    T::false(isset(Plandalf_Mepr_Events::purchase_status($request)->get_data()['set_password_url']), 'only once');
});

T::add('password: existing accounts are never handed a set-password link', function () {
    T::connect();
    self_user('regular@example.com');
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership, ['customer' => ['email' => 'regular@example.com']]);
    T::deliver(T::event('invoice.paid', $invoice));

    $request = new WP_REST_Request('GET', '/plandalf/v1/purchase-status');
    $request->set_param('invoice', $invoice['number']);
    $request->set_param('ref', $invoice['id']);

    T::true(Plandalf_Mepr_Events::purchase_status($request)->get_data()['applied']);
    T::false(isset(Plandalf_Mepr_Events::purchase_status($request)->get_data()['set_password_url']));
    T::same(0, count(array_filter(T::mail_to('regular@example.com'), fn ($m) => str_contains($m['subject'], 'Set Your New Password'))), 'no set-password email');
});

T::add('password: setting it sends the member to what they bought and skips the admin "password changed" email', function () {
    T::connect();
    $membership = T::membership('Gold', 29, 'months', ['access_url' => home_url('/members/gold/')]);
    $invoice = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $invoice));
    $user = get_user_by('email', $invoice['customer']['email']);
    T::$mail = [];

    $_POST['mepr_screenname'] = $user->user_login;
    MeprHooks::apply_filters('mepr-validate-reset-password', []);
    $key = get_password_reset_key($user);
    (new MeprUser($user->ID))->set_password_and_send_notifications($key, 'N3w-passw0rd!');

    T::true(wp_check_password('N3w-passw0rd!', get_userdata($user->ID)->user_pass, $user->ID), 'password really set');
    T::true((bool) array_filter(T::$mail, fn ($m) => str_contains($m['subject'], 'Your new Password')), 'member still told');
    $admin = array_filter(T::$mail, fn ($m) => str_contains($m['subject'], 'Password Lost/Changed'));
    T::same(0, count($admin), 'admin email dropped');

    T::same(home_url('/members/gold/'), apply_filters('mepr-process-login-redirect-url', home_url('/account/'), $user), 'goes to content');
    T::same(home_url('/account/'), apply_filters('mepr-process-login-redirect-url', home_url('/account/'), $user), 'only once');
});

T::add('password: other members resetting still notify the admin as usual', function () {
    T::connect();
    $user = get_userdata(self_user('someone@example.com'));

    // MemberPress sends these at most once per PHP process (a static guard), and the
    // previous test used it up, so replay its exact sequence: validate → title → mail.
    $_POST['mepr_screenname'] = $user->user_login;
    MeprHooks::apply_filters('mepr-validate-reset-password', []);
    $subject = MeprHooks::apply_filters('mepr_admin_pw_reset_title', sprintf('[%s] Password Lost/Changed', MeprUtils::blogname()));
    MeprUtils::wp_mail_to_admin($subject, 'body', ['Content-Type: text/html']);

    T::same(1, count(array_filter(T::$mail, fn ($m) => str_contains($m['subject'], 'Password Lost/Changed'))));
});
