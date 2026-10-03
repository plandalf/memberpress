<?php

defined('ABSPATH') || exit;

/**
 * Daily safety net. For every active Plandalf subscription whose access ends
 * within 48 hours, ask Plandalf for its state and apply anything the site
 * missed (a blocked or late event): extend on renewal, cancel on cancel.
 */
class Plandalf_Mepr_Reconcile
{
    public const HOOK = 'plandalf_mepr_reconcile';

    public const WINDOW_SECONDS = 2 * DAY_IN_SECONDS;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** @return array{checked: int, renewed: int, cancelled: int, deferred: int, unresolved: int} */
    public static function run(): array
    {
        $totals = ['checked' => 0, 'renewed' => 0, 'cancelled' => 0, 'deferred' => 0, 'unresolved' => 0];
        $gateway = Plandalf_Mepr_Plugin::gateway();
        if (! $gateway || ! Plandalf_Mepr_Settings::is_connected()) {
            return $totals;
        }

        $api = Plandalf_Mepr_Api::from_settings();

        $due = self::due_subscriptions((string) $gateway->id);
        foreach ($due as $index => $sub) {
            $result = Plandalf_Mepr_Fulfillment::with_lock(static fn () => self::check_subscription((int) $sub->id, $api, $gateway));
            if (is_wp_error($result)) {
                $totals['deferred'] = count($due) - $index;
                $next = wp_next_scheduled(self::HOOK);
                if (! $next || $next > time() + 5 * MINUTE_IN_SECONDS) {
                    wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
                }
                break;
            }

            $totals['checked']++;
            if ($result !== 'checked') {
                $totals[$result]++;
            }
        }

        Plandalf_Mepr_Event_Log::prune();

        return $totals;
    }

    private static function check_subscription(int $id, Plandalf_Mepr_Api $api, MeprPlandalfGateway $gateway): string
    {
        // Reload under the lock: a webhook may have changed this subscription
        // after the initial due-list query. Fetch remote state while locked too.
        $sub = new MeprSubscription($id);
        if (! $sub->id || $sub->status !== MeprSubscription::$active_str || (string) $sub->gateway !== (string) $gateway->id) {
            return 'checked';
        }

        $subscription_id = Plandalf_Mepr_Fulfillment::plandalf_subscription_id((string) $sub->subscr_id);
        $remote = $api->subscription($subscription_id, true);
        if (is_wp_error($remote)) {
            return 'unresolved';
        }
        if (($remote['id'] ?? '') !== $subscription_id
            || ! is_string($remote['status'] ?? null) || $remote['status'] === ''
            || (array_key_exists('cancel_at_period_end', $remote) && ! is_bool($remote['cancel_at_period_end']))) {
            return 'unresolved';
        }

        $status = (string) ($remote['status'] ?? '');
        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true) || ($remote['cancel_at_period_end'] ?? false) === true) {
            Plandalf_Mepr_Fulfillment::mark_cancelled($sub);

            return 'cancelled';
        }

        if ($status !== 'active') {
            return 'checked';
        }
        if (($remote['recovery']['status'] ?? '') !== 'ready'
            || ! is_array($remote['recovery']['invoice'] ?? null)) {
            return 'unresolved';
        }

        try {
            return Plandalf_Mepr_Fulfillment::recover_paid_invoice($gateway, $sub, $remote['recovery']['invoice']);
        } catch (Throwable) {
            // Leave this subscription due for a later check; never fabricate a payment.
            return 'unresolved';
        }
    }

    /** @return array<int, MeprSubscription> */
    private static function due_subscriptions(string $gateway_id): array
    {
        global $wpdb;
        $mepr_db = new MeprDb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT sub.id FROM {$mepr_db->subscriptions} sub
             WHERE sub.gateway = %s AND sub.status = %s
               AND (SELECT MAX(txn.expires_at) FROM {$mepr_db->transactions} txn
                    WHERE txn.subscription_id = sub.id AND txn.status IN (%s, %s)) <= %s",
            $gateway_id,
            MeprSubscription::$active_str,
            MeprTransaction::$complete_str,
            MeprTransaction::$confirmed_str,
            gmdate('Y-m-d H:i:s', time() + self::WINDOW_SECONDS)
        ));

        return array_map(static fn ($id) => new MeprSubscription((int) $id), $ids);
    }
}
