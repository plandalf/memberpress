<?php

defined('ABSPATH') || exit;

/**
 * Wires the plugin together and owns the record-only "Plandalf" payment
 * method entry in MemberPress settings.
 */
class Plandalf_Mepr_Plugin
{
    public const GATEWAY_OPTION = 'plandalf_mepr_gateway_id';

    /** Plugin version whose schema and schedule are installed on this site. */
    public const INSTALLED_VERSION_OPTION = 'plandalf_mepr_installed_version';

    public static function boot(): void
    {
        if (! class_exists('MeprBaseRealGateway')) {
            add_action('admin_notices', [self::class, 'memberpress_missing_notice']);

            return;
        }

        self::maybe_upgrade();

        add_filter('mepr-price-string', [self::class, 'subscription_price_label'], 99, 3);
        add_filter('mepr-gateway-paths', [self::class, 'gateway_paths']);
        add_filter('mepr_options_helper_payment_methods', [self::class, 'hide_from_checkout'], 99, 3);
        add_action('admin_init', [self::class, 'ensure_gateway']);

        Plandalf_Mepr_Events::register();
        Plandalf_Mepr_Password_Setup::register();
        Plandalf_Mepr_Links::register();
        Plandalf_Mepr_Admin::register();
        Plandalf_Mepr_Membership_Tab::register();
        Plandalf_Mepr_List_Columns::register();
        Plandalf_Mepr_Checkout::register();
        Plandalf_Mepr_Reconcile::register();
    }

    /** Use the signed purchase snapshot, never the membership's editable local price. */
    public static function subscription_price_label(string $label, $object, $show_symbol = true): string
    {
        if (! $object instanceof MeprSubscription || ! $object->payment_method() instanceof MeprPlandalfGateway) {
            return $label;
        }
        $terms = $object->get_meta('_plandalf_initial_billing_terms', true);
        if (! is_array($terms) || ($terms['source'] ?? '') !== 'stripe_subscription'
            || ! is_int($terms['base_amount'] ?? null) || $terms['base_amount'] < 0
            || ! is_int($terms['minor_unit'] ?? null) || $terms['minor_unit'] < 0 || $terms['minor_unit'] > 3
            || ! is_string($terms['currency'] ?? null)
            || ! preg_match('/^[a-z]{3}$/D', $terms['currency'] ?? '')
            || ! in_array($terms['interval'] ?? '', ['day', 'week', 'month', 'year'], true)
            || ! is_int($terms['interval_count'] ?? null) || $terms['interval_count'] < 1) {
            return __('Billing is managed in Plandalf. Check your Plandalf invoice for terms.', 'plandalf-memberpress');
        }
        $periods = [
            'day' => _n('day', 'days', $terms['interval_count'], 'plandalf-memberpress'),
            'week' => _n('week', 'weeks', $terms['interval_count'], 'plandalf-memberpress'),
            'month' => _n('month', 'months', $terms['interval_count'], 'plandalf-memberpress'),
            'year' => _n('year', 'years', $terms['interval_count'], 'plandalf-memberpress'),
        ];
        $period = $terms['interval_count'] === 1 ? $periods[$terms['interval']] : $terms['interval_count'].' '.$periods[$terms['interval']];
        $amount = strtoupper($terms['currency']).' '.number_format_i18n($terms['base_amount'] / (10 ** $terms['minor_unit']), $terms['minor_unit']);
        $label = sprintf(__('Initial Plandalf base price: %1$s / %2$s. Final charges, discounts and tax are shown on your Plandalf invoices.', 'plandalf-memberpress'), $amount, $period);
        if (is_int($terms['trial_end'] ?? null) && $terms['trial_end'] > 0) {
            $label .= ' '.sprintf(__('Initial trial end: %s UTC.', 'plandalf-memberpress'), gmdate('Y-m-d H:i', $terms['trial_end']));
        }
        if (is_int($terms['cancel_at'] ?? null) && $terms['cancel_at'] > 0) {
            $label .= ' '.sprintf(__('Originally scheduled to end: %s UTC.', 'plandalf-memberpress'), gmdate('Y-m-d H:i', $terms['cancel_at']));
        }

        return $label;
    }

    public static function activate(): void
    {
        Plandalf_Mepr_Event_Log::install();
        Plandalf_Mepr_Reconcile::schedule();
        update_option(self::INSTALLED_VERSION_OPTION, PLANDALF_MEPR_VERSION, false);
    }

    /**
     * Updating a plugin doesn't re-run its activation hook, so bring the
     * events table and the daily check up to date once per new version.
     */
    public static function maybe_upgrade(): void
    {
        if (get_option(self::INSTALLED_VERSION_OPTION) === PLANDALF_MEPR_VERSION) {
            return;
        }

        self::activate();
    }

    public static function deactivate(): void
    {
        Plandalf_Mepr_Reconcile::unschedule();
    }

    /** @param array<int, string> $paths */
    public static function gateway_paths(array $paths): array
    {
        $paths[] = untrailingslashit(PLANDALF_MEPR_PATH.'gateways');

        return array_values(array_unique($paths));
    }

    /**
     * MemberPress's own signup form must never offer the Plandalf method —
     * Plandalf memberships are bought through Plandalf checkout.
     *
     * @param  array<int, string>  $payment_method_ids
     */
    public static function hide_from_checkout(array $payment_method_ids, $field_name = null, $product = null): array
    {
        $gateway_id = get_option(self::GATEWAY_OPTION);

        return array_values(array_filter($payment_method_ids, static fn ($id) => $id !== $gateway_id));
    }

    /** Adds the Plandalf payment method to MemberPress settings if it is missing. */
    public static function ensure_gateway(): void
    {
        if (! class_exists('MeprOptions') || ! Plandalf_Mepr_Settings::is_connected()) {
            return;
        }

        $options = MeprOptions::fetch();
        $integrations = is_array($options->integrations) ? $options->integrations : [];
        $gateway_id = (string) get_option(self::GATEWAY_OPTION, '');

        if ($gateway_id !== '' && isset($integrations[$gateway_id])) {
            return;
        }

        // A reinstall forgets the id; reuse the entry the earlier install left
        // so existing Plandalf subscriptions keep pointing at a live gateway.
        foreach ($integrations as $existing_id => $integration) {
            if (($integration['gateway'] ?? '') === 'MeprPlandalfGateway') {
                update_option(self::GATEWAY_OPTION, (string) $existing_id, false);

                return;
            }
        }

        $gateway = new MeprPlandalfGateway;
        $gateway_id = $gateway_id !== '' ? $gateway_id : (string) $gateway->id;

        $integrations[$gateway_id] = [
            'gateway' => 'MeprPlandalfGateway',
            'id' => $gateway_id,
            'label' => 'Plandalf',
            'use_label' => true,
            'use_icon' => false,
            'use_desc' => false,
        ];
        $options->integrations = $integrations;
        $options->store(false);
        $options->payment_methods(true, true);

        update_option(self::GATEWAY_OPTION, $gateway_id, false);
    }

    public static function gateway(): ?MeprPlandalfGateway
    {
        self::ensure_gateway();
        $gateway_id = (string) get_option(self::GATEWAY_OPTION, '');
        $gateway = $gateway_id !== '' ? MeprOptions::fetch()->payment_method($gateway_id) : null;

        return $gateway instanceof MeprPlandalfGateway ? $gateway : null;
    }

    public static function memberpress_missing_notice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Plandalf for MemberPress needs MemberPress to be installed and active.', 'plandalf-memberpress')
        );
    }
}
