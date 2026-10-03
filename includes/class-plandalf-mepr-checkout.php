<?php

defined('ABSPATH') || exit;

/**
 * Front end: memberships that sell with Plandalf show a Plandalf checkout in
 * place of MemberPress's signup form (classic, block, shortcode and
 * ReadyLaunch forms all render through the same views), plus the
 * [plandalf_buy] shortcode for buttons anywhere.
 *
 * After a purchase the buyer goes to the MemberPress thank-you page, which
 * waits for Plandalf's signed event to land before saying "you're in".
 */
class Plandalf_Mepr_Checkout
{
    private const FORM_VIEWS = ['/checkout/form', '/checkout/spc_form', '/readylaunch/checkout/form'];

    private static bool $needs_assets = false;

    /** @var array<int, array<string, mixed>> */
    private static array $offers = [];

    public static function register(): void
    {
        add_filter('mepr_view_get_string', [self::class, 'replace_signup_form'], 20, 3);
        add_filter('the_content', [self::class, 'pending_thank_you_content'], 100);
        add_shortcode('plandalf_buy', [self::class, 'shortcode']);
        add_action('wp_footer', [self::class, 'print_assets'], 5);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public static function replace_signup_form(string $view, string $slug, array $vars): string
    {
        if (! in_array($slug, self::FORM_VIEWS, true)) {
            return $view;
        }

        $membership = $vars['product'] ?? null;
        if ($membership instanceof MeprProduct && Plandalf_Mepr_Campaign::selected((int) $membership->ID)
            && ! Plandalf_Mepr_Links::page_settings((int) $membership->ID)['opted_out']
            && Plandalf_Mepr_Settings::get('replace_checkout', true)) {
            return self::buy_ui((int) $membership->ID);
        }
        if (! $membership instanceof MeprProduct || ! Plandalf_Mepr_Links::sells_with_plandalf((int) $membership->ID)) {
            return $view;
        }

        return self::buy_ui((int) $membership->ID);
    }

    /** @param array<string, string>|string $atts */
    public static function shortcode($atts, ?string $content = null): string
    {
        $atts = shortcode_atts(['membership' => '', 'class' => ''], (array) $atts, 'plandalf_buy');
        $membership_id = absint($atts['membership']);

        if (! $membership_id || (! Plandalf_Mepr_Campaign::selected($membership_id) && ! Plandalf_Mepr_Links::sells_with_plandalf($membership_id))) {
            return current_user_can('edit_posts')
                ? '<p><em>'.esc_html__('[plandalf_buy]: this membership has no linked Plandalf price, or no checkout design is set.', 'plandalf-memberpress').'</em></p>'
                : '';
        }

        $label = trim((string) $content) !== '' ? do_shortcode($content) : esc_html__('Join now', 'plandalf-memberpress');

        return self::button($membership_id, wp_kses_post($label), (string) $atts['class'], 'popup');
    }

    private static function buy_ui(int $membership_id): string
    {
        $state = Plandalf_Mepr_Links::page_settings($membership_id);
        $selection = self::selection_attributes($membership_id);
        if (is_wp_error($selection)) {
            return self::unavailable();
        }
        self::track($membership_id);

        if ($state['display'] === 'inline') {
            return sprintf(
                '<div class="plandalf-mepr-inline" data-plandalf-mount="%s"%s%s data-plandalf-continuity="tab" data-plandalf-clear-session-on-purchase="true" data-plandalf-membership="%d" style="width:100%%;max-width:1100px;min-height:640px;margin:0 auto"></div>',
                esc_attr($state['offer']),
                $selection,
                self::mode_attribute(),
                $membership_id
            );
        }

        $label = sprintf(
            /* translators: %s: membership name */
            esc_html__('Join %s', 'plandalf-memberpress'),
            esc_html(get_the_title($membership_id))
        );

        return '<div class="plandalf-mepr-buy">'.self::button($membership_id, $label, '', $state['display'], $selection).'</div>';
    }

    private static function button(int $membership_id, string $label, string $class, string $display, ?string $selection = null): string
    {
        $state = Plandalf_Mepr_Links::page_settings($membership_id);
        $selection ??= self::selection_attributes($membership_id);
        if (is_wp_error($selection)) {
            return self::unavailable();
        }
        self::track($membership_id);

        return sprintf(
            '<button type="button" class="%s" data-plandalf-present="%s"%s data-plandalf-frame="%s"%s data-plandalf-continuity="tab" data-plandalf-clear-session-on-purchase="true" data-plandalf-membership="%d">%s</button>',
            esc_attr(trim('mepr-submit plandalf-mepr-button '.$class)),
            esc_attr($state['offer']),
            $selection,
            esc_attr($display === 'page' ? 'fullscreen' : 'modal'),
            self::mode_attribute(),
            $membership_id,
            $label
        );
    }

    private static function selection_attributes(int $membership_id): string|WP_Error
    {
        if (! Plandalf_Mepr_Campaign::selected($membership_id)) {
            return ' data-plandalf-price="'.esc_attr((string) Plandalf_Mepr_Links::page_settings($membership_id)['price_key']).'"';
        }
        if (! Plandalf_Mepr_Settings::is_connected()) {
            return new WP_Error('plandalf_disconnected');
        }
        $status = Plandalf_Mepr_Campaign::status($membership_id);
        if (is_wp_error($status) || ($status['checkout_enabled'] ?? false) !== true) {
            return new WP_Error('plandalf_campaign_unavailable');
        }

        return ' data-plandalf-catalog-link="'.(int) $status['binding']['link_id'].'" data-plandalf-apply-promo="'.esc_attr($status['promo']).'"';
    }

    private static function unavailable(): string
    {
        return '<p class="plandalf-mepr-unavailable">'.esc_html__('This membership campaign is unavailable. Please contact the site before purchasing.', 'plandalf-memberpress').'</p>';
    }

    /**
     * A site connected with a test_ key runs every checkout in test mode, so
     * its test purchases produce test events — the only kind it receives.
     */
    private static function mode_attribute(): string
    {
        return Plandalf_Mepr_Settings::mode() === 'test' ? ' data-plandalf-mode="test"' : '';
    }

    public static function pending_thank_you_content(string $content): string
    {
        if (! isset($_GET['plandalf_invoice'], $_GET['trans_num'])) {
            return $content;
        }

        $invoice_number = sanitize_text_field(wp_unslash($_GET['plandalf_invoice']));
        $transaction_number = sanitize_text_field(wp_unslash($_GET['trans_num']));
        if ($invoice_number === '' || $invoice_number !== $transaction_number) {
            return $content;
        }

        $transaction = MeprTransaction::get_one_by_trans_num($transaction_number);
        if ($transaction && ! empty($transaction->id)) {
            return $content;
        }

        return '<section class="plandalf-mepr-pending" style="max-width:560px;margin:12vh auto 0;padding:0 24px;text-align:center">'
            .'<h2 style="font-size:28px;margin:0 0 12px">'.esc_html__('Thank you for your purchase', 'plandalf-memberpress').'</h2>'
            .'<p style="font-size:16px;line-height:1.6;color:#4b5563;margin:0">'.esc_html__('We are activating your membership. You will be taken to choose a password automatically.', 'plandalf-memberpress').'</p>'
            .'</section>';
    }

    private static function track(int $membership_id): void
    {
        self::$needs_assets = true;
        $membership = new MeprProduct($membership_id);

        self::$offers[$membership_id] = [
            'thankYouUrl' => MeprOptions::fetch()->thankyou_page_url([
                'membership' => sanitize_title($membership->post_title),
                'membership_id' => $membership_id,
            ]),
        ];
    }

    public static function print_assets(): void
    {
        $waiting = isset($_GET['plandalf_invoice']);
        if (! self::$needs_assets && ! $waiting) {
            return;
        }

        $settings = Plandalf_Mepr_Settings::all();
        $sdk_url = (string) ($settings['organization']['sdk_url'] ?? '');
        $user = wp_get_current_user();

        $config = [
            'identity' => $user->exists() ? Plandalf_Mepr_Jwt::for_user($user) : null,
            'memberships' => (object) self::$offers,
            'statusUrl' => rest_url('plandalf/v1/purchase-status'),
            'waitingFor' => $waiting ? sanitize_text_field(wp_unslash($_GET['plandalf_invoice'])) : null,
            'ref' => $waiting && isset($_GET['plandalf_ref']) ? sanitize_text_field(wp_unslash($_GET['plandalf_ref'])) : null,
            'i18n' => [
                'activating' => __('Activating your membership…', 'plandalf-memberpress'),
                'ready' => __('Your membership is active.', 'plandalf-memberpress'),
                'setPassword' => __('Your membership is active. Taking you to choose your password…', 'plandalf-memberpress'),
                'slow' => __('Your payment was recorded. Membership activation is taking longer than expected. Check again, or contact the site with your invoice number. Do not pay again.', 'plandalf-memberpress'),
                'retry' => __('Check membership status', 'plandalf-memberpress'),
            ],
        ];

        if (self::$needs_assets && $sdk_url !== '') {
            echo "<script>window.plandalf=window.plandalf||function(){(window.plandalf.q=window.plandalf.q||[]).push(arguments)};</script>\n";
            printf("<script src=\"%s\" async></script>\n", esc_url($sdk_url));
        }

        printf("<script>window.PlandalfMepr=%s;</script>\n", wp_json_encode($config));
        printf("<script src=\"%s\" defer></script>\n", esc_url(PLANDALF_MEPR_URL.'assets/checkout.js?ver='.PLANDALF_MEPR_VERSION));
    }
}
