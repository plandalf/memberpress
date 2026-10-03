<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('purchase: first subscription payment creates the member, subscription and paid period', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);

    T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);

    $user = get_user_by('email', $invoice['customer']['email']);
    T::true($user instanceof WP_User, 'member created');
    T::same('Grace', $user->first_name);

    $sub = MeprSubscription::get_one_by_subscr_id($invoice['subscription']['id']);
    T::true($sub instanceof MeprSubscription, 'subscription created');
    T::same(MeprSubscription::$active_str, $sub->status);
    T::same((string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION), (string) $sub->gateway, 'Plandalf payment method');

    $payment = current(array_filter(T::transactions($user->ID), fn ($t) => $t->txn_type === 'payment'));
    T::same($invoice['number'], $payment->trans_num);
    T::same('complete', $payment->status);
    T::same(gmdate('Y-m-d', $invoice['subscription']['current_period_end']), substr($payment->expires_at, 0, 10), 'paid to period end');
    T::true(in_array($membership->ID, (new MeprUser($user->ID))->active_product_subscriptions('ids')), 'membership active');
});

T::add('purchase: a logged-in member (wp:<id>) gets the membership on their own account', function () {
    T::connect();
    $existing = self_user('existing@example.com');
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership, ['customer' => ['email' => 'different@example.com', 'external_id' => 'wp:'.$existing]]);

    T::deliver(T::event('invoice.paid', $invoice));

    T::false(get_user_by('email', 'different@example.com') instanceof WP_User, 'no new account');
    T::true(in_array($membership->ID, (new MeprUser($existing))->active_product_subscriptions('ids')));
});

T::add('purchase: one-off price grants a lifetime membership', function () {
    T::connect();
    $membership = T::membership('Lifetime', 199, 'lifetime');
    $invoice = T::invoice($membership, ['recurring' => false, 'lines' => [0 => ['amount' => 19900]]]);

    T::deliver(T::event('invoice.paid', $invoice));

    $user = get_user_by('email', $invoice['customer']['email']);
    $txn = T::transactions($user->ID)[0];
    T::same('complete', $txn->status);
    T::same('199.00', number_format((float) $txn->total, 2, '.', ''));
    T::same(MeprUtils::db_lifetime(), $txn->expires_at, 'never expires');
});

T::add('purchase: lines not linked to a membership on this site are ignored', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership, ['lines' => [0 => ['links' => [T::grants($membership, 'another-site.example')]]]]);

    $response = T::deliver(T::event('invoice.paid', $invoice));

    T::same('ignored', $response->get_data()['status']);
    T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User, 'no member created');
});

T::add('renewal: extends access to the new period end, once', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $first = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $first));

    $renewal = $first;
    $renewal['id'] = 'in_renewal';
    $renewal['number'] = 'STRIPE-'.wp_generate_password(8, false);
    $renewal['billing_reason'] = 'subscription_cycle';
    $renewal['subscription']['current_period_end'] = time() + 60 * DAY_IN_SECONDS;
    $event = T::event('invoice.paid', $renewal);

    T::deliver($event);
    T::deliver(array_merge($event, ['id' => 'evt_other_id_same_invoice']));

    $user = get_user_by('email', $first['customer']['email']);
    $payments = array_values(array_filter(T::transactions($user->ID), fn ($t) => $t->txn_type === 'payment'));
    T::same(2, count($payments), 'first + renewal');
    T::same($renewal['number'], $payments[1]->trans_num);
    T::same(gmdate('Y-m-d', $renewal['subscription']['current_period_end']), substr($payments[1]->expires_at, 0, 10));
});

T::add('renewal: for a subscription this site does not know is ignored', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership, ['billing_reason' => 'subscription_cycle']);

    T::same('ignored', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
});

T::add('renewal: a late paid event identifies the recovered period so a refund reaches the same transaction', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $first = T::invoice($membership);
    $first['subscription']['current_period_end'] = time() + DAY_IN_SECONDS;
    T::deliver(T::event('invoice.paid', $first));
    $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id']);
    $end = time() + 60 * DAY_IN_SECONDS;
    $synthetic = 'renewal:'.$sub->subscr_id.':'.$end;
    $gateway = Plandalf_Mepr_Plugin::gateway();
    // Fixture from the older plugin, which used synthetic recovery identifiers.
    Plandalf_Mepr_Fulfillment::extend_to($gateway, $sub, $end, $synthetic);
    $recovered = Plandalf_Mepr_Fulfillment::transaction_for_invoice($synthetic);

    // A newer period must not prevent the older recovered payment being identified.
    $laterEnd = $end + 30 * DAY_IN_SECONDS;
    Plandalf_Mepr_Fulfillment::extend_to($gateway, $sub, $laterEnd, 'renewal:'.$sub->subscr_id.':'.$laterEnd);
    $renewal = array_replace($first, [
        'id' => 'in_late_recovered',
        'number' => 'INV-LATE-RECOVERED',
        'billing_reason' => 'subscription_cycle',
        'subscription' => array_replace($first['subscription'], ['current_period_end' => $end]),
    ]);
    $beforeCount = count(T::transactions($sub->user_id));
    $beforeMail = count(T::$mail);
    T::same('applied', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    $identified = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
    T::true($identified instanceof MeprTransaction, 'recovered payment now has the invoice number');
    T::same($recovered->id, $identified->id, 'same payment');
    T::same($recovered->expires_at, $identified->expires_at, 'same paid period');
    T::same($beforeCount, count(T::transactions($sub->user_id)), 'no duplicate payment');
    T::same($beforeMail, count(T::$mail), 'no second receipt');

    T::same('applied', T::deliver(T::event('invoice.refunded', $renewal + ['refunded' => true]))->get_data()['status']);
    T::same('refunded', (new MeprTransaction($recovered->id))->status);
    T::deliver(T::event('invoice.paid', $renewal));
    T::same('refunded', (new MeprTransaction($recovered->id))->status, 'late duplicate does not restore refunded access');
    T::same($beforeCount, count(T::transactions($sub->user_id)), 'duplicate paid event adds no payment');
    T::same('complete', Plandalf_Mepr_Fulfillment::transaction_for_invoice('renewal:'.$sub->subscr_id.':'.$laterEnd)->status, 'newer period is untouched');
});

T::add('renewal: recovery matching does not rename a different subscription transaction', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $first = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $first));
    $other = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $other));
    $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id']);
    $otherSub = MeprSubscription::get_one_by_subscr_id($other['subscription']['id']);
    $end = time() + 60 * DAY_IN_SECONDS;
    $synthetic = 'renewal:'.$sub->subscr_id.':'.$end;
    Plandalf_Mepr_Fulfillment::extend_to(Plandalf_Mepr_Plugin::gateway(), $otherSub, $end, $synthetic);
    $foreign = Plandalf_Mepr_Fulfillment::transaction_for_invoice($synthetic);
    $renewal = array_replace($first, [
        'id' => 'in_other_scope', 'number' => 'INV-OTHER-SCOPE', 'billing_reason' => 'subscription_cycle',
        'subscription' => array_replace($first['subscription'], ['current_period_end' => $end]),
    ]);
    T::deliver(T::event('invoice.paid', $renewal));
    T::same($synthetic, (new MeprTransaction($foreign->id))->trans_num, 'foreign transaction unchanged');
    T::same((int) $sub->id, (int) Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number'])->subscription_id);
});

T::add('renewal: a recovered refund stays refunded when its paid event arrives', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $first = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $first));
    $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id']);
    $end = time() + 60 * DAY_IN_SECONDS;
    $synthetic = 'renewal:'.$sub->subscr_id.':'.$end;
    Plandalf_Mepr_Fulfillment::extend_to(Plandalf_Mepr_Plugin::gateway(), $sub, $end, $synthetic);
    $recovered = Plandalf_Mepr_Fulfillment::transaction_for_invoice($synthetic);
    $recovered->status = MeprTransaction::$refunded_str;
    $recovered->store();
    $renewal = array_replace($first, [
        'id' => 'in_refunded_recovery', 'number' => 'INV-REFUNDED-RECOVERY', 'billing_reason' => 'subscription_cycle',
        'subscription' => array_replace($first['subscription'], ['current_period_end' => $end]),
    ]);
    $count = count(T::transactions($sub->user_id));
    T::deliver(T::event('invoice.paid', $renewal));
    $identified = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
    T::true($identified instanceof MeprTransaction);
    T::same($recovered->id, $identified->id);
    T::same('refunded', $identified->status);
    T::same($count, count(T::transactions($sub->user_id)));
});

T::add('renewal: recovered membership payments keep their invoice suffixes', function () {
    T::connect();
    $gold = T::membership('Gold', 29);
    $silver = T::membership('Silver', 29);
    $first = T::invoice($gold);
    $first['lines'][0]['links'][] = T::grants($silver);
    T::deliver(T::event('invoice.paid', $first));
    $end = time() + 60 * DAY_IN_SECONDS;
    $ids = [];
    foreach ([$gold, $silver] as $membership) {
        $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id'].':'.$membership->ID);
        $synthetic = 'renewal:'.$sub->subscr_id.':'.$end;
        Plandalf_Mepr_Fulfillment::extend_to(Plandalf_Mepr_Plugin::gateway(), $sub, $end, $synthetic);
        $ids[$membership->ID] = Plandalf_Mepr_Fulfillment::transaction_for_invoice($synthetic)->id;
    }
    $renewal = array_replace($first, [
        'id' => 'in_multiple_recovery', 'number' => 'INV-MULTIPLE-RECOVERY', 'billing_reason' => 'subscription_cycle',
        'subscription' => array_replace($first['subscription'], ['current_period_end' => $end]),
    ]);
    T::deliver(T::event('invoice.paid', $renewal));
    T::same(2, count(Plandalf_Mepr_Fulfillment::transactions_for_invoice($renewal['number'])));
    foreach ($ids as $membership_id => $id) {
        T::same($id, Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number'].':'.$membership_id)->id);
    }
    T::deliver(T::event('invoice.refunded', $renewal + ['refunded' => true]));
    foreach ($ids as $id) {
        T::same('refunded', (new MeprTransaction($id))->status);
    }
});

T::add('refund: a full refund ends access; a partial refund does not', function () {
    T::connect();
    $membership = T::membership('Lifetime', 199, 'lifetime');
    $invoice = T::invoice($membership, ['recurring' => false]);
    T::deliver(T::event('invoice.paid', $invoice));
    $user = get_user_by('email', $invoice['customer']['email']);

    T::same('ignored', T::deliver(T::event('invoice.refunded', $invoice + ['refunded' => false, 'amount_refunded' => 100]))->get_data()['status'], 'partial');
    T::same('complete', T::transactions($user->ID)[0]->status);

    T::same('applied', T::deliver(T::event('invoice.refunded', $invoice + ['refunded' => true, 'amount_refunded' => 19900]))->get_data()['status'], 'full');
    T::same('refunded', T::transactions($user->ID)[0]->status);
});

T::add('refund order: full refund before first payment never grants a membership', function () {
    T::connect();
    $membership = T::membership('Refunded before delivery', 29);
    $invoice = T::invoice($membership);
    $refund = T::event('invoice.refunded', $invoice + ['refunded' => true]);
    T::same('waiting', T::deliver($refund)->get_data()['status']);
    T::true(T::deliver($refund)->get_data()['duplicate'] ?? false);
    T::same('ignored', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
    T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User, 'no buyer account created');
    T::same([], Plandalf_Mepr_Fulfillment::transactions_for_invoice($invoice['number']));
    T::same('applied', Plandalf_Mepr_Event_Log::find($refund['id'])['status']);
    T::false(Plandalf_Mepr_Event_Log::invoice_applied($invoice['number']), 'refunded invoice is not activated');
    T::same('ignored', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status'], 'different paid event ID cannot restore access');
    T::same([], T::mail_to($invoice['customer']['email']), 'no welcome, password or receipt email');
});

T::add('refund order: saved refund is applied when a recovered renewal gets its invoice identity', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $first = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $first));
    $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id']);
    $end = time() + 60 * DAY_IN_SECONDS;
    $synthetic = 'renewal:'.$sub->subscr_id.':'.$end;
    Plandalf_Mepr_Fulfillment::extend_to(Plandalf_Mepr_Plugin::gateway(), $sub, $end, $synthetic);
    $recovered = Plandalf_Mepr_Fulfillment::transaction_for_invoice($synthetic);
    $renewal = array_replace($first, [
        'id' => 'in_refund_first', 'number' => 'INV-REFUND-FIRST', 'billing_reason' => 'subscription_cycle',
        'subscription' => array_replace($first['subscription'], ['current_period_end' => $end]),
    ]);
    $refund = T::event('invoice.refunded', $renewal + ['refunded' => true]);
    T::same('waiting', T::deliver($refund)->get_data()['status']);
    $count = count(T::transactions($sub->user_id));
    $missing_period = $renewal;
    unset($missing_period['subscription']['current_period_end']);
    T::same(500, T::deliver(T::event('invoice.paid', $missing_period))->get_status(), 'missing period must not claim reconciliation');
    T::same('waiting', Plandalf_Mepr_Event_Log::find($refund['id'])['status']);
    T::same('complete', (new MeprTransaction($recovered->id))->status, 'do not guess which recovered period to refund');
    T::same('ignored', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
    T::same($recovered->id, $txn->id);
    T::same('refunded', $txn->status);
    T::same('applied', Plandalf_Mepr_Event_Log::find($refund['id'])['status']);
    T::same($count, count(T::transactions($sub->user_id)), 'no new payment');
    $mail = count(T::$mail);
    T::same('ignored', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    T::deliver($refund);
    T::same($mail, count(T::$mail), 'no repeated refund notice');
    T::same('complete', Plandalf_Mepr_Fulfillment::transaction_for_invoice($first['number'])->status, 'other paid period unchanged');
});

T::add('refund order: invoice identity and connected account must match', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    $refund = T::event('invoice.refunded', $invoice + ['refunded' => true]);
    T::deliver($refund);
    $other = $invoice;
    $other['id'] = 'different-invoice-same-number';
    T::same('applied', T::deliver(T::event('invoice.paid', $other))->get_data()['status'], 'invoice number alone is insufficient');
    T::same(500, T::deliver(T::event('invoice.paid', $invoice))->get_status(), 'ambiguous transaction identity requires reconciliation');
    T::same('complete', Plandalf_Mepr_Fulfillment::transaction_for_invoice($other['number'])->status, 'other invoice cannot be refunded');

    $next = T::invoice($membership);
    T::deliver(T::event('invoice.refunded', $next + ['refunded' => true]));
    $organization = Plandalf_Mepr_Settings::get('organization');
    $organization['id'] = 2;
    Plandalf_Mepr_Settings::update(['organization' => $organization]);
    T::same('applied', T::deliver(T::event('invoice.paid', $next))->get_data()['status'], 'previous connection cannot suppress access');
});

T::add('refund order: partial refunds before paid delivery do not suppress access', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    T::same('ignored', T::deliver(T::event('invoice.refunded', $invoice + ['refunded' => false]))->get_data()['status']);
    T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
    T::true(get_user_by('email', $invoice['customer']['email']) instanceof WP_User);
});

T::add('cancel: cancelled in Plandalf marks the MemberPress subscription cancelled; reactivation restores it', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $invoice));
    $id = $invoice['subscription']['id'];

    T::deliver(T::event('subscription.updated', ['object' => 'subscription', 'id' => $id, 'status' => 'active', 'cancel_at_period_end' => true]));
    T::same(MeprSubscription::$cancelled_str, MeprSubscription::get_one_by_subscr_id($id)->status, 'scheduled cancel');

    T::deliver(T::event('subscription.updated', ['object' => 'subscription', 'id' => $id, 'status' => 'active', 'cancel_at_period_end' => false]));
    T::same(MeprSubscription::$active_str, MeprSubscription::get_one_by_subscr_id($id)->status, 'undone');

    T::deliver(T::event('subscription.canceled', ['object' => 'subscription', 'id' => $id, 'status' => 'canceled']));
    T::same(MeprSubscription::$cancelled_str, MeprSubscription::get_one_by_subscr_id($id)->status, 'ended');
});

T::add('cancel: the member cancelling in MemberPress cancels in Plandalf at period end', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    T::deliver(T::event('invoice.paid', $invoice));
    $id = $invoice['subscription']['id'];

    $sent = null;
    T::route('/api/v1/subscriptions/'.$id.'/cancel', function ($args, $body) use (&$sent, $id) {
        $sent = $body;

        return [200, ['object' => 'subscription', 'id' => $id, 'status' => 'active', 'cancel_at_period_end' => true]];
    });

    $sub = MeprSubscription::get_one_by_subscr_id($id);
    Plandalf_Mepr_Plugin::gateway()->process_cancel_subscription($sub->id);

    T::same(['at_period_end' => true], $sent);
    T::same(MeprSubscription::$cancelled_str, MeprSubscription::get_one_by_subscr_id($id)->status);
});

function self_user(string $email): int
{
    return (int) wp_insert_user(['user_login' => sanitize_user(strstr($email, '@', true)), 'user_email' => $email, 'user_pass' => wp_generate_password()]);
}

foreach (['no local trial' => [], 'longer local trial' => ['trial' => true, 'trial_days' => 30, 'trial_amount' => 0], 'paid local trial' => ['trial' => true, 'trial_days' => 1, 'trial_amount' => 5]] as $case => $settings) {
    T::add('trial access: provider expiry overrides '.$case, function () use ($settings) {
        T::connect();
        $membership = T::membership('Trial membership', 29, 'months', $settings);
        $end = time() + 7 * DAY_IN_SECONDS;
        $invoice = trial_access_invoice($membership, $end);
        T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
        $user = get_user_by('email', $invoice['customer']['email']);
        $txns = T::transactions($user->ID);
        T::same(1, count($txns), 'one confirmation, no invented payment');
        T::same($invoice['number'], $txns[0]->trans_num);
        T::same(MeprTransaction::$subscription_confirmation_str, $txns[0]->txn_type);
        T::same(MeprTransaction::$confirmed_str, $txns[0]->status);
        T::same(0.0, (float) $txns[0]->total);
        T::same(gmdate('Y-m-d H:i:s', $end), $txns[0]->expires_at, 'exact provider expiry');
        T::true(in_array($membership->ID, (new MeprUser($user->ID))->active_product_subscriptions('ids')));
    });
}

T::add('trial access: delayed initial delivery does not restart an expired trial', function () {
    T::connect();
    $membership = T::membership('Expired trial', 29, 'months', ['trial' => true, 'trial_days' => 30, 'trial_amount' => 0]);
    $end = time() - DAY_IN_SECONDS;
    $invoice = trial_access_invoice($membership, $end);
    T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
    $user = get_user_by('email', $invoice['customer']['email']);
    T::same(gmdate('Y-m-d H:i:s', $end), T::transactions($user->ID)[0]->expires_at);
    T::false(in_array($membership->ID, (new MeprUser($user->ID))->active_product_subscriptions('ids')));
});

foreach (['missing' => null, 'zero' => 0, 'negative' => -1, 'string' => '1900000000'] as $case => $end) {
    T::add('trial access: '.$case.' provider expiry fails before creating a buyer', function () use ($end) {
        T::connect();
        $invoice = trial_access_invoice(T::membership('Invalid trial', 29), $end);
        T::same(500, T::deliver(T::event('invoice.paid', $invoice))->get_status());
        T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User);
        T::same(0, count(T::$mail));
    });
}

T::add('trial access: duplicate, cancellation, reactivation and first paid renewal preserve the correct period', function () {
    T::connect();
    $membership = T::membership('Trial lifecycle', 29);
    $end = time() + 7 * DAY_IN_SECONDS;
    $invoice = trial_access_invoice($membership, $end);
    $event = T::event('invoice.paid', $invoice);
    T::deliver($event);
    $user = get_user_by('email', $invoice['customer']['email']);
    $before = serialize(T::transactions($user->ID));
    $mail = count(T::$mail);
    T::deliver($event);
    T::deliver(T::event('invoice.paid', $invoice));
    T::same($before, serialize(T::transactions($user->ID)), 'duplicates unchanged');
    T::same($mail, count(T::$mail), 'duplicates send no mail');
    $sub_id = $invoice['subscription']['id'];
    T::deliver(T::event('subscription.updated', ['id' => $sub_id, 'status' => 'trialing', 'cancel_at_period_end' => true]));
    T::same(MeprSubscription::$cancelled_str, MeprSubscription::get_one_by_subscr_id($sub_id)->status);
    T::same($before, serialize(T::transactions($user->ID)), 'scheduled cancellation keeps trial access');
    T::deliver(T::event('invoice.paid', $invoice));
    T::same(MeprSubscription::$cancelled_str, MeprSubscription::get_one_by_subscr_id($sub_id)->status, 'old trial invoice cannot reactivate');
    T::deliver(T::event('subscription.updated', ['id' => $sub_id, 'status' => 'trialing', 'cancel_at_period_end' => false]));
    T::same(MeprSubscription::$active_str, MeprSubscription::get_one_by_subscr_id($sub_id)->status);
    $renewal = array_replace_recursive($invoice, ['id' => 'in_trial_conversion', 'number' => 'TRIAL-PAID-'.wp_rand(), 'billing_reason' => 'subscription_cycle', 'total' => 2900,
        'lines' => [0 => ['amount' => 2900]], 'subscription' => ['status' => 'active', 'current_period_end' => $end + 30 * DAY_IN_SECONDS]]);
    T::deliver(T::event('invoice.paid', $renewal));
    $txns = T::transactions($user->ID);
    T::same(2, count($txns), 'confirmation and first paid renewal');
    $paid = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
    T::same(29.0, (float) $paid->total);
    T::same(MeprTransaction::$complete_str, $paid->status);
    $after = serialize($txns);
    T::deliver(T::event('invoice.paid', $invoice));
    T::same($after, serialize(T::transactions($user->ID)), 'late initial invoice cannot shorten paid access');
});

function trial_access_invoice(MeprProduct $membership, mixed $end): array
{
    return T::invoice($membership, ['total' => 0, 'lines' => [0 => ['amount' => 0]],
        'subscription' => ['status' => 'trialing', 'current_period_end' => $end]]);
}

foreach (['unpaid' => ['status' => 'draft'], 'nonzero total' => ['total' => 2900], 'nonzero line' => ['lines' => [0 => ['amount' => 2900]]]] as $case => $changes) {
    T::add('trial access: rejects '.$case.' before provisioning', function () use ($changes) {
        T::connect();
        $invoice = array_replace_recursive(trial_access_invoice(T::membership('Invalid invoice', 29), time() + DAY_IN_SECONDS), $changes);
        T::same(500, T::deliver(T::event('invoice.paid', $invoice))->get_status());
        T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User);
    });
}

T::add('trial access: retrying a missing-period event uses the supplied period after correction', function () {
    T::connect();
    $invoice = trial_access_invoice(T::membership('Retry trial', 29), null);
    $event = T::event('invoice.paid', $invoice);
    T::same(500, T::deliver($event)->get_status());
    $end = time() + 7 * DAY_IN_SECONDS;
    $event['data']['object']['subscription']['current_period_end'] = $end;
    T::same('applied', T::deliver($event)->get_data()['status']);
    $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($invoice['number']);
    T::same(gmdate('Y-m-d H:i:s', $end), $txn->expires_at);
});

T::add('trial access: another initial invoice cannot replace an existing trial confirmation', function () {
    T::connect();
    $invoice = trial_access_invoice(T::membership('Existing trial', 29), time() + DAY_IN_SECONDS);
    T::deliver(T::event('invoice.paid', $invoice));
    $user = get_user_by('email', $invoice['customer']['email']);
    $before = serialize(T::transactions($user->ID));
    $invoice['id'] = 'in_another_initial';
    $invoice['number'] = 'ANOTHER-'.wp_rand();
    $invoice['subscription']['current_period_end'] += 30 * DAY_IN_SECONDS;
    T::same(500, T::deliver(T::event('invoice.paid', $invoice))->get_status());
    T::same($before, serialize(T::transactions($user->ID)));
});
