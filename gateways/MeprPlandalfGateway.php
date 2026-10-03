<?php

defined('ABSPATH') || exit;

/**
 * Record-only MemberPress payment method for memberships sold through
 * Plandalf. It never takes a payment and is hidden from MemberPress's own
 * checkout (see Plandalf_Mepr_Plugin::hide_from_checkout). It exists so
 * MemberPress reports, emails and the account page treat Plandalf
 * subscriptions like any other, and so "Cancel" reaches Plandalf.
 */
#[AllowDynamicProperties]
class MeprPlandalfGateway extends MeprBaseRealGateway
{
    private ?array $trial_access = null;

    public function __construct()
    {
        $this->name = 'Plandalf';
        $this->key = 'plandalf';
        $this->has_spc_form = false;
        $this->set_defaults();

        // MemberPress calls cancel on the old subscription during group
        // upgrades only when the gateway advertises this capability.
        $this->capabilities = ['cancel-subscriptions'];
        $this->notifiers = [];
        $this->message_pages = [];
    }

    public function load($settings)
    {
        $this->settings = (object) $settings;
        $this->set_defaults();
    }

    protected function set_defaults()
    {
        if (! isset($this->settings)) {
            $this->settings = [];
        }

        $this->settings = (object) array_merge([
            'gateway' => 'MeprPlandalfGateway',
            'id' => $this->generate_id(),
            'label' => 'Plandalf',
            'use_label' => true,
            'icon' => '',
            'use_icon' => false,
            'desc' => '',
            'use_desc' => false,
        ], (array) $this->settings);

        $this->id = $this->settings->id;
        $this->label = $this->settings->label;
        $this->use_label = $this->settings->use_label;
        $this->icon = $this->settings->icon;
        $this->use_icon = $this->settings->use_icon;
        $this->desc = $this->settings->desc;
        $this->use_desc = $this->settings->use_desc;
        $this->recurrence_type = 'automatic';
    }

    /**
     * Cancel at the end of the paid period in Plandalf, then mirror it here.
     * Called from the MemberPress account page and during group upgrades.
     *
     * @param  int  $subscription_id  MemberPress subscription id.
     *
     * @throws MeprGatewayException When Plandalf refuses the cancellation.
     */
    public function process_cancel_subscription($subscription_id)
    {
        $sub = new MeprSubscription($subscription_id);
        if (! $sub->id || $sub->status === MeprSubscription::$cancelled_str) {
            return;
        }

        $id = Plandalf_Mepr_Fulfillment::plandalf_subscription_id((string) $sub->subscr_id);
        $result = Plandalf_Mepr_Api::from_settings()->cancel_subscription($id, true);

        // Not found can mean the connected organization cannot resolve the
        // subscription. It does not establish that the provider stopped billing.
        if (is_wp_error($result)) {
            throw new MeprGatewayException(esc_html($result->get_error_message()));
        }

        if (($result['id'] ?? '') !== $id
            || (! in_array($result['status'] ?? '', ['canceled', 'incomplete_expired'], true)
                && ($result['cancel_at_period_end'] ?? false) !== true)) {
            throw new MeprGatewayException(esc_html__('Plandalf did not confirm cancellation. Check the subscription in Plandalf before retrying.', 'plandalf-memberpress'));
        }

        Plandalf_Mepr_Fulfillment::mark_cancelled($sub);
    }

    /** Record a zero-value trial confirmation using the signed provider period. */
    public function record_trial_subscription(MeprSubscription $sub, string $number, int $period_end): void
    {
        $existing = Plandalf_Mepr_Fulfillment::transaction_for_invoice($number);
        if ($existing) {
            if ((int) $existing->subscription_id !== (int) $sub->id
                || (int) $existing->user_id !== (int) $sub->user_id
                || (int) $existing->product_id !== (int) $sub->product_id
                || (string) $existing->gateway !== (string) $this->id
                || $existing->txn_type !== MeprTransaction::$subscription_confirmation_str) {
                throw new RuntimeException('The trial invoice belongs to another transaction.');
            }

            return;
        }
        $first = $sub->first_txn();
        if ($first instanceof MeprTransaction && ($first->txn_type !== MeprTransaction::$subscription_confirmation_str
            || (string) $first->trans_num !== (string) $sub->subscr_id)) {
            throw new RuntimeException('The trial cannot replace an existing payment.');
        }
        $this->trial_access = ['number' => $number, 'period_end' => $period_end];
        try {
            parent::record_create_sub($sub);
        } finally {
            $this->trial_access = null;
        }
    }

    /** Keep MemberPress signup hooks, while saving the correct trial expiry before they run. */
    public function activate_subscription(MeprTransaction $txn, MeprSubscription $sub, $set_trans_num = true, $set_created_at = true)
    {
        if ($this->trial_access === null) {
            return parent::activate_subscription($txn, $sub, $set_trans_num, $set_created_at);
        }
        $sub->status = MeprSubscription::$active_str;
        if ($set_created_at) {
            $sub->created_at = gmdate('c');
        }
        $sub->store();
        $txn->trans_num = $this->trial_access['number'];
        $txn->status = MeprTransaction::$confirmed_str;
        $txn->txn_type = MeprTransaction::$subscription_confirmation_str;
        $txn->expires_at = gmdate('Y-m-d H:i:s', $this->trial_access['period_end']);
        $txn->set_subtotal(0.00);
        $txn->store(true);
    }

    /** Card updates happen in the Plandalf billing portal, not here. */
    public function hide_update_link($subscription)
    {
        return true;
    }

    public function is_test_mode()
    {
        return Plandalf_Mepr_Settings::mode() === 'test';
    }

    public function force_ssl()
    {
        return false;
    }

    public function display_options_form()
    {
        printf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__('Memberships sold through Plandalf use this payment method automatically. Nothing to set up here.', 'plandalf-memberpress'),
            esc_url(Plandalf_Mepr_Admin::url()),
            esc_html__('Open Plandalf settings', 'plandalf-memberpress')
        );
    }

    public function validate_options_form($errors)
    {
        return $errors;
    }

    // Payment collection happens in Plandalf; MemberPress never routes a checkout here.

    public function process_payment($transaction)
    {
        throw new MeprGatewayException(esc_html__('Plandalf memberships are purchased through Plandalf checkout.', 'plandalf-memberpress'));
    }

    public function process_create_subscription($transaction)
    {
        $this->process_payment($transaction);
    }

    public function process_trial_payment($transaction)
    {
        $this->process_payment($transaction);
    }

    public function process_refund(MeprTransaction $txn)
    {
        throw new MeprGatewayException(esc_html__('Issue refunds in Plandalf. MemberPress updates automatically.', 'plandalf-memberpress'));
    }

    public function process_update_subscription($subscription_id) {}

    public function process_suspend_subscription($subscription_id) {}

    public function process_resume_subscription($subscription_id) {}

    public function process_signup_form($txn) {}

    public function display_payment_page($txn) {}

    public function enqueue_payment_form_scripts() {}

    public function display_payment_form($amount, $user, $product_id, $transaction_id) {}

    public function validate_payment_form($errors)
    {
        return $errors;
    }

    public function display_update_account_form($subscription_id, $errors = [], $message = '') {}

    public function validate_update_account_form($errors = [])
    {
        return $errors;
    }

    public function process_update_account_form($subscription_id) {}

    // Plandalf events are handled by the plugin's REST receiver, not gateway notifiers.

    public function record_payment() {}

    public function record_refund() {}

    public function record_subscription_payment() {}

    public function record_payment_failure() {}

    public function record_trial_payment($transaction) {}

    public function record_create_subscription() {}

    public function record_update_subscription() {}

    public function record_suspend_subscription() {}

    public function record_resume_subscription() {}

    public function record_cancel_subscription() {}
}
