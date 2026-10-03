<?php

defined('ABSPATH') || exit;

/**
 * Links between Plandalf prices and MemberPress memberships.
 *
 * A MemberPress membership is an access level; a Plandalf price is something
 * you sell. They are different things, so nothing is copied or kept equal:
 * a membership is *granted by* any number of Plandalf prices (monthly,
 * yearly, a promo, an upsell), each with its own amount.
 *
 * Links are created just in time — when the merchant links an existing
 * Plandalf price, or asks the plugin to create one from the membership. The
 * membership's own terms are sent along as a snapshot so Plandalf can flag
 * drift ("MemberPress says $29/mo, Plandalf sells $25/mo").
 */
class Plandalf_Mepr_Links
{
    public const SYSTEM = 'memberpress';

    public const TYPE = 'membership';

    public const META_LINKED = '_plandalf_linked_prices';

    /** Opt this membership out of Plandalf checkout (keep MemberPress's form). */
    public const META_OPT_OUT = '_plandalf_use_memberpress';

    /** Optional per-membership checkout design (offer slug); site default otherwise. */
    public const META_OFFER = '_plandalf_offer';

    public const META_OFFER_NAME = '_plandalf_offer_name';

    /** Which linked price this membership's page sells (price id); first linked otherwise. */
    public const META_PAGE_PRICE = '_plandalf_page_price';

    public const META_DISPLAY = '_plandalf_display';

    private const INTERVALS = ['days' => 'day', 'weeks' => 'week', 'months' => 'month', 'years' => 'year'];

    /** @var array<int, array<int, array<string, mixed>>|WP_Error> */
    private static array $cache = [];

    /** For tests: forget per-request link lookups. */
    public static function flush_cache(): void
    {
        self::$cache = [];
    }

    public static function register(): void
    {
        // Fires from MemberPress's own save handler, after the membership's
        // terms are stored, so the snapshot we send is current.
        add_action('mepr-membership-save-meta', [self::class, 'refresh'], 20);
    }

    /** This site as Plandalf records it: scheme-less, no trailing slash. */
    public static function site(): string
    {
        return strtolower(rtrim((string) preg_replace('#^https?://#i', '', home_url()), '/'));
    }

    public static function product_url(string $lookup_key): string
    {
        return Plandalf_Mepr_Settings::api_base().'/products/'.rawurlencode($lookup_key);
    }

    /** @return array<int, WP_Post> */
    public static function memberships(): array
    {
        return get_posts([
            'post_type' => MeprProduct::$cpt,
            'post_status' => ['publish', 'private'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    /**
     * Plandalf links for a membership (with price summary and drift).
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function for_membership(int $membership_id, bool $fresh = false): array|WP_Error
    {
        if ($fresh || ! isset(self::$cache[$membership_id])) {
            self::$cache[$membership_id] = Plandalf_Mepr_Api::from_settings()->links([
                'system' => self::SYSTEM,
                'site' => self::site(),
                'external_type' => self::TYPE,
                'external_id' => (string) $membership_id,
            ]);
            if (! is_wp_error(self::$cache[$membership_id])) {
                self::remember($membership_id, self::$cache[$membership_id]);
            }
        }

        return self::$cache[$membership_id];
    }

    /**
     * Linked prices from the local cache (no API call).
     *
     * @return array<int, array{id: int, lookup_key: ?string, product_lookup_key?: ?string, label: string}>
     */
    public static function linked_prices(int $membership_id): array
    {
        $prices = get_post_meta($membership_id, self::META_LINKED, true);

        return is_array($prices) ? array_values(array_filter($prices, 'is_array')) : [];
    }

    /** @return array<int, int> */
    public static function linked_price_ids(int $membership_id): array
    {
        return array_map(static fn ($p) => (int) $p['id'], self::linked_prices($membership_id));
    }

    public static function link(MeprProduct $membership, int $price_id): true|WP_Error
    {
        $result = Plandalf_Mepr_Api::from_settings()->create_link(array_merge(
            self::object($membership),
            ['price_id' => $price_id, 'role' => 'grants']
        ));
        self::for_membership((int) $membership->ID, true);

        return is_wp_error($result) ? $result : true;
    }

    public static function unlink(MeprProduct $membership, int $link_id): true|WP_Error
    {
        $result = Plandalf_Mepr_Api::from_settings()->delete_link($link_id);
        self::for_membership((int) $membership->ID, true);

        return is_wp_error($result) ? $result : true;
    }

    public static function acknowledge(MeprProduct $membership, int $link_id): true|WP_Error
    {
        $result = Plandalf_Mepr_Api::from_settings()->acknowledge_link($link_id);
        self::for_membership((int) $membership->ID, true);

        return is_wp_error($result) ? $result : true;
    }

    /**
     * Create a Plandalf product and price from the membership's own terms and
     * link them — for merchants who don't have a Plandalf price yet.
     */
    public static function create_price_from(MeprProduct $membership): true|WP_Error
    {
        $unsupported = self::unsupported_reason($membership);
        if ($unsupported) {
            return new WP_Error('plandalf_unsupported', $unsupported);
        }

        $api = Plandalf_Mepr_Api::from_settings();
        $key = 'memberpress:'.self::site().':'.$membership->ID;
        $title = get_the_title($membership->ID);

        $product = $api->upsert_product(['name' => $title, 'lookup_key' => $key, 'status' => 'active']);
        if (is_wp_error($product)) {
            return $product;
        }

        $terms = self::snapshot($membership);
        $payload = [
            'product' => ['lookup_key' => $key],
            'lookup_key' => $key,
            'name' => $title,
            'amount_cents' => $terms['amount_cents'],
            'currency' => strtoupper((string) $terms['currency']),
        ];
        if ($terms['interval']) {
            $payload['recurring'] = ['interval' => $terms['interval'], 'interval_count' => $terms['interval_count']];
            if (! empty($terms['trial_period_days'])) {
                $payload['trial_period_days'] = $terms['trial_period_days'];
            }
        }

        $price = $api->upsert_price($payload);
        if (is_wp_error($price)) {
            return $price;
        }

        return self::link($membership, (int) $price['id']);
    }

    /**
     * Make the MemberPress membership's amount and billing period match a
     * linked Plandalf price — the "Plandalf is right" side of a drift.
     *
     * @param  array<string, mixed>  $price  PriceSummary from the API
     */
    public static function match_membership_to(MeprProduct $membership, array $price): void
    {
        $membership->price = number_format(((int) $price['amount_cents']) / 100, 2, '.', '');

        if (! empty($price['recurring'])) {
            $membership->period = (int) ($price['recurring']['interval_count'] ?? 1);
            $membership->period_type = array_search($price['recurring']['interval'], self::INTERVALS, true) ?: 'months';
            $membership->trial = ! empty($price['trial_period_days']);
            $membership->trial_days = (int) ($price['trial_period_days'] ?? 0);
            $membership->trial_amount = 0;
        } else {
            $membership->period_type = 'lifetime';
            $membership->trial = false;
        }

        $membership->store();
        self::refresh($membership);
    }

    /** Push the membership's current name, URL and terms to every link. */
    public static function refresh(MeprProduct $membership): void
    {
        if (! Plandalf_Mepr_Settings::is_connected() || get_post_status($membership->ID) === 'auto-draft') {
            return;
        }

        $links = Plandalf_Mepr_Api::from_settings()->refresh_links(self::object($membership));
        if (! is_wp_error($links)) {
            self::$cache[(int) $membership->ID] = $links;
            self::remember((int) $membership->ID, $links);
        }
    }

    /**
     * Membership ids granted by an invoice line, from the line's links.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, int>
     */
    public static function memberships_granted_by(array $line): array
    {
        $ids = [];
        foreach ((array) ($line['links'] ?? []) as $link) {
            if (($link['system'] ?? '') === self::SYSTEM
                && ($link['external_type'] ?? '') === self::TYPE
                && ($link['role'] ?? 'grants') === 'grants'
                && strtolower((string) ($link['site'] ?? '')) === self::site()
                && get_post_type((int) ($link['external_id'] ?? 0)) === MeprProduct::$cpt) {
                $ids[] = (int) $link['external_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The membership's own terms, in Plandalf's price shape.
     *
     * @return array{amount_cents: int, currency: string, interval: ?string,
     *               interval_count: ?int, trial_period_days: ?int}
     */
    public static function snapshot(MeprProduct $membership): array
    {
        $recurring = ! $membership->is_one_time_payment() && isset(self::INTERVALS[$membership->period_type]);

        return [
            'amount_cents' => (int) round(((float) $membership->price) * 100),
            'currency' => strtolower((string) MeprOptions::fetch()->currency_code),
            'interval' => $recurring ? self::INTERVALS[$membership->period_type] : null,
            'interval_count' => $recurring ? max(1, (int) $membership->period) : null,
            'trial_period_days' => $recurring && $membership->trial && (int) $membership->trial_days > 0 ? (int) $membership->trial_days : null,
        ];
    }

    /**
     * Why a membership can't be created in Plandalf from its own terms, or null.
     * (Linking an existing Plandalf price is always possible.)
     */
    public static function unsupported_reason(MeprProduct $membership): ?string
    {
        if ($membership->expire_type === 'fixed') {
            return __('Memberships with a fixed expiry date can\'t be created in Plandalf automatically. Link an existing Plandalf price instead.', 'plandalf-memberpress');
        }
        if (! $membership->is_one_time_payment() && $membership->trial && (float) $membership->trial_amount > 0) {
            return __('Paid trials can\'t be created in Plandalf automatically. Link an existing Plandalf price instead.', 'plandalf-memberpress');
        }
        if (! $membership->is_one_time_payment() && ! isset(self::INTERVALS[$membership->period_type])) {
            return __('This billing period can\'t be created in Plandalf automatically.', 'plandalf-memberpress');
        }

        return null;
    }

    /**
     * How this membership's page sells, resolved from its own overrides and
     * the site-wide defaults.
     *
     * @return array{opted_out: bool, offer: string, offer_name: string, offer_is_override: bool,
     *               price_id: int, price_key: ?string, display: string}
     */
    public static function page_settings(int $membership_id): array
    {
        $site = Plandalf_Mepr_Settings::all();
        $override = (string) get_post_meta($membership_id, self::META_OFFER, true);
        $prices = self::linked_prices($membership_id);
        $chosen = (int) get_post_meta($membership_id, self::META_PAGE_PRICE, true);
        $price = current(array_filter($prices, static fn ($p) => (int) $p['id'] === $chosen)) ?: ($prices[0] ?? null);

        return [
            'opted_out' => get_post_meta($membership_id, self::META_OPT_OUT, true) === '1',
            'offer' => $override !== '' ? $override : (string) $site['checkout_offer'],
            'offer_name' => $override !== ''
                ? (string) get_post_meta($membership_id, self::META_OFFER_NAME, true)
                : (string) $site['checkout_offer_name'],
            'offer_is_override' => $override !== '',
            'price_id' => (int) ($price['id'] ?? 0),
            'price_key' => $price['lookup_key'] ?? null,
            'display' => (string) (get_post_meta($membership_id, self::META_DISPLAY, true) ?: 'inline'),
        ];
    }

    /**
     * Plandalf replaces MemberPress's checkout for a membership when the site
     * is connected, replacement is on, a checkout design exists, and a linked
     * price (with a lookup key) is there to sell — otherwise a purchase would
     * grant nothing, so MemberPress's own form stays.
     */
    public static function sells_with_plandalf(int $membership_id): bool
    {
        $settings = self::page_settings($membership_id);

        return Plandalf_Mepr_Settings::is_connected()
            && (bool) Plandalf_Mepr_Settings::get('replace_checkout', true)
            && ! $settings['opted_out']
            && $settings['offer'] !== ''
            && ! empty($settings['price_key']);
    }

    /** @return array<string, mixed> */
    private static function object(MeprProduct $membership): array
    {
        return [
            'system' => self::SYSTEM,
            'site' => self::site(),
            'external_type' => self::TYPE,
            'external_id' => (string) $membership->ID,
            'external_name' => get_the_title($membership->ID),
            'external_url' => (string) get_permalink($membership->ID),
            'snapshot' => self::snapshot($membership),
        ];
    }

    /** @param array<int, array<string, mixed>> $links */
    public static function remember(int $membership_id, array $links): void
    {
        $prices = [];
        foreach ($links as $link) {
            $price = (array) ($link['price'] ?? []);
            if (! empty($price['id'])) {
                $prices[] = [
                    'id' => (int) $price['id'],
                    'lookup_key' => $price['lookup_key'] ?? null,
                    'product_lookup_key' => $price['product']['lookup_key'] ?? null,
                    'label' => trim(($price['product']['name'] ?? '').' · '.Plandalf_Mepr_Membership_Tab::terms($price), ' ·'),
                    'drift' => ! empty($link['drift']) && empty($link['drift_acknowledged']),
                ];
            }
        }
        if (get_post_meta($membership_id, self::META_LINKED, true) !== $prices) {
            update_post_meta($membership_id, self::META_LINKED, $prices);
        }
    }
}
