<?php

defined('ABSPATH') || exit;

/**
 * Applies verified Plandalf events to MemberPress, using MemberPress's own
 * gateway helpers (record_create_sub, record_sub_payment,
 * record_one_time_payment) so upgrades, grace periods, welcome emails and
 * receipts behave exactly as they do for MemberPress's built-in gateways.
 *
 * Numbering:
 *   transaction trans_num = Plandalf invoice number (":<membership id>" is
 *                           appended when one invoice grants several memberships)
 *   subscription subscr_id = Plandalf subscription id (same suffix rule)
 */
class Plandalf_Mepr_Fulfillment
{
    private const SEPARATOR = ':';

    private static bool $processing = false;

    /** Serialize plugin fulfilment on this WordPress site's database connection. */
    public static function with_lock(callable $callback): mixed
    {
        global $wpdb;
        if (self::$processing) {
            return new WP_Error('plandalf_busy', 'Membership processing is busy.', ['status' => 503]);
        }

        $name = 'plandalf_mepr_'.substr(hash('sha256', DB_NAME.'|'.Plandalf_Mepr_Event_Log::table()), 0, 48);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $acquired !== '1') {
            return new WP_Error('plandalf_busy', 'Membership processing is unavailable. Retry this delivery.', ['status' => 503]);
        }

        self::$processing = true;
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            self::$processing = false;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string} [log status, human message]
     */
    public static function handle(array $event): array
    {
        $object = (array) ($event['data']['object'] ?? []);

        return match ((string) $event['type']) {
            'invoice.paid' => self::invoice_paid($event, $object),
            'invoice.refunded' => self::invoice_refunded($object),
            'subscription.updated', 'subscription.canceled' => self::subscription_changed($object),
            'invoice.payment_failed' => [Plandalf_Mepr_Event_Log::IGNORED, __('Payment failed. Plandalf retries it; access continues until the paid period ends.', 'plandalf-memberpress')],
            default => [Plandalf_Mepr_Event_Log::IGNORED, __('Not an event this plugin acts on.', 'plandalf-memberpress')],
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $invoice
     * @return array{0: string, 1: string}
     */
    private static function invoice_paid(array $event, array $invoice): array
    {
        $lines = self::membership_lines($invoice);
        if (! $lines) {
            return [Plandalf_Mepr_Event_Log::IGNORED, __('Nothing on this invoice is linked to a membership on this site.', 'plandalf-memberpress')];
        }

        $gateway = Plandalf_Mepr_Plugin::gateway();
        if (! $gateway) {
            throw new RuntimeException('The Plandalf payment method is missing from MemberPress settings.');
        }

        $refunds = Plandalf_Mepr_Event_Log::full_refunds_for($invoice);
        if ($refunds) {
            return self::reconcile_refund_before_payment($gateway, $invoice, $lines, $refunds);
        }

        $subscription = is_array($invoice['subscription'] ?? null) ? $invoice['subscription'] : null;
        $is_renewal = ($invoice['billing_reason'] ?? '') === 'subscription_cycle';
        $trial_end = null;
        if (! $is_renewal && ($subscription['status'] ?? '') === 'trialing') {
            $trial_end = $subscription['current_period_end'] ?? null;
            if (! is_int($trial_end) || $trial_end <= 0 || empty($subscription['id'])
                || ($invoice['status'] ?? '') !== 'paid' || ($invoice['total'] ?? null) !== 0) {
                throw new RuntimeException('The trial has no confirmed zero invoice and subscription period.');
            }
            foreach ($lines as $line) {
                if ($line['recurring'] && $line['amount'] !== 0) {
                    throw new RuntimeException('The trial membership has a nonzero initial amount.');
                }
            }
        }
        $user = self::resolve_user($invoice, $event, $lines[0]['membership']);
        $number = (string) ($invoice['number'] ?? $invoice['id']);
        $granted = [];

        foreach ($lines as $line) {
            $membership = $line['membership'];
            $suffix = count($lines) > 1 ? self::SEPARATOR.$membership->ID : '';
            $trans_num = $number.$suffix;
            $amount = ((int) ($line['amount'] ?? 0)) / 100;

            if ($line['recurring'] && $subscription) {
                $subscr_id = $subscription['id'].self::subscription_suffix($lines, $membership);
                $sub = MeprSubscription::get_one_by_subscr_id($subscr_id);

                if (! $sub instanceof MeprSubscription) {
                    if ($is_renewal) {
                        continue;
                    }
                    $sub = self::create_subscription($gateway, $user, $membership, $subscr_id, $trial_end, $trans_num, $line['initial_billing_terms'] ?? null);
                }

                if ($trial_end !== null) {
                    if ((int) $sub->user_id !== (int) $user->ID || (int) $sub->product_id !== (int) $membership->ID
                        || (string) $sub->gateway !== (string) $gateway->id) {
                        throw new RuntimeException('The trial subscription does not match this membership and buyer.');
                    }
                    $gateway->record_trial_subscription($sub, $trans_num, $trial_end);
                } elseif ($amount > 0 || ($is_renewal && $amount == 0 && ($invoice['status'] ?? '') === 'paid')) {
                    $period_end = (int) ($subscription['current_period_end'] ?? 0);
                    $recovered = $is_renewal && self::identify_recovered_payment($gateway, $sub, $period_end, $trans_num);
                    if (! $recovered) {
                        self::extend_to($gateway, $sub, $period_end, $trans_num, $amount);
                    }
                }
            } elseif (! MeprTransaction::txn_exists($trans_num)) {
                $txn = new MeprTransaction;
                $txn->user_id = $user->ID;
                $txn->product_id = $membership->ID;
                $txn->gateway = $gateway->id;
                $txn->txn_type = MeprTransaction::$payment_str;
                $txn->status = MeprTransaction::$pending_str;
                $txn->set_gross($amount);
                $gateway->record_one_time_payment($txn, $trans_num);
            }

            $txn = self::transaction_for_invoice($trans_num);
            if ($txn) {
                /**
                 * Fires after a membership is granted or extended from a Plandalf event.
                 *
                 * @param  MeprTransaction  $txn
                 * @param  array<string, mixed>  $event
                 */
                do_action('plandalf_mepr_granted', $txn, $event);
            }
            $granted[] = get_the_title($membership->ID);
        }

        if (! $granted) {
            return [Plandalf_Mepr_Event_Log::IGNORED, __('Renewal for a subscription this site does not know.', 'plandalf-memberpress')];
        }

        return [Plandalf_Mepr_Event_Log::APPLIED, sprintf(
            /* translators: 1: membership names, 2: user email */
            __('%1$s for %2$s', 'plandalf-memberpress'),
            implode(', ', $granted),
            $user->user_email
        )];
    }

    /**
     * A verified full refund arrived first. Identify any daily-check recovery,
     * refund existing records, and never create a member or grant new access.
     */
    private static function reconcile_refund_before_payment(MeprPlandalfGateway $gateway, array $invoice, array $lines, array $refunds): array
    {
        $number = (string) ($invoice['number'] ?? $invoice['id']);
        $subscription = $invoice['subscription'] ?? null;
        $identified_ids = [];
        foreach ($refunds as $refund) {
            $identified_ids = array_merge($identified_ids, (array) ($refund['_plandalf_transactions'] ?? []));
        }
        if (($invoice['billing_reason'] ?? '') === 'subscription_cycle' && is_array($subscription)) {
            if ((int) ($subscription['current_period_end'] ?? 0) <= 0) {
                throw new RuntimeException('The renewal has no period end to identify a recovered payment.');
            }
            foreach ($lines as $line) {
                if (! $line['recurring']) {
                    continue;
                }
                $membership = $line['membership'];
                $sub = MeprSubscription::get_one_by_subscr_id($subscription['id'].self::subscription_suffix($lines, $membership));
                if ($sub instanceof MeprSubscription) {
                    $trans_num = $number.(count($lines) > 1 ? self::SEPARATOR.$membership->ID : '');
                    if (self::identify_recovered_payment(
                        $gateway, $sub, (int) ($subscription['current_period_end'] ?? 0),
                        $trans_num
                    )) {
                        $identified_ids[] = self::transaction_for_invoice($trans_num)->id;
                    }
                }
            }
        }

        $transactions = self::transactions_for_invoice($number);
        if ($transactions && ! Plandalf_Mepr_Event_Log::has_paid_invoice($invoice)) {
            foreach ($transactions as $txn) {
                if (! in_array((int) $txn->id, array_map('intval', $identified_ids), true)) {
                    throw new RuntimeException('The invoice number matches a transaction whose invoice identity is unverified.');
                }
            }
        }
        if ($transactions) {
            self::invoice_refunded(array_merge($invoice, ['refunded' => true]));
        }
        foreach ($refunds as $refund) {
            Plandalf_Mepr_Event_Log::record(
                $refund, Plandalf_Mepr_Event_Log::APPLIED,
                __('Full refund reconciled with the paid event. No new access granted.', 'plandalf-memberpress'),
                array_map(static fn ($txn) => (int) $txn->id, $transactions)
            );
        }

        return [Plandalf_Mepr_Event_Log::IGNORED, __('Invoice already fully refunded. No new access granted.', 'plandalf-memberpress')];
    }

    /**
     * Replace only the exact daily-check reference for this subscription and
     * period. Do not infer invoice identity from the latest expiry: a newer
     * renewal may already exist when a delayed event arrives.
     */
    private static function identify_recovered_payment(MeprPlandalfGateway $gateway, MeprSubscription $sub, int $period_end, string $trans_num): bool
    {
        global $wpdb;
        if ($period_end <= 0 || MeprTransaction::txn_exists($trans_num)) {
            return false;
        }

        $mepr_db = new MeprDb;
        $synthetic = 'renewal:'.$sub->subscr_id.':'.$period_end;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$mepr_db->transactions}
             WHERE trans_num = %s AND subscription_id = %d AND user_id = %d
               AND product_id = %d AND gateway = %s AND txn_type = %s
               AND status IN (%s, %s)",
            $synthetic, $sub->id, $sub->user_id, $sub->product_id, $gateway->id,
            MeprTransaction::$payment_str, MeprTransaction::$complete_str, MeprTransaction::$refunded_str
        ));
        if (count($ids) !== 1) {
            return false;
        }

        // Updating the reference must not grant another period or resend a receipt.
        // Preserve a refund already applied by an operator.
        $updated = $wpdb->update($mepr_db->transactions, ['trans_num' => $trans_num], [
            'id' => (int) $ids[0], 'trans_num' => $synthetic,
        ], ['%s'], ['%d', '%s']);
        if ($updated === false) {
            throw new RuntimeException('Could not identify the recovered renewal payment.');
        }

        return $updated === 1;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{0: string, 1: string}
     */
    private static function invoice_refunded(array $invoice): array
    {
        if (($invoice['refunded'] ?? false) !== true) {
            return [Plandalf_Mepr_Event_Log::IGNORED, __('Partial refund — membership left active.', 'plandalf-memberpress')];
        }

        $transactions = self::transactions_for_invoice((string) ($invoice['number'] ?? $invoice['id'] ?? ''));
        if (! $transactions && ! empty($invoice['id']) && self::membership_lines($invoice)) {
            return [Plandalf_Mepr_Event_Log::WAITING, __('Full refund saved. Waiting for the matching paid event; no access changed.', 'plandalf-memberpress')];
        }

        $refunded = 0;
        foreach ($transactions as $txn) {
            if ($txn->status === MeprTransaction::$refunded_str) {
                continue;
            }
            $txn->status = MeprTransaction::$refunded_str;
            $txn->store();
            MeprUtils::send_refunded_txn_notices($txn);
            $refunded++;
        }

        return $refunded
            ? [Plandalf_Mepr_Event_Log::APPLIED, sprintf(_n('%d transaction refunded.', '%d transactions refunded.', $refunded, 'plandalf-memberpress'), $refunded)]
            : ($transactions
                ? [Plandalf_Mepr_Event_Log::APPLIED, __('Matching transactions are already refunded.', 'plandalf-memberpress')]
                : [Plandalf_Mepr_Event_Log::IGNORED, __('No matching transaction to refund.', 'plandalf-memberpress')]);
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array{0: string, 1: string}
     */
    private static function subscription_changed(array $subscription): array
    {
        $subs = self::subscriptions_for((string) ($subscription['id'] ?? ''));
        if (! $subs) {
            return [Plandalf_Mepr_Event_Log::IGNORED, __('Subscription is not on this site.', 'plandalf-memberpress')];
        }

        $status = (string) ($subscription['status'] ?? '');
        $ending = in_array($status, ['canceled', 'incomplete_expired'], true) || ! empty($subscription['cancel_at_period_end']);
        $changed = 0;

        foreach ($subs as $sub) {
            if ($ending && $sub->status !== MeprSubscription::$cancelled_str) {
                self::mark_cancelled($sub);
                $changed++;
            } elseif (! $ending && in_array($status, ['active', 'trialing'], true) && $sub->status === MeprSubscription::$cancelled_str) {
                $sub->status = MeprSubscription::$active_str;
                $sub->store();
                $changed++;
            }
        }

        return $changed
            ? [Plandalf_Mepr_Event_Log::APPLIED, $ending ? __('Subscription cancelled; access runs to the end of the paid period.', 'plandalf-memberpress') : __('Subscription re-activated.', 'plandalf-memberpress')]
            : [Plandalf_Mepr_Event_Log::IGNORED, __('Already up to date.', 'plandalf-memberpress')];
    }

    /**
     * Record a paid period on a subscription, unless access already runs to
     * that date (the daily check or an earlier event got there first) or the
     * payment was already recorded. Never moves an expiry backwards.
     */
    /** Apply a provider-verified recovery while the caller holds the fulfilment lock. */
    public static function recover_paid_invoice(MeprPlandalfGateway $gateway, MeprSubscription $sub, array $invoice): string
    {
        $subscription_id = self::plandalf_subscription_id((string) $sub->subscr_id);
        $user = get_userdata((int) $sub->user_id);
        $number = (string) ($invoice['number'] ?? '');
        if (! self::$processing || ! $user || $number === '' || empty($invoice['id'])
            || ($invoice['object'] ?? '') !== 'invoice' || ($invoice['status'] ?? '') !== 'paid'
            || ($invoice['billing_reason'] ?? '') !== 'subscription_cycle'
            || ($invoice['livemode'] ?? null) !== (Plandalf_Mepr_Settings::mode() === 'live')
            || ($invoice['subscription']['id'] ?? '') !== $subscription_id
            || ! is_int($invoice['subscription']['current_period_end'] ?? null)
            || $invoice['subscription']['current_period_end'] <= 0
            || ! is_bool($invoice['refunded'] ?? null)
            || strtolower((string) ($invoice['currency'] ?? '')) !== strtolower((string) MeprOptions::fetch()->currency_code)
            || strcasecmp((string) ($invoice['customer']['email'] ?? ''), $user->user_email) !== 0
            || (! empty($invoice['customer']['external_id']) && $invoice['customer']['external_id'] !== 'wp:'.$user->ID)) {
            return 'unresolved';
        }

        foreach ((array) ($invoice['lines'] ?? []) as $line) {
            if (! is_int($line['amount'] ?? null) || $line['amount'] < 0) {
                return 'unresolved';
            }
        }
        $lines = self::membership_lines($invoice);
        $seen = [];
        foreach ($lines as $line) {
            $membership = $line['membership'];
            $target = MeprSubscription::get_one_by_subscr_id($subscription_id.self::subscription_suffix($lines, $membership));
            if (! $line['recurring'] || isset($seen[$membership->ID]) || $line['amount'] < 0
                || ! $target instanceof MeprSubscription || (int) $target->user_id !== (int) $user->ID
                || (int) $target->product_id !== (int) $membership->ID || (string) $target->gateway !== (string) $gateway->id) {
                return 'unresolved';
            }
            $seen[$membership->ID] = true;
        }
        if (! isset($seen[$sub->product_id])) {
            return 'unresolved';
        }

        $scope = wp_json_encode([Plandalf_Mepr_Settings::api_base(), Plandalf_Mepr_Settings::get('organization')['id'] ?? null, Plandalf_Mepr_Settings::mode()]);
        $event = ['id' => 'recover_'.substr(hash('sha256', $scope.'|'.$invoice['id'].'|paid'), 0, 48),
            'type' => 'invoice.paid', 'created' => time(), 'livemode' => $invoice['livemode'], 'data' => ['object' => $invoice]];
        $before = self::transactions_for_invoice($number);
        foreach ($before as $txn) {
            $target = new MeprSubscription((int) $txn->subscription_id);
            if ((int) $txn->user_id !== (int) $user->ID || ! isset($seen[$txn->product_id])
                || (string) $txn->gateway !== (string) $gateway->id
                || self::plandalf_subscription_id((string) $target->subscr_id) !== $subscription_id) {
                return 'unresolved';
            }
        }
        if ($invoice['refunded']) {
            $refund = array_replace($event, ['id' => 'recover_'.substr(hash('sha256', $scope.'|'.$invoice['id'].'|refund'), 0, 48), 'type' => 'invoice.refunded']);
            [$status, $message] = self::handle($refund);
            $known = [];
            foreach (Plandalf_Mepr_Event_Log::full_refunds_for($invoice) as $saved) {
                $known = array_merge($known, (array) ($saved['_plandalf_transactions'] ?? []));
            }
            Plandalf_Mepr_Event_Log::record($refund, $status, $message, $known);
        }
        [$status, $message] = self::handle($event);
        Plandalf_Mepr_Event_Log::record($event, $status, $message);

        return ! $invoice['refunded'] && count(self::transactions_for_invoice($number)) > count($before) ? 'renewed' : 'checked';
    }

    public static function extend_to(MeprPlandalfGateway $gateway, MeprSubscription $sub, int $period_end, string $trans_num, ?float $amount = null): bool
    {
        if (MeprTransaction::txn_exists($trans_num)) {
            return false;
        }

        $latest = $sub->latest_txn();
        $current_end = $latest instanceof MeprTransaction && ! empty($latest->expires_at) && $latest->expires_at !== MeprUtils::db_lifetime()
            ? strtotime($latest->expires_at.' UTC')
            : 0;

        if ($period_end > 0 && $current_end >= strtotime(gmdate('Y-m-d 23:59:59', $period_end).' UTC')
            && $latest->txn_type === MeprTransaction::$payment_str) {
            return false;
        }

        $gateway->record_sub_payment(
            $sub,
            $amount ?? (float) $sub->total,
            $trans_num,
            null,
            self::mysql_date($period_end ?: null)
        );

        return true;
    }

    public static function mark_cancelled(MeprSubscription $sub): void
    {
        $sub->status = MeprSubscription::$cancelled_str;
        $sub->store();
        MeprUtils::send_cancelled_sub_notices($sub);
    }

    /** First transaction recorded for a Plandalf invoice number, if any. */
    public static function transaction_for_invoice(string $number): ?MeprTransaction
    {
        return self::transactions_for_invoice($number)[0] ?? null;
    }

    /** @return array<int, MeprTransaction> */
    public static function transactions_for_invoice(string $number): array
    {
        global $wpdb;
        if ($number === '') {
            return [];
        }

        $mepr_db = new MeprDb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$mepr_db->transactions} WHERE trans_num = %s OR trans_num LIKE %s ORDER BY id",
            $number,
            $wpdb->esc_like($number.self::SEPARATOR).'%'
        ));

        return array_map(static fn ($id) => new MeprTransaction((int) $id), $ids);
    }

    /** @return array<int, MeprSubscription> */
    public static function subscriptions_for(string $subscription_id): array
    {
        global $wpdb;
        if ($subscription_id === '') {
            return [];
        }

        $mepr_db = new MeprDb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$mepr_db->subscriptions} WHERE subscr_id = %s OR subscr_id LIKE %s ORDER BY id",
            $subscription_id,
            $wpdb->esc_like($subscription_id.self::SEPARATOR).'%'
        ));

        return array_map(static fn ($id) => new MeprSubscription((int) $id), $ids);
    }

    /** The Plandalf subscription id behind a MemberPress subscr_id. */
    public static function plandalf_subscription_id(string $subscr_id): string
    {
        return explode(self::SEPARATOR, $subscr_id, 2)[0];
    }

    private static function create_subscription(MeprPlandalfGateway $gateway, WP_User $user, MeprProduct $membership, string $subscr_id, ?int $trial_end = null, ?string $trial_number = null, ?array $billing_terms = null): MeprSubscription
    {
        $sub = new MeprSubscription;
        $sub->user_id = $user->ID;
        $sub->product_id = $membership->ID;
        $sub->gateway = $gateway->id;
        $sub->subscr_id = $subscr_id;
        $sub->load_product_vars($membership, null, true);
        $sub->status = MeprSubscription::$pending_str;
        $sub->store();
        if ($billing_terms !== null) {
            $sub->update_meta('_plandalf_initial_billing_terms', $billing_terms);
        }

        if ($trial_end !== null && $trial_number !== null) {
            $gateway->record_trial_subscription($sub, $trial_number, $trial_end);
        } else {
            $gateway->record_create_sub($sub);
        }

        return new MeprSubscription($sub->id);
    }

    /**
     * The memberships an invoice grants on this site, from each line's links.
     * A line linked to several memberships splits its amount between them.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<int, array{membership: MeprProduct, amount: int, recurring: bool}>
     */
    private static function membership_lines(array $invoice): array
    {
        $lines = [];
        foreach ((array) ($invoice['lines'] ?? []) as $line) {
            $membership_ids = Plandalf_Mepr_Links::memberships_granted_by((array) $line);
            $count = count($membership_ids);

            foreach ($membership_ids as $membership_id) {
                $lines[] = [
                    'membership' => new MeprProduct($membership_id),
                    'amount' => intdiv((int) ($line['amount'] ?? 0), max(1, $count)),
                    'recurring' => ! empty($line['price']['recurring']),
                    'initial_billing_terms' => is_array($line['initial_billing_terms'] ?? null) ? $line['initial_billing_terms'] : null,
                ];
            }
        }

        return $lines;
    }

    /** @param array<int, array{membership: MeprProduct, recurring: bool}> $lines */
    private static function subscription_suffix(array $lines, MeprProduct $membership): string
    {
        $recurring = array_filter($lines, static fn ($line) => $line['recurring']);

        return count($recurring) > 1 ? self::SEPARATOR.$membership->ID : '';
    }

    /**
     * The member for a purchase: the WordPress user the identity token named,
     * else the account with the checkout email, else a new account.
     *
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $event
     */
    private static function resolve_user(array $invoice, array $event, MeprProduct $membership): WP_User
    {
        $customer = (array) ($invoice['customer'] ?? []);
        $user = null;

        if (preg_match('/^wp:(\d+)$/', (string) ($customer['external_id'] ?? ''), $matches)) {
            $user = get_userdata((int) $matches[1]) ?: null;
        }

        $email = sanitize_email((string) ($customer['email'] ?? ''));
        if (! $user && $email !== '') {
            $user = get_user_by('email', $email) ?: null;
        }

        /**
         * Pick or create the WordPress user for an event. Return a WP_User to override matching.
         *
         * @param  WP_User|null  $user
         * @param  array<string, mixed>  $event
         */
        $user = apply_filters('plandalf_mepr_resolve_user', $user, $event);
        if ($user instanceof WP_User) {
            return $user;
        }

        if ($email === '') {
            throw new RuntimeException('The purchase has no customer email, so no member can be created.');
        }

        return self::create_user($email, (string) ($customer['name'] ?? ''), (string) ($invoice['id'] ?? ''), $membership);
    }

    /**
     * New member for a guest checkout. The set-password link is made once and
     * used twice: emailed (MemberPress's own "Set Your New Password" email)
     * and held for the thank-you page, which sends the buyer straight to it.
     * Making a second link would invalidate the emailed one.
     */
    private static function create_user(string $email, string $name, string $invoice_id, MeprProduct $membership): WP_User
    {
        $login = sanitize_user(strstr($email, '@', true) ?: $email, true) ?: 'member';
        $base = $login;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base.$i;
        }

        [$first, $last] = array_pad(explode(' ', trim($name), 2), 2, '');

        $member = new MeprUser;
        $member->user_login = $login;
        $member->user_email = $email;
        $member->first_name = $first;
        $member->last_name = $last;
        $member->set_password(wp_generate_password(24));
        $member->store();

        $link = $member->reset_password_link();
        if ($link) {
            Plandalf_Mepr_Password_Setup::hold($invoice_id, (int) $member->ID, $link, $membership);
            Plandalf_Mepr_Password_Setup::email($member, $link);
        }

        return new WP_User($member->ID);
    }

    private static function mysql_date(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null;
    }
}
