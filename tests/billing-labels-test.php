<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

function billing_label_terms(array $overrides = []): array
{
    return array_replace(['source' => 'stripe_subscription', 'base_amount' => 1900, 'currency' => 'usd', 'minor_unit' => 2,
        'interval' => 'month', 'interval_count' => 1, 'trial_start' => 1791000000, 'trial_end' => 1791604800, 'cancel_at' => null], $overrides);
}

T::add('billing labels: signed price wins over local membership terms before signup hooks', function () {
    T::connect();
    $membership = T::membership('Different local terms', 99, 'years', ['trial' => true, 'trial_days' => 30, 'trial_amount' => 5, 'limit_cycles' => true, 'limit_cycles_num' => 2]);
    $invoice = trial_access_invoice($membership, 1791604800);
    $invoice['lines'][0]['initial_billing_terms'] = billing_label_terms();
    $captured = [];
    $hook = function ($txn, $sub) use (&$captured) {
        $captured[] = MeprTransactionsHelper::format_currency($txn);
    };
    add_action('mepr_plandalf_subscription_created', $hook, 10, 2);
    try {
        T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
    } finally {
        remove_action('mepr_plandalf_subscription_created', $hook, 10);
    }
    T::same(1, count($captured));
    T::true(str_contains($captured[0], 'USD 19.00 / month'), $captured[0]);
    T::false(str_contains($captured[0], '99'), 'no local price');
    T::false(str_contains($captured[0], '30 days'), 'no local trial');
    T::true(str_contains($captured[0], gmdate('Y-m-d H:i', 1791604800)), 'signed trial end');
    $sub = MeprSubscription::get_one_by_subscr_id($invoice['subscription']['id']);
    T::same(billing_label_terms(), $sub->get_meta('_plandalf_initial_billing_terms', true));
    $invoice['lines'][0]['initial_billing_terms']['base_amount'] = 9900;
    T::deliver(T::event('invoice.paid', $invoice));
    T::same(1900, (new MeprSubscription($sub->id))->get_meta('_plandalf_initial_billing_terms', true)['base_amount'], 'duplicate does not rewrite initial terms');
});

foreach ([['base_amount' => 500, 'currency' => 'jpy', 'minor_unit' => 0], ['base_amount' => 19001, 'currency' => 'kwd', 'minor_unit' => 3], ['base_amount' => 0, 'interval_count' => 3]] as $case => $terms) {
    T::add('billing labels: currencies, zero and interval count '.$case, function () use ($terms) {
        T::connect();
        $invoice = T::invoice(T::membership('Currency test', 99));
        $invoice['lines'][0]['initial_billing_terms'] = billing_label_terms($terms);
        T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
        $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($invoice['number']);
        $label = MeprTransactionsHelper::format_currency($txn);
        $expected = strtoupper($terms['currency'] ?? 'usd').' '.number_format_i18n($terms['base_amount'] / (10 ** ($terms['minor_unit'] ?? 2)), $terms['minor_unit'] ?? 2);
        T::true(str_contains($label, $expected), $label);
        if (isset($terms['interval_count'])) {
            T::true(str_contains($label, '/ 3 months'), $label);
        }
    });
}

T::add('billing labels: older subscriptions do not advertise inherited membership prices', function () {
    T::connect();
    $invoice = T::invoice(T::membership('Legacy', 99));
    T::deliver(T::event('invoice.paid', $invoice));
    $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($invoice['number']);
    T::same('Billing is managed in Plandalf. Check your Plandalf invoice for terms.', MeprTransactionsHelper::format_currency($txn));
    $membership = new MeprProduct($txn->product_id);
    $native = MeprAppHelper::format_price_string($membership, 99);
    T::true(str_contains($native, '99'), 'native membership formatting stays intact');
});

foreach ([['currency' => []], ['base_amount' => -1], ['minor_unit' => 99], ['interval' => 'century'], ['interval_count' => 0]] as $case => $invalid) {
    T::add('billing labels: malformed snapshot uses neutral guidance '.$case, function () use ($invalid) {
        T::connect();
        $invoice = T::invoice(T::membership('Malformed', 99));
        $invoice['lines'][0]['initial_billing_terms'] = billing_label_terms($invalid);
        T::same('applied', T::deliver(T::event('invoice.paid', $invoice))->get_data()['status']);
        $txn = Plandalf_Mepr_Fulfillment::transaction_for_invoice($invoice['number']);
        T::same('Billing is managed in Plandalf. Check your Plandalf invoice for terms.', MeprTransactionsHelper::format_currency($txn));
    });
}
