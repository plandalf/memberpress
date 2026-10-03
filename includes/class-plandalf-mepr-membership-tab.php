<?php

defined('ABSPATH') || exit;

/**
 * "Plandalf" tab in the MemberPress membership editor.
 *
 *   1. Which Plandalf prices grant this membership (link, create, unlink),
 *      with drift between the two systems and a way to resolve it.
 *   2. How the membership page sells: which linked price, which checkout
 *      design (site-wide default or an override), popup/inline, opt-out.
 *
 * The editor is one big form, so every action is a submit button handled
 * during MemberPress's own save; the outcome is shown after the reload.
 */
class Plandalf_Mepr_Membership_Tab
{
    private const NONCE = 'plandalf_mepr_membership';

    private const NOTICE = 'plandalf_mepr_notice_';

    public static function register(): void
    {
        add_action('mepr-product-options-tabs', [self::class, 'tab']);
        add_action('mepr-product-options-pages', [self::class, 'page']);
        add_action('mepr-membership-save-meta', [self::class, 'save'], 10);
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function tab(): void
    {
        printf('<a class="nav-tab main-nav-tab" href="#" id="plandalf">%s</a>', esc_html__('Plandalf', 'plandalf-memberpress'));
    }

    public static function page(MeprProduct $membership): void
    {
        echo '<div class="product_options_page plandalf">';
        self::render($membership);
        echo '</div>';
    }

    private static function render(MeprProduct $membership): void
    {
        if (! Plandalf_Mepr_Settings::is_connected()) {
            printf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__('Connect Plandalf to sell this membership through Plandalf checkout.', 'plandalf-memberpress'),
                esc_url(Plandalf_Mepr_Admin::url()),
                esc_html__('Connect Plandalf', 'plandalf-memberpress')
            );

            return;
        }

        if (! $membership->ID || get_post_status($membership->ID) === 'auto-draft') {
            echo '<p>'.esc_html__('Save this membership first, then link it to Plandalf.', 'plandalf-memberpress').'</p>';

            return;
        }

        $id = (int) $membership->ID;
        $links = Plandalf_Mepr_Links::for_membership($id, true);
        $settings = Plandalf_Mepr_Links::page_settings($id);
        $linked_ids = is_wp_error($links) ? [] : array_map(static fn ($l) => (int) ($l['price']['id'] ?? 0), $links);

        wp_nonce_field(self::NONCE, '_plandalf_nonce');
        ?>
        <h3 class="mepr-page-heading"><?php esc_html_e('Plandalf prices that grant this membership', 'plandalf-memberpress'); ?></h3>
        <p class="description"><?php esc_html_e('Buying any of these prices in Plandalf gives the buyer this membership. Prices and billing schedules are configured in Plandalf; MemberPress controls access. They can differ from the price set here — for example a yearly plan or a launch promo.', 'plandalf-memberpress'); ?></p>

        <?php if (is_wp_error($links)) { ?>
            <p style="color:#b32d2e"><?php echo esc_html($links->get_error_message()); ?></p>
        <?php } else { ?>
            <table class="widefat striped" style="max-width:900px;margin:12px 0">
                <tbody>
                <?php if (! $links) { ?>
                    <tr><td><?php esc_html_e('No Plandalf price grants this membership yet.', 'plandalf-memberpress'); ?></td></tr>
                <?php } ?>
                <?php foreach ($links as $link) { ?>
                    <?php self::render_link($link); ?>
                <?php } ?>
                </tbody>
            </table>

            <?php self::render_add($membership, $linked_ids); ?>
        <?php } ?>

        <h3 class="mepr-page-heading" style="margin-top:28px"><?php esc_html_e('Checkout on this membership\'s page', 'plandalf-memberpress'); ?></h3>
        <?php self::render_checkout_settings($id, $settings, $linked_ids); ?>
        <?php self::render_campaign($id, is_wp_error($links) ? [] : $links); ?>
        <?php
    }

    /** @param array<string, mixed> $link */
    private static function render_link(array $link): void
    {
        $price = (array) ($link['price'] ?? []);
        $drift = (array) ($link['drift'] ?? []);
        $open_drift = $drift && empty($link['drift_acknowledged']);
        $product_key = (string) ($price['product']['lookup_key'] ?? '');
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html(($price['product']['name'] ?? '') ?: ($price['name'] ?? '')); ?></strong>
                <?php if (! empty($price['name']) && ($price['product']['name'] ?? '') !== $price['name']) { ?>
                    — <?php echo esc_html($price['name']); ?>
                <?php } ?>
                <br /><span class="description"><?php echo esc_html(self::terms($price)); ?></span>
                <?php if ($product_key !== '') { ?>
                    <br /><a href="<?php echo esc_url(Plandalf_Mepr_Links::product_url($product_key)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View price in Plandalf ↗', 'plandalf-memberpress'); ?></a>
                <?php } ?>
                <?php if ($open_drift) { ?>
                    <div style="margin-top:6px;padding:6px 10px;background:#fcf0d0;border-radius:4px">
                        <?php echo esc_html(self::describe_drift($drift)); ?>
                        <div style="margin-top:6px">
                            <button type="submit" class="button button-small" name="plandalf_action" value="match:<?php echo (int) $link['id']; ?>"><?php esc_html_e('Update MemberPress to match Plandalf', 'plandalf-memberpress'); ?></button>
                            <button type="submit" class="button button-small" name="plandalf_action" value="keep:<?php echo (int) $link['id']; ?>"><?php esc_html_e('Keep the difference', 'plandalf-memberpress'); ?></button>
                        </div>
                    </div>
                <?php } elseif ($drift) { ?>
                    <br /><span class="description"><?php esc_html_e('Differs from MemberPress on purpose.', 'plandalf-memberpress'); ?></span>
                <?php } ?>
            </td>
            <td style="width:120px;text-align:right;vertical-align:middle">
                <button type="submit" class="button-link button-link-delete" name="plandalf_action" value="unlink:<?php echo (int) $link['id']; ?>" onclick="return confirm('<?php echo esc_js(__('Unlink this price? Buying it will no longer grant this membership.', 'plandalf-memberpress')); ?>');"><?php esc_html_e('Unlink', 'plandalf-memberpress'); ?></button>
            </td>
        </tr>
        <?php
    }

    /** @param array<int, int> $linked_ids */
    private static function render_add(MeprProduct $membership, array $linked_ids): void
    {
        $prices = Plandalf_Mepr_Api::from_settings()->prices();
        $unsupported = Plandalf_Mepr_Links::unsupported_reason($membership);
        ?>
        <p>
            <?php if (! is_wp_error($prices)) { ?>
                <select name="plandalf_link_price" style="min-width:320px">
                    <option value=""><?php esc_html_e('— Link an existing Plandalf price —', 'plandalf-memberpress'); ?></option>
                    <?php foreach ($prices as $price) { ?>
                        <?php if (in_array((int) $price['id'], $linked_ids, true)) {
                            continue;
                        } ?>
                        <option value="<?php echo (int) $price['id']; ?>">
                            <?php echo esc_html(trim(($price['product']['name'] ?? '').' — '.($price['name'] ?? ''), ' —').' · '.self::terms($price)); ?>
                        </option>
                    <?php } ?>
                </select>
                <button type="submit" class="button" name="plandalf_action" value="link"><?php esc_html_e('Link price', 'plandalf-memberpress'); ?></button>
            <?php } else { ?>
                <span style="color:#b32d2e"><?php echo esc_html($prices->get_error_message()); ?></span>
            <?php } ?>
        </p>
        <p>
            <button type="submit" class="button button-secondary" name="plandalf_action" value="create" <?php disabled((bool) $unsupported); ?>>
                <?php esc_html_e('Create a Plandalf price from this membership', 'plandalf-memberpress'); ?>
            </button>
            <span class="description">
                <?php echo $unsupported ? esc_html($unsupported) : esc_html(sprintf(
                    /* translators: %s: price terms, e.g. "$29.00 / month" */
                    __('Creates a product and a %s price in Plandalf and links it.', 'plandalf-memberpress'),
                    self::terms(self::membership_terms($membership))
                )); ?>
            </span>
        </p>
        <?php
    }

    /**
     * @param  array<string, mixed>  $settings  Plandalf_Mepr_Links::page_settings()
     * @param  array<int, int>  $linked_ids
     */
    private static function render_checkout_settings(int $membership_id, array $settings, array $linked_ids): void
    {
        $prices = Plandalf_Mepr_Links::linked_prices($membership_id);
        $site = Plandalf_Mepr_Settings::all();
        $offers = $settings['offer_is_override'] || isset($_GET['plandalf_design'])
            ? Plandalf_Mepr_Api::from_settings()->offers()
            : null;
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'plandalf-memberpress'); ?></th>
                <td>
                    <?php if (Plandalf_Mepr_Links::sells_with_plandalf($membership_id)) { ?>
                        <strong style="color:#0a6b34"><?php esc_html_e('This page shows the Plandalf checkout.', 'plandalf-memberpress'); ?></strong>
                    <?php } elseif ($settings['opted_out']) { ?>
                        <?php esc_html_e('This page keeps the MemberPress signup form (opted out below).', 'plandalf-memberpress'); ?>
                    <?php } elseif (! $prices) { ?>
                        <?php esc_html_e('Link a Plandalf price above to switch this page to the Plandalf checkout.', 'plandalf-memberpress'); ?>
                    <?php } elseif ($settings['offer'] === '') { ?>
                        <?php printf(
                            /* translators: %s: settings page link */
                            esc_html__('Choose a checkout design in %s to switch this page to the Plandalf checkout.', 'plandalf-memberpress'),
                            '<a href="'.esc_url(Plandalf_Mepr_Admin::url()).'">'.esc_html__('Plandalf settings', 'plandalf-memberpress').'</a>'
                        ); ?>
                    <?php } elseif (empty($settings['price_key'])) { ?>
                        <?php esc_html_e('The selected price has no lookup key in Plandalf, so it can\'t be sold on a page yet. Give it one in Plandalf.', 'plandalf-memberpress'); ?>
                    <?php } else { ?>
                        <?php esc_html_e('Plandalf checkout is turned off for this site in Plandalf settings.', 'plandalf-memberpress'); ?>
                    <?php } ?>
                </td>
            </tr>
            <?php if (count($prices) > 1) { ?>
            <tr>
                <th scope="row"><label for="plandalf_page_price"><?php esc_html_e('Price sold on this page', 'plandalf-memberpress'); ?></label></th>
                <td>
                    <select id="plandalf_page_price" name="_plandalf_page_price">
                        <?php foreach ($prices as $price) { ?>
                            <option value="<?php echo (int) $price['id']; ?>" <?php selected($settings['price_id'], (int) $price['id']); ?>><?php echo esc_html($price['label']); ?></option>
                        <?php } ?>
                    </select>
                    <p class="description"><?php esc_html_e('Every linked price grants the membership; this is the one the membership page sells.', 'plandalf-memberpress'); ?></p>
                </td>
            </tr>
            <?php } ?>
            <tr>
                <th scope="row"><?php esc_html_e('Checkout design', 'plandalf-memberpress'); ?></th>
                <td>
                    <?php if ($offers === null) { ?>
                        <?php echo esc_html($site['checkout_offer_name'] ?: __('Not chosen yet', 'plandalf-memberpress')); ?>
                        <span class="description">— <?php esc_html_e('the site-wide design.', 'plandalf-memberpress'); ?></span>
                        <a href="<?php echo esc_url(add_query_arg('plandalf_design', '1')); ?>"><?php esc_html_e('Use a different design for this membership', 'plandalf-memberpress'); ?></a>
                    <?php } elseif (is_wp_error($offers)) { ?>
                        <span style="color:#b32d2e"><?php echo esc_html($offers->get_error_message()); ?></span>
                        <input type="hidden" name="_plandalf_offer" value="<?php echo esc_attr($settings['offer_is_override'] ? $settings['offer'] : ''); ?>" />
                    <?php } else { ?>
                        <select id="plandalf_offer" name="_plandalf_offer">
                            <option value=""><?php
                            /* translators: %s: site-wide design name */
                            echo esc_html(sprintf(__('Site-wide design (%s)', 'plandalf-memberpress'), $site['checkout_offer_name'] ?: __('not chosen', 'plandalf-memberpress')));
                        ?></option>
                            <?php foreach ($offers as $offer) { ?>
                                <option value="<?php echo esc_attr($offer['slug']); ?>" data-name="<?php echo esc_attr($offer['name']); ?>" <?php selected($settings['offer_is_override'] ? $settings['offer'] : '', $offer['slug']); ?>><?php echo esc_html($offer['name']); ?></option>
                            <?php } ?>
                        </select>
                        <input type="hidden" name="_plandalf_offer_name" id="plandalf_offer_name" value="<?php echo esc_attr($settings['offer_is_override'] ? $settings['offer_name'] : ''); ?>" />
                        <p class="description"><?php esc_html_e('The offer is only the look of the checkout; the price comes from the link.', 'plandalf-memberpress'); ?></p>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Shown as', 'plandalf-memberpress'); ?></th>
                <td>
                    <?php foreach (self::display_modes() as $value => $label) { ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="radio" name="_plandalf_display" value="<?php echo esc_attr($value); ?>" <?php checked($settings['display'], $value); ?> />
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Opt out', 'plandalf-memberpress'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="_plandalf_opt_out" value="1" <?php checked($settings['opted_out']); ?> />
                        <?php esc_html_e('Keep the MemberPress signup form for this membership', 'plandalf-memberpress'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <script>
            (function () {
                var select = document.getElementById('plandalf_offer');
                var name = document.getElementById('plandalf_offer_name');
                if (!select || !name) { return; }
                select.addEventListener('change', function () {
                    var option = select.options[select.selectedIndex];
                    name.value = option && option.value ? (option.getAttribute('data-name') || '') : '';
                });
            })();
        </script>
        <?php
    }

    private static function render_campaign(int $membership_id, array $links): void
    {
        $saved = Plandalf_Mepr_Campaign::saved($membership_id);
        $status = $saved ? Plandalf_Mepr_Campaign::status($membership_id) : null;
        ?>
        <h3 class="mepr-page-heading"><?php esc_html_e('Campaign pricing', 'plandalf-memberpress'); ?></h3>
        <p class="description"><?php esc_html_e('Map a Plandalf campaign to this membership. Every campaign tier must grant the same membership. Saving a mapping does not change this page\'s price.', 'plandalf-memberpress'); ?></p>
        <p><label for="plandalf_campaign_slug"><?php esc_html_e('Campaign slug', 'plandalf-memberpress'); ?></label><br />
            <input id="plandalf_campaign_slug" name="plandalf_campaign_slug" type="text" class="regular-text" value="<?php echo esc_attr($saved['promo'] ?? ''); ?>" />
        </p>
        <p><label for="plandalf_campaign_link"><?php esc_html_e('Membership grant link', 'plandalf-memberpress'); ?></label><br />
            <select id="plandalf_campaign_link" name="plandalf_campaign_link">
                <option value=""><?php esc_html_e('Choose a linked price', 'plandalf-memberpress'); ?></option>
                <?php foreach ($links as $link) { ?>
                    <option value="<?php echo (int) $link['id']; ?>" <?php selected($saved['link_id'] ?? 0, $link['id']); ?>><?php echo esc_html(($link['price']['product']['name'] ?? 'Membership').' — '.self::terms($link['price'] ?? [])); ?></option>
                <?php } ?>
            </select>
        </p>
        <?php if (is_wp_error($status)) { ?>
            <p style="color:#b32d2e"><?php echo esc_html($status->get_error_message()); ?></p>
        <?php } elseif ($saved) { ?>
            <p><?php echo esc_html(($status['checkout_enabled'] ?? false) === true
                ? (Plandalf_Mepr_Campaign::selected($membership_id) ? __('This page uses campaign pricing.', 'plandalf-memberpress') : __('Campaign checkout is available. You can use it on this page.', 'plandalf-memberpress'))
                : __('Mapping saved. Campaign checkout is not available yet.', 'plandalf-memberpress')); ?></p>
        <?php } ?>
        <p>
            <button type="submit" class="button" name="plandalf_action" value="campaign-save"><?php esc_html_e('Save campaign mapping', 'plandalf-memberpress'); ?></button>
            <?php if ($saved) { ?>
                <button type="submit" class="button" name="plandalf_action" value="campaign-select" <?php disabled(is_wp_error($status) || ($status['checkout_enabled'] ?? false) !== true); ?>><?php esc_html_e('Use campaign on this page', 'plandalf-memberpress'); ?></button>
                <button type="submit" class="button" name="plandalf_action" value="campaign-forget"><?php esc_html_e('Use fixed price and forget this selection', 'plandalf-memberpress'); ?></button>
            <?php } ?>
        </p>
        <?php
    }

    public static function save(MeprProduct $membership): void
    {
        if (! isset($_POST['_plandalf_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['_plandalf_nonce'])), self::NONCE)) {
            return;
        }
        if (! current_user_can('edit_post', $membership->ID)) {
            return;
        }

        $id = (int) $membership->ID;
        $display = sanitize_key(wp_unslash($_POST['_plandalf_display'] ?? 'inline'));

        update_post_meta($id, Plandalf_Mepr_Links::META_OPT_OUT, empty($_POST['_plandalf_opt_out']) ? '0' : '1');
        update_post_meta($id, Plandalf_Mepr_Links::META_DISPLAY, array_key_exists($display, self::display_modes()) ? $display : 'inline');
        if (isset($_POST['_plandalf_page_price'])) {
            update_post_meta($id, Plandalf_Mepr_Links::META_PAGE_PRICE, absint($_POST['_plandalf_page_price']));
        }
        // The design override field is only rendered when the merchant asked for it.
        if (isset($_POST['_plandalf_offer'])) {
            update_post_meta($id, Plandalf_Mepr_Links::META_OFFER, sanitize_text_field(wp_unslash($_POST['_plandalf_offer'])));
            update_post_meta($id, Plandalf_Mepr_Links::META_OFFER_NAME, sanitize_text_field(wp_unslash($_POST['_plandalf_offer_name'] ?? '')));
        }

        $action = sanitize_text_field(wp_unslash($_POST['plandalf_action'] ?? ''));
        if ($action !== '') {
            self::flash(self::run_action($membership, $action));
        }
    }

    private static function run_action(MeprProduct $membership, string $action): string|WP_Error
    {
        [$verb, $link_id] = array_pad(explode(':', $action, 2), 2, '0');
        $link_id = (int) $link_id;

        switch ($verb) {
            case 'campaign-save':
                return Plandalf_Mepr_Campaign::save((int) $membership->ID, absint($_POST['plandalf_campaign_link'] ?? 0), sanitize_text_field(wp_unslash($_POST['plandalf_campaign_slug'] ?? '')));
            case 'campaign-select':
                return Plandalf_Mepr_Campaign::select((int) $membership->ID);
            case 'campaign-forget':
                return Plandalf_Mepr_Campaign::forget((int) $membership->ID);
            case 'create':
                $result = Plandalf_Mepr_Links::create_price_from($membership);

                return is_wp_error($result) ? $result : __('Created a Plandalf price from this membership and linked it.', 'plandalf-memberpress');

            case 'link':
                $price_id = absint($_POST['plandalf_link_price'] ?? 0);
                if (! $price_id) {
                    return new WP_Error('plandalf_pick', __('Choose a Plandalf price to link.', 'plandalf-memberpress'));
                }
                $result = Plandalf_Mepr_Links::link($membership, $price_id);

                return is_wp_error($result) ? $result : __('Price linked. Buying it now grants this membership.', 'plandalf-memberpress');

            case 'unlink':
                $result = Plandalf_Mepr_Links::unlink($membership, $link_id);

                return is_wp_error($result) ? $result : __('Price unlinked.', 'plandalf-memberpress');

            case 'keep':
                $result = Plandalf_Mepr_Links::acknowledge($membership, $link_id);

                return is_wp_error($result) ? $result : __('Kept the difference. We\'ll flag it again if either side changes.', 'plandalf-memberpress');

            case 'match':
                $links = Plandalf_Mepr_Links::for_membership((int) $membership->ID, true);
                $link = is_wp_error($links) ? null : current(array_filter($links, static fn ($l) => (int) $l['id'] === $link_id));
                if (! $link || empty($link['price'])) {
                    return new WP_Error('plandalf_missing', __('That link no longer exists.', 'plandalf-memberpress'));
                }
                Plandalf_Mepr_Links::match_membership_to(new MeprProduct($membership->ID), $link['price']);

                return __('MemberPress now matches the Plandalf price.', 'plandalf-memberpress');
        }

        return new WP_Error('plandalf_action', __('Unknown action.', 'plandalf-memberpress'));
    }

    private static function flash(string|WP_Error $result): void
    {
        set_transient(self::NOTICE.get_current_user_id(), [
            'type' => is_wp_error($result) ? 'error' : 'success',
            'message' => is_wp_error($result) ? $result->get_error_message() : $result,
        ], 60);
    }

    public static function notice(): void
    {
        $key = self::NOTICE.get_current_user_id();
        $notice = get_transient($key);
        if (! is_array($notice)) {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-%s is-dismissible"><p><strong>Plandalf:</strong> %s</p></div>',
            esc_attr($notice['type']),
            esc_html($notice['message'])
        );
    }

    /** @return array<string, string> */
    public static function display_modes(): array
    {
        return [
            'inline' => __('Embedded on the membership page (recommended)', 'plandalf-memberpress'),
            'popup' => __('Popup from a button', 'plandalf-memberpress'),
            'page' => __('Full screen from a button', 'plandalf-memberpress'),
        ];
    }

    /**
     * "$29.00 / month", "$290.00 / year · 14-day trial", "$199.00 once".
     *
     * @param  array<string, mixed>  $price  PriceSummary shape
     */
    public static function terms(array $price): string
    {
        $amount = strtoupper((string) ($price['currency'] ?? '')).' '.number_format(((int) ($price['amount_cents'] ?? 0)) / 100, 2);
        $recurring = $price['recurring'] ?? null;

        if (! $recurring) {
            return $amount.' '.__('once', 'plandalf-memberpress');
        }

        $count = (int) ($recurring['interval_count'] ?? 1);
        $every = $count > 1 ? $count.' '.$recurring['interval'].'s' : $recurring['interval'];
        $text = $amount.' / '.$every;

        if (! empty($price['trial_period_days'])) {
            /* translators: %d: trial length in days */
            $text .= ' · '.sprintf(__('%d-day trial', 'plandalf-memberpress'), (int) $price['trial_period_days']);
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private static function membership_terms(MeprProduct $membership): array
    {
        $snapshot = Plandalf_Mepr_Links::snapshot($membership);

        return [
            'amount_cents' => $snapshot['amount_cents'],
            'currency' => $snapshot['currency'],
            'recurring' => $snapshot['interval'] ? ['interval' => $snapshot['interval'], 'interval_count' => $snapshot['interval_count']] : null,
            'trial_period_days' => $snapshot['trial_period_days'],
        ];
    }

    /** @param array<int, array{field: string, plandalf: mixed, external: mixed}> $drift */
    private static function describe_drift(array $drift): string
    {
        $labels = [
            'amount_cents' => __('price', 'plandalf-memberpress'),
            'currency' => __('currency', 'plandalf-memberpress'),
            'interval' => __('billing period', 'plandalf-memberpress'),
            'interval_count' => __('billing frequency', 'plandalf-memberpress'),
            'trial_period_days' => __('trial', 'plandalf-memberpress'),
        ];

        $parts = array_map(static function ($d) use ($labels) {
            $format = static fn ($v) => $d['field'] === 'amount_cents' && $v !== null
                ? number_format(((int) $v) / 100, 2)
                : ($v === null ? __('none', 'plandalf-memberpress') : (string) $v);

            return sprintf(
                /* translators: 1: field, 2: MemberPress value, 3: Plandalf value */
                __('%1$s is %2$s in MemberPress but %3$s in Plandalf', 'plandalf-memberpress'),
                $labels[$d['field']] ?? $d['field'],
                $format($d['external']),
                $format($d['plandalf'])
            );
        }, $drift);

        return ucfirst(implode('; ', $parts)).'.';
    }
}
