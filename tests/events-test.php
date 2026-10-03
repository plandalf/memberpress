<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('events: accepts a correctly signed event and records it', function () {
    T::connect();
    $event = T::event('invoice.payment_failed', ['object' => 'invoice', 'number' => 'INV-1']);
    $response = T::deliver($event);

    T::same(200, $response->get_status());
    T::same('ignored', $response->get_data()['status']);
    T::same('ignored', Plandalf_Mepr_Event_Log::find($event['id'])['status'] ?? null, 'logged');
});

T::add('events: rejects a bad signature, a wrong secret and a stale timestamp', function () {
    T::connect();
    $event = T::event('invoice.paid', ['object' => 'invoice']);

    T::same(401, T::deliver($event, 'whsec_wrong')->get_status(), 'wrong secret');
    T::same(401, T::deliver($event, null, time() - 600)->get_status(), 'stale timestamp');

    $request = new WP_REST_Request('POST', '/plandalf/v1/events');
    $request->set_body((string) wp_json_encode($event));
    T::same(401, Plandalf_Mepr_Events::receive($request)->get_status(), 'no signature');

    T::same(null, Plandalf_Mepr_Event_Log::find($event['id']), 'nothing recorded');
});

T::add('events: rejects everything while disconnected', function () {
    T::same(401, T::deliver(T::event('invoice.paid', []))->get_status());
});

T::add('events: acknowledges a repeat delivery without applying it again', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $event = T::event('invoice.paid', T::invoice($membership));

    T::same('applied', T::deliver($event)->get_data()['status']);
    T::true(T::deliver($event)->get_data()['duplicate'] ?? false, 'second delivery');

    $user = get_user_by('email', $event['data']['object']['customer']['email']);
    T::same(1, count(array_filter(T::transactions($user->ID), fn ($t) => $t->txn_type === 'payment')), 'one payment');
});

T::add('events: verify() matches the documented hmac of "<t>.<body>"', function () {
    $header = 't=1000,v1='.hash_hmac('sha256', '1000.{"a":1}', 'secret');

    T::true(Plandalf_Mepr_Events::verify('secret', $header, '{"a":1}', 1000));
    T::false(Plandalf_Mepr_Events::verify('secret', $header, '{"a":2}', 1000), 'tampered');
    T::false(Plandalf_Mepr_Events::verify('secret', 't=abc,v1=00', '{"a":1}', 1000), 'junk');
});

T::add('events: refund markers survive pruning without retaining buyer details', function () {
    global $wpdb;
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    $refund = T::event('invoice.refunded', $invoice + ['refunded' => true]);
    T::same('waiting', T::deliver($refund)->get_data()['status']);
    $stored = json_decode(Plandalf_Mepr_Event_Log::find($refund['id'])['payload'], true);
    T::false(isset($stored['data']['object']['customer']), 'buyer details omitted');
    T::false(isset($stored['data']['object']['lines']), 'line details omitted');
    T::same($invoice['id'], $stored['data']['object']['id']);
    $old = gmdate('Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS);
    $wpdb->update(Plandalf_Mepr_Event_Log::table(), ['received_at' => $old], ['event_id' => $refund['id']]);
    Plandalf_Mepr_Event_Log::prune();
    T::true(Plandalf_Mepr_Event_Log::find($refund['id']) !== null, 'waiting marker retained');
    T::deliver(T::event('invoice.paid', $invoice));
    $wpdb->update(Plandalf_Mepr_Event_Log::table(), ['received_at' => $old], ['event_id' => $refund['id']]);
    Plandalf_Mepr_Event_Log::prune();
    T::same('applied', Plandalf_Mepr_Event_Log::find($refund['id'])['status'], 'applied refund marker retained');
    T::same('ignored', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status'], 'late payment cannot grant access after cleanup');
});

T::add('events: storage failure is not acknowledged and the same refund can retry', function () {
    global $wpdb;
    T::connect();
    $membership = T::membership('Gold', 29);
    $event = T::event('invoice.refunded', T::invoice($membership) + ['refunded' => true]);
    $table = Plandalf_Mepr_Event_Log::table();
    $fail = static function ($query) use ($table) {
        return str_starts_with($query, "REPLACE INTO `{$table}`")
            ? str_replace("`{$table}`", '`plandalf_nonexistent_test_table`', $query)
            : $query;
    };
    $prior = $wpdb->suppress_errors(true);
    add_filter('query', $fail);
    try {
        T::same(500, T::deliver($event)->get_status());
    } finally {
        remove_filter('query', $fail);
        $wpdb->suppress_errors($prior);
    }
    T::same(null, Plandalf_Mepr_Event_Log::find($event['id']));
    T::same('waiting', T::deliver($event)->get_data()['status'], 'same event can be persisted after recovery');
});

T::add('events: refund markers do not cross test and live connections', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    T::deliver(T::event('invoice.refunded', $invoice + ['refunded' => true]));
    T::same(1, count(Plandalf_Mepr_Event_Log::full_refunds_for($invoice)));
    T::connect('live');
    T::same([], Plandalf_Mepr_Event_Log::full_refunds_for($invoice));
});

T::add('events: another database connection defers delivery without a grant or acknowledgement', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    $event = T::event('invoice.paid', $invoice);
    $peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock = 'plandalf_mepr_'.substr(hash('sha256', DB_NAME.'|'.Plandalf_Mepr_Event_Log::table()), 0, 48);
    T::same('1', $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock)));
    try {
        T::same(503, T::deliver($event)->get_status());
        T::same(null, Plandalf_Mepr_Event_Log::find($event['id']), 'busy delivery must not overwrite the event log');
        T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User, 'no grant while another connection owns the lock');
        $result = Plandalf_Mepr_Reconcile::run();
        T::same(0, $result['renewed']);
    } finally {
        $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        $peer->close();
    }
    T::same('applied', T::deliver($event)->get_data()['status']);
    T::true(T::deliver($event)->get_data()['duplicate'] ?? false);
    T::same(1, count(Plandalf_Mepr_Fulfillment::transactions_for_invoice($invoice['number'])));
});

T::add('events: the lock covers hooks and event persistence and is released after failure', function () {
    T::connect();
    $event = T::event('invoice.payment_failed', ['object' => 'invoice', 'id' => 'in_lock_test', 'number' => 'INV-LOCK-TEST']);
    $peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock = 'plandalf_mepr_'.substr(hash('sha256', DB_NAME.'|'.Plandalf_Mepr_Event_Log::table()), 0, 48);
    $observed = [];
    $write_observed = [];
    $table = Plandalf_Mepr_Event_Log::table();
    $observe_write = static function ($query) use ($peer, $lock, $table, &$write_observed) {
        if (str_starts_with($query, "REPLACE INTO `{$table}`")) {
            $write_observed[] = $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        }

        return $query;
    };
    $hook = static function () use ($peer, $lock, $event, &$observed) {
        $observed['lock'] = $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        $observed['nested_status'] = T::deliver($event)->get_status();
        throw new RuntimeException('Synthetic processing failure');
    };
    add_action('plandalf_mepr_event', $hook);
    add_filter('query', $observe_write);
    try {
        T::same(500, T::deliver($event)->get_status());
        T::same('0', $observed['lock'], 'other connection cannot enter during processing');
        T::same(503, $observed['nested_status'], 'nested delivery cannot bypass the lock');
        T::same(['0'], $write_observed, 'lock held until the event result is stored');
        T::same('failed', Plandalf_Mepr_Event_Log::find($event['id'])['status']);
        T::same('1', $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock)), 'lock released after failure');
    } finally {
        remove_action('plandalf_mepr_event', $hook);
        remove_filter('query', $observe_write);
        $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        $peer->close();
    }
    T::same('ignored', T::deliver($event)->get_data()['status'], 'failed event can retry');
});

T::add('events: daily reconciliation shares the lock and schedules a deferred retry', function () {
    wp_clear_scheduled_hook(Plandalf_Mepr_Reconcile::HOOK);
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    $invoice['subscription']['current_period_end'] = time() + DAY_IN_SECONDS;
    T::deliver(T::event('invoice.paid', $invoice));
    $renewal = array_replace($invoice, [
        'id' => 'in_locked_renewal', 'number' => 'INV-LOCKED-RENEWAL', 'billing_reason' => 'subscription_cycle', 'livemode' => false, 'refunded' => false,
        'subscription' => array_replace($invoice['subscription'], ['current_period_end' => time() + 30 * DAY_IN_SECONDS]),
    ]);
    $peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock = 'plandalf_mepr_'.substr(hash('sha256', DB_NAME.'|'.Plandalf_Mepr_Event_Log::table()), 0, 48);
    $observed = [];
    T::route('/api/v1/subscriptions/'.$invoice['subscription']['id'], static function () use ($peer, $lock, $renewal, &$observed) {
        $observed['lock'] = $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        $observed['delivery'] = T::deliver(T::event('invoice.paid', $renewal))->get_status();

        return [200, $renewal['subscription'] + ['recovery' => ['status' => 'ready', 'invoice' => $renewal]]];
    });
    T::same('1', $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock)));
    try {
        $deferred = Plandalf_Mepr_Reconcile::run();
        T::true($deferred['deferred'] >= 1);
        T::same(0, $deferred['checked']);
        T::same([], $observed, 'provider not called while busy');
        $retry = wp_next_scheduled(Plandalf_Mepr_Reconcile::HOOK);
        T::true($retry > time() && $retry <= time() + MINUTE_IN_SECONDS, 'short retry scheduled');
        $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        T::same(1, Plandalf_Mepr_Reconcile::run()['renewed']);
        T::same('0', $observed['lock'], 'daily provider read and mutation share the lock');
        T::same(503, $observed['delivery'], 'webhook deferred during daily reconciliation');
    } finally {
        $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        $peer->close();
    }
    T::same('applied', T::deliver(T::event('invoice.paid', $renewal))->get_data()['status']);
    T::same(1, count(Plandalf_Mepr_Fulfillment::transactions_for_invoice($renewal['number'])), 'retried paid event identifies the one recovered payment');
});

T::add('events: unavailable named locks fail closed', function () {
    T::connect();
    $membership = T::membership('Gold', 29);
    $invoice = T::invoice($membership);
    $event = T::event('invoice.paid', $invoice);
    $fail = static fn ($query) => str_starts_with($query, 'SELECT GET_LOCK(') ? 'SELECT NULL' : $query;
    add_filter('query', $fail);
    try {
        T::same(503, T::deliver($event)->get_status());
        T::same(null, Plandalf_Mepr_Event_Log::find($event['id']));
        T::false(get_user_by('email', $invoice['customer']['email']) instanceof WP_User);
    } finally {
        remove_filter('query', $fail);
    }
    T::same('applied', T::deliver($event)->get_data()['status']);
});
