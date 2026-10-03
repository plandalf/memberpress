<?php

defined('ABSPATH') || exit;

use Plandalf_Test as T;

foreach ([1900, 0] as $paid) {
    T::add('recovery: uses the paid invoice amount '.$paid.' and deduplicates a late event', function () use ($paid) {
        T::connect();
        $membership = T::membership('Recovery Gold', 29);
        $first = T::invoice($membership, ['subscription' => ['current_period_end' => time() + DAY_IN_SECONDS]]);
        T::deliver(T::event('invoice.paid', $first));
        $renewal = array_replace_recursive($first, [
            'id' => 'in_recovered', 'number' => 'INV-RECOVERED', 'billing_reason' => 'subscription_cycle',
            'livemode' => false, 'refunded' => false, 'total' => $paid, 'amount_paid' => $paid,
            'subscription' => ['current_period_end' => time() + 30 * DAY_IN_SECONDS],
            'lines' => [['amount' => $paid]],
        ]);
        T::route('/api/v1/subscriptions/'.$first['subscription']['id'].'?include_recovery=1', fn () => [200,
            $renewal['subscription'] + ['recovery' => ['status' => 'ready', 'invoice' => $renewal]],
        ]);
        T::same(1, Plandalf_Mepr_Reconcile::run()['renewed']);
        $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
        T::true($txn instanceof MeprTransaction);
        T::same((float) ($paid / 100), (float) $txn->total);
        T::same('complete', $txn->status);
        T::same(gmdate('Y-m-d', $renewal['subscription']['current_period_end']), substr($txn->expires_at, 0, 10));
        $mail = count(T::$mail);
        T::same('applied', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
        T::same(1, count(Plandalf_Mepr_Fulfillment::transactions_for_invoice($renewal['number'])));
        T::same($mail, count(T::$mail));
    });
}

foreach (['missing', 'unavailable', 'mode', 'customer', 'subscription', 'currency', 'membership', 'period'] as $invalid) {
    T::add('recovery: leaves '.$invalid.' evidence unresolved without extending access', function () use ($invalid) {
        T::connect();
        $membership = T::membership('Recovery Gold', 29);
        $first = T::invoice($membership, ['subscription' => ['current_period_end' => time() + DAY_IN_SECONDS]]);
        T::deliver(T::event('invoice.paid', $first));
        $renewal = array_replace_recursive($first, [
            'id' => 'in_recovered', 'number' => 'INV-RECOVERED', 'billing_reason' => 'subscription_cycle',
            'livemode' => false, 'refunded' => false,
            'subscription' => ['current_period_end' => time() + 30 * DAY_IN_SECONDS],
        ]);
        if ($invalid === 'mode') {
            $renewal['livemode'] = true;
        }
        if ($invalid === 'customer') {
            $renewal['customer']['email'] = 'another@example.test';
        }
        if ($invalid === 'subscription') {
            $renewal['subscription']['id'] = 'sub_other';
        }
        if ($invalid === 'currency') {
            $renewal['currency'] = 'eur';
        }
        if ($invalid === 'membership') {
            $renewal['lines'][0]['links'] = [];
        }
        if ($invalid === 'period') {
            $renewal['subscription']['current_period_end'] = 0;
        }
        $remote = array_replace($first['subscription'], ['current_period_end' => time() + 30 * DAY_IN_SECONDS]);
        if ($invalid !== 'missing') {
            $remote['recovery'] = ['status' => $invalid === 'unavailable' ? 'unavailable' : 'ready', 'invoice' => $renewal];
        }
        T::route('/api/v1/subscriptions/'.$first['subscription']['id'], fn () => [200, $remote]);
        $user = get_user_by('email', $first['customer']['email']);
        $before = count(T::transactions($user->ID));
        $result = Plandalf_Mepr_Reconcile::run();
        T::same(0, $result['renewed']);
        T::true($result['unresolved'] >= 1);
        T::same($before, count(T::transactions($user->ID)));
        T::same(null, Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']));
    });
}

T::add('recovery: a fully refunded invoice grants no access and suppresses a late paid event', function () {
    T::connect();
    $membership = T::membership('Recovery Gold', 29);
    $first = T::invoice($membership, ['subscription' => ['current_period_end' => time() + DAY_IN_SECONDS]]);
    T::deliver(T::event('invoice.paid', $first));
    $renewal = array_replace_recursive($first, [
        'id' => 'in_refunded_recovery', 'number' => 'INV-REFUNDED-RECOVERY', 'billing_reason' => 'subscription_cycle',
        'livemode' => false, 'refunded' => true,
        'subscription' => ['current_period_end' => time() + 30 * DAY_IN_SECONDS],
    ]);
    T::route('/api/v1/subscriptions/'.$first['subscription']['id'], fn () => [200,
        $renewal['subscription'] + ['recovery' => ['status' => 'ready', 'invoice' => $renewal]],
    ]);
    T::same(0, Plandalf_Mepr_Reconcile::run()['renewed']);
    T::same(null, Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']));
    T::same(1, count(Plandalf_Mepr_Event_Log::full_refunds_for($renewal)));
    T::same('ignored', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    T::same(null, Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']));
});

T::add('recovery: repeated refund checks retain the identity of a legacy recovered payment', function () {
    T::connect();
    $membership = T::membership('Recovery Gold', 29);
    $first = T::invoice($membership, ['subscription' => ['current_period_end' => time() - DAY_IN_SECONDS]]);
    T::deliver(T::event('invoice.paid', $first));
    $sub = MeprSubscription::get_one_by_subscr_id($first['subscription']['id']);
    $end = time() + DAY_IN_SECONDS;
    Plandalf_Mepr_Fulfillment::extend_to(Plandalf_Mepr_Plugin::gateway(), $sub, $end, 'renewal:'.$sub->subscr_id.':'.$end);
    $renewal = array_replace_recursive($first, [
        'id' => 'in_legacy_refund', 'number' => 'INV-LEGACY-REFUND', 'billing_reason' => 'subscription_cycle',
        'livemode' => false, 'refunded' => true, 'subscription' => ['current_period_end' => $end],
    ]);
    T::route('/api/v1/subscriptions/'.$sub->subscr_id, fn () => [200,
        $renewal['subscription'] + ['recovery' => ['status' => 'ready', 'invoice' => $renewal]],
    ]);
    Plandalf_Mepr_Reconcile::run();
    $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($renewal['number']);
    T::true($txn instanceof MeprTransaction);
    T::same('refunded', $txn->status);
    T::same('ignored', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    T::same(0, Plandalf_Mepr_Reconcile::run()['renewed']);
    T::same('ignored', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    T::same('refunded', (new MeprTransaction($txn->id))->status);
});
