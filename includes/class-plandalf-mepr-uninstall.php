<?php

defined('ABSPATH') || exit;

/**
 * What deleting the plugin removes. Only the plugin's own data goes; MemberPress
 * records (members, transactions, subscriptions) and the Plandalf payment
 * method entry in MemberPress settings stay, because they are the site's
 * billing history and a reinstall picks the payment method up again.
 */
class Plandalf_Mepr_Uninstall
{
    public const OPTIONS = ['plandalf_mepr_settings', 'plandalf_mepr_gateway_id', 'plandalf_mepr_installed_version'];

    public const POST_META = ['_plandalf_display', '_plandalf_linked_prices', '_plandalf_offer', '_plandalf_offer_name', '_plandalf_use_memberpress', '_plandalf_page_price'];

    public const USER_META = ['_plandalf_after_password'];

    public static function run(): void
    {
        self::forget_site();
        self::drop_events_table();
    }

    /** Unregisters the event endpoint and deletes options, transients, meta and the schedule. */
    public static function forget_site(): void
    {
        global $wpdb;

        $endpoint_id = (int) (Plandalf_Mepr_Settings::get('endpoint')['id'] ?? 0);
        if ($endpoint_id > 0 && Plandalf_Mepr_Settings::is_connected()) {
            Plandalf_Mepr_Api::from_settings()->revoke();
        }

        wp_clear_scheduled_hook('plandalf_mepr_reconcile');

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }

        // Through delete_transient(), not a raw DELETE, so the object cache forgets them too.
        // Short-lived (at most 30 minutes), so ones only held in a persistent cache simply expire.
        $transients = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_plandalf_mepr_').'%'
        ));
        foreach ($transients as $option_name) {
            delete_transient(substr($option_name, strlen('_transient_')));
        }

        foreach (self::POST_META as $meta_key) {
            delete_post_meta_by_key($meta_key);
        }
        foreach (self::USER_META as $meta_key) {
            delete_metadata('user', 0, $meta_key, '', true);
        }
    }

    /** Separate from forget_site(): DROP TABLE commits, so the test suite never calls it. */
    public static function drop_events_table(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.Plandalf_Mepr_Event_Log::table());
    }
}
