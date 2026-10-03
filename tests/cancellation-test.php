<?php

defined('ABSPATH') || exit;

use Plandalf_Test as T;

foreach (['not-found', 'forbidden', 'unavailable', 'empty', 'wrong-subscription', 'still-active', 'string-flag'] as $case) {
    T::add('cancellation evidence: account cancellation rejects '.$case, function () use ($case) {
        T::connect();
        $first = T::invoice(T::membership('Cancellation Gold', 29));
        T::deliver(T::event('invoice.paid', $first));
        $id = $first['subscription']['id'];
        $sub = MeprSubscription::get_one_by_subscr_id($id);
        $before = T::transactions((int) $sub->user_id);
        $mail = count(T::$mail);
        [$status, $body] = cancellation_test_response($case, $id);
        T::route('/api/v1/subscriptions/'.$id.'/cancel', fn () => [$status, $body]);
        $error = null;
        try {
            Plandalf_Mepr_Plugin::gateway()->process_cancel_subscription($sub->id);
        } catch (MeprGatewayException $e) {
            $error = $e;
        }
        T::true($error instanceof MeprGatewayException, 'unconfirmed cancellation must be reported');
        T::same(MeprSubscription::$active_str, (new MeprSubscription($sub->id))->status);
        T::same(serialize($before), serialize(T::transactions((int) $sub->user_id)), 'paid period unchanged');
        T::same($mail, count(T::$mail), 'no cancellation email');
    });

    T::add('cancellation evidence: daily check leaves '.$case.' unresolved', function () use ($case) {
        T::connect();
        $first = T::invoice(T::membership('Cancellation Gold', 29), ['subscription' => ['current_period_end' => time() + DAY_IN_SECONDS]]);
        T::deliver(T::event('invoice.paid', $first));
        $id = $first['subscription']['id'];
        $sub = MeprSubscription::get_one_by_subscr_id($id);
        $before = T::transactions((int) $sub->user_id);
        $mail = count(T::$mail);
        [$status, $body] = cancellation_test_response($case, $id);
        T::route('/api/v1/subscriptions/'.$id, fn () => [$status, $body]);
        $result = Plandalf_Mepr_Reconcile::run();
        T::true($result['unresolved'] >= 1, 'missing evidence remains unresolved');
        T::same(MeprSubscription::$active_str, (new MeprSubscription($sub->id))->status);
        T::same(serialize($before), serialize(T::transactions((int) $sub->user_id)), 'paid period unchanged');
        T::same($mail, count(T::$mail), 'no cancellation email');
    });
}

foreach (['scheduled', 'canceled', 'incomplete_expired'] as $case) {
    T::add('cancellation evidence: accepts matching '.$case.' without shortening paid access', function () use ($case) {
        T::connect();
        $first = T::invoice(T::membership('Cancellation Gold', 29), ['subscription' => ['current_period_end' => time() + DAY_IN_SECONDS]]);
        T::deliver(T::event('invoice.paid', $first));
        $id = $first['subscription']['id'];
        $sub = MeprSubscription::get_one_by_subscr_id($id);
        $before = T::transactions((int) $sub->user_id);
        $body = ['object' => 'subscription', 'id' => $id, 'status' => $case === 'scheduled' ? 'active' : $case, 'cancel_at_period_end' => $case === 'scheduled'];
        T::route('/api/v1/subscriptions/'.$id, fn () => [200, $body]);
        $result = Plandalf_Mepr_Reconcile::run();
        T::true($result['cancelled'] >= 1);
        T::same(MeprSubscription::$cancelled_str, (new MeprSubscription($sub->id))->status);
        T::same(serialize($before), serialize(T::transactions((int) $sub->user_id)), 'daily check preserves paid period');
        $sub->status = MeprSubscription::$active_str;
        $sub->store();
        Plandalf_Mepr_Plugin::gateway()->process_cancel_subscription($sub->id);
        T::same(MeprSubscription::$cancelled_str, (new MeprSubscription($sub->id))->status);
        T::same(serialize($before), serialize(T::transactions((int) $sub->user_id)), 'account cancellation preserves paid period');
    });
}

function cancellation_test_response(string $case, string $id): array
{
    $status = ['not-found' => 404, 'forbidden' => 403, 'unavailable' => 503][$case] ?? 200;
    if ($status !== 200) {
        return [$status, ['message' => 'Subscription could not be resolved.']];
    }
    if ($case === 'empty') {
        return [200, []];
    }

    return [200, [
        'object' => 'subscription',
        'id' => $case === 'wrong-subscription' ? 'sub_another' : $id,
        'status' => $case === 'wrong-subscription' ? 'canceled' : 'active',
        'cancel_at_period_end' => $case === 'string-flag' ? 'false' : false,
    ]];
}
