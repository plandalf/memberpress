<?php

defined('ABSPATH') || exit;

/**
 * MemberPress → Plandalf. A four-step setup page:
 *
 *   1. Connect      one click → Plandalf consent screen → back, connected
 *   2. Checkout     pick the checkout design once for the whole site
 *   3. Memberships  link Plandalf prices (one click to create from a membership)
 *   4. Events       what Plandalf sent and what the plugin did with it
 *
 * Every form posts to admin-post.php with a nonce.
 */
class Plandalf_Mepr_Admin
{
    public const PAGE = 'plandalf-memberpress';

    private const STATE = 'plandalf_mepr_connect_state_';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 99);
        add_action('admin_post_plandalf_mepr_start_connect', [self::class, 'handle_start_connect']);
        add_action('admin_post_plandalf_mepr_connected', [self::class, 'handle_connected']);
        add_action('admin_post_plandalf_mepr_connect', [self::class, 'handle_connect_with_key']);
        add_action('admin_post_plandalf_mepr_disconnect', [self::class, 'handle_disconnect']);
        add_action('admin_post_plandalf_mepr_checkout', [self::class, 'handle_checkout']);
        add_action('admin_post_plandalf_mepr_create_price', [self::class, 'handle_create_price']);
        add_filter('plugin_action_links_'.plugin_basename(PLANDALF_MEPR_FILE), [self::class, 'action_links']);
    }

    /** @param array<string, string> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    /** @param array<int, string> $links */
    public static function action_links(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Set up', 'plandalf-memberpress')));

        return $links;
    }

    public static function menu(): void
    {
        add_submenu_page('memberpress', __('Plandalf', 'plandalf-memberpress'), __('Plandalf', 'plandalf-memberpress'), 'manage_options', self::PAGE, [self::class, 'render']);
    }

    // ── Step 1: connect ─────────────────────────────────────────────────────

    /** "Connect with Plandalf": send the admin to Plandalf's consent screen. */
    public static function handle_start_connect(): void
    {
        self::guard('plandalf_mepr_start_connect');

        $api_base = self::submitted_api_base();
        $state = wp_generate_password(40, false);
        set_transient(self::STATE.get_current_user_id(), ['state' => $state, 'api_base' => $api_base], 15 * MINUTE_IN_SECONDS);

        // wp_redirect, not wp_safe_redirect: Plandalf is deliberately off-site.
        wp_redirect($api_base.'/connect/site?'.http_build_query([
            'site' => home_url(),
            'return' => self::connected_url(),
            'state' => $state,
            'platform' => 'memberpress',
        ]));
        exit;
    }

    /** Plandalf sends the admin back here with a single-use code. */
    public static function handle_connected(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'plandalf-memberpress'), 403);
        }

        $pending = get_transient(self::STATE.get_current_user_id());
        delete_transient(self::STATE.get_current_user_id());

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        if (! is_array($pending) || ! hash_equals((string) $pending['state'], $state)) {
            self::back(['error' => __('That connection attempt expired. Click Connect with Plandalf again.', 'plandalf-memberpress')]);
        }

        if (isset($_GET['error'])) {
            self::back(['error' => __('Connection cancelled.', 'plandalf-memberpress')]);
        }

        $grant = Plandalf_Mepr_Connection::exchange_code(
            (string) $pending['api_base'],
            sanitize_text_field(wp_unslash($_GET['code'] ?? '')),
            self::connected_url()
        );
        if (is_wp_error($grant)) {
            self::back(['error' => $grant->get_error_message()]);
        }

        $result = Plandalf_Mepr_Connection::connect($grant['api_key'], $grant['api_base']);
        if (is_wp_error($result)) {
            self::back(['error' => $result->get_error_message()]);
        }

        self::back(['notice' => __('Connected to Plandalf. Next, choose your checkout design.', 'plandalf-memberpress')]);
    }

    /** Advanced: connect by pasting an API key instead. */
    public static function handle_connect_with_key(): void
    {
        self::guard('plandalf_mepr_connect');

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        if ($api_key === '') {
            self::back(['error' => __('Paste your Plandalf API key.', 'plandalf-memberpress')]);
        }

        $result = Plandalf_Mepr_Connection::connect($api_key, self::submitted_api_base());
        self::back(is_wp_error($result)
            ? ['error' => $result->get_error_message()]
            : ['notice' => __('Connected to Plandalf. Next, choose your checkout design.', 'plandalf-memberpress')]);
    }

    public static function handle_disconnect(): void
    {
        self::guard('plandalf_mepr_disconnect');
        Plandalf_Mepr_Connection::disconnect();
        self::back(['notice' => __('Disconnected. Memberships are back on the MemberPress signup form.', 'plandalf-memberpress')]);
    }

    // ── Step 2: checkout design ─────────────────────────────────────────────

    public static function handle_checkout(): void
    {
        self::guard('plandalf_mepr_checkout');

        $offer = sanitize_text_field(wp_unslash($_POST['checkout_offer'] ?? ''));
        Plandalf_Mepr_Settings::update([
            'checkout_offer' => $offer,
            'checkout_offer_name' => $offer !== '' ? sanitize_text_field(wp_unslash($_POST['checkout_offer_name'] ?? '')) : '',
            'replace_checkout' => ! empty($_POST['replace_checkout']),
        ]);

        self::back(['notice' => __('Checkout saved.', 'plandalf-memberpress')]);
    }

    // ── Step 3: memberships ─────────────────────────────────────────────────

    /** One click: create a Plandalf price from a membership (or all unlinked ones) and link it. */
    public static function handle_create_price(): void
    {
        self::guard('plandalf_mepr_create_price');

        $only = absint($_POST['membership'] ?? 0);
        $created = 0;
        $errors = [];

        foreach (Plandalf_Mepr_Links::memberships() as $post) {
            if (($only && $post->ID !== $only) || (! $only && Plandalf_Mepr_Links::linked_prices($post->ID))) {
                continue;
            }
            $result = Plandalf_Mepr_Links::create_price_from(new MeprProduct($post->ID));
            if (is_wp_error($result)) {
                $errors[] = get_the_title($post).': '.$result->get_error_message();
            } else {
                $created++;
            }
        }

        $args = [];
        if ($created) {
            /* translators: %d: number of memberships */
            $args['notice'] = sprintf(_n('Created and linked a Plandalf price for %d membership.', 'Created and linked Plandalf prices for %d memberships.', $created, 'plandalf-memberpress'), $created);
        }
        if ($errors) {
            $args['error'] = implode(' ', $errors);
        }

        self::back($args ?: ['notice' => __('Nothing to create — every membership already has a linked price.', 'plandalf-memberpress')], 'memberships');
    }

    // ── Page ────────────────────────────────────────────────────────────────

    public static function render(): void
    {
        $settings = Plandalf_Mepr_Settings::all();
        $connected = Plandalf_Mepr_Settings::is_connected();
        $has_design = $connected && $settings['checkout_offer'] !== '';
        ?>
        <div class="wrap plandalf-mepr">
            <h1><?php esc_html_e('Plandalf', 'plandalf-memberpress'); ?></h1>
            <p class="description" style="max-width:760px"><?php esc_html_e('Sell your memberships with Plandalf checkout — offers, upsells, promos and payments — while MemberPress keeps members and access. Three steps:', 'plandalf-memberpress'); ?></p>
            <?php self::render_notices(); ?>
            <style>
                .plandalf-mepr .pm-step{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:16px 0;max-width:1040px;padding:4px 24px 20px}
                .plandalf-mepr .pm-step h2{align-items:center;display:flex;gap:10px}
                .plandalf-mepr .pm-num{align-items:center;background:#f0f0f1;border-radius:50%;display:inline-flex;font-size:13px;height:26px;justify-content:center;width:26px}
                .plandalf-mepr .pm-done .pm-num{background:#00a32a;color:#fff}
                .plandalf-mepr .pm-muted{opacity:.55;pointer-events:none}
                .plandalf-mepr .pm-badge{border-radius:10px;font-size:11px;margin-left:6px;padding:2px 8px}
            </style>

            <?php self::render_connect_step($settings, $connected); ?>
            <?php self::render_checkout_step($settings, $connected, $has_design); ?>
            <?php self::render_memberships_step($connected && $has_design); ?>
            <?php if ($connected) { ?>
                <?php self::render_events(); ?>
            <?php } ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $settings */
    private static function render_connect_step(array $settings, bool $connected): void
    {
        $organization = $settings['organization'];
        ?>
        <div class="pm-step <?php echo $connected ? 'pm-done' : ''; ?>">
            <h2><span class="pm-num"><?php echo $connected ? '✓' : '1'; ?></span><?php esc_html_e('Connect your Plandalf account', 'plandalf-memberpress'); ?></h2>
            <?php if ($connected) { ?>
                <p>
                    <?php esc_html_e('Connected to', 'plandalf-memberpress'); ?>
                    <strong><?php echo esc_html($organization['name'] ?: '#'.$organization['id']); ?></strong>
                    <?php self::mode_badge((string) $organization['mode']); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('Plandalf sends purchase events to', 'plandalf-memberpress'); ?>
                    <code><?php echo esc_html($settings['endpoint']['url'] ?? ''); ?></code>.
                    <?php esc_html_e('If you use a security or caching plugin, let this address through.', 'plandalf-memberpress'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect from Plandalf? Memberships go back to the MemberPress signup form.', 'plandalf-memberpress')); ?>');" style="display:inline">
                    <?php wp_nonce_field('plandalf_mepr_disconnect'); ?>
                    <input type="hidden" name="action" value="plandalf_mepr_disconnect" />
                    <?php submit_button(__('Disconnect', 'plandalf-memberpress'), 'secondary', 'submit', false); ?>
                </form>
            <?php } else { ?>
                <p><?php esc_html_e('You\'ll sign in to Plandalf, pick your account, and come straight back.', 'plandalf-memberpress'); ?></p>
            <?php } ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="<?php echo $connected ? 'display:inline;margin-left:8px' : ''; ?>">
                <?php wp_nonce_field('plandalf_mepr_start_connect'); ?>
                <input type="hidden" name="action" value="plandalf_mepr_start_connect" />
                <input type="hidden" name="api_base" value="<?php echo esc_attr(Plandalf_Mepr_Settings::api_base()); ?>" />
                <?php submit_button($connected ? __('Reconnect', 'plandalf-memberpress') : __('Connect with Plandalf', 'plandalf-memberpress'), $connected ? 'secondary' : 'primary hero', 'submit', false); ?>
            </form>

            <details style="margin-top:16px">
                <summary><?php esc_html_e('Advanced', 'plandalf-memberpress'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('plandalf_mepr_connect'); ?>
                    <input type="hidden" name="action" value="plandalf_mepr_connect" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="plandalf_api_key"><?php esc_html_e('Connect with an API key', 'plandalf-memberpress'); ?></label></th>
                            <td>
                                <input type="password" id="plandalf_api_key" name="api_key" class="regular-text" autocomplete="off" placeholder="live_… or test_…" />
                                <p class="description"><?php esc_html_e('Only if you can\'t use the button above. Create a key in Plandalf under Settings → API keys.', 'plandalf-memberpress'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="plandalf_api_base"><?php esc_html_e('Plandalf address', 'plandalf-memberpress'); ?></label></th>
                            <td>
                                <input type="url" id="plandalf_api_base" name="api_base" class="regular-text" value="<?php echo esc_attr(Plandalf_Mepr_Settings::api_base()); ?>" />
                                <p class="description"><?php esc_html_e('Leave as is unless Plandalf support asks you to change it.', 'plandalf-memberpress'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Connect with key', 'plandalf-memberpress'), 'secondary', 'submit', false); ?>
                </form>
            </details>
        </div>
        <?php
    }

    /** @param array<string, mixed> $settings */
    private static function render_checkout_step(array $settings, bool $connected, bool $has_design): void
    {
        $offers = $connected ? Plandalf_Mepr_Api::from_settings()->offers() : [];
        ?>
        <div class="pm-step <?php echo $has_design ? 'pm-done' : ''; ?> <?php echo $connected ? '' : 'pm-muted'; ?>">
            <h2><span class="pm-num"><?php echo $has_design ? '✓' : '2'; ?></span><?php esc_html_e('Choose your checkout design', 'plandalf-memberpress'); ?></h2>
            <p class="description" style="max-width:760px"><?php esc_html_e('Pick a Plandalf offer to use as the look of your checkout — its pages, blocks and upsells. Every membership page will use it to sell that membership\'s own price. Design it in Plandalf any time; changes show up here automatically.', 'plandalf-memberpress'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('plandalf_mepr_checkout'); ?>
                <input type="hidden" name="action" value="plandalf_mepr_checkout" />
                <p>
                    <?php if (is_wp_error($offers)) { ?>
                        <span style="color:#b32d2e"><?php echo esc_html($offers->get_error_message()); ?></span>
                    <?php } else { ?>
                        <select id="plandalf_checkout_offer" name="checkout_offer" style="min-width:360px">
                            <option value=""><?php esc_html_e('— Choose a checkout design —', 'plandalf-memberpress'); ?></option>
                            <?php foreach ($offers as $offer) { ?>
                                <option value="<?php echo esc_attr($offer['slug']); ?>" data-name="<?php echo esc_attr($offer['name']); ?>" <?php selected($settings['checkout_offer'], $offer['slug']); ?>>
                                    <?php echo esc_html(($offer['name'] ?: __('Untitled offer', 'plandalf-memberpress')).(($offer['status'] ?? '') !== 'published' ? ' ('.$offer['status'].')' : '')); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <input type="hidden" name="checkout_offer_name" id="plandalf_checkout_offer_name" value="<?php echo esc_attr($settings['checkout_offer_name']); ?>" />
                        <?php if ($connected) { ?>
                            <a class="button-link" href="<?php echo esc_url(Plandalf_Mepr_Settings::api_base()); ?>" target="_blank" rel="noopener" style="margin-left:8px"><?php esc_html_e('Design one in Plandalf ↗', 'plandalf-memberpress'); ?></a>
                        <?php } ?>
                    <?php } ?>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="replace_checkout" value="1" <?php checked((bool) $settings['replace_checkout']); ?> />
                        <?php esc_html_e('Use Plandalf checkout in place of the MemberPress signup form', 'plandalf-memberpress'); ?>
                    </label>
                </p>
                <?php submit_button(__('Save checkout', 'plandalf-memberpress'), 'primary', 'submit', false); ?>
            </form>
            <script>
                (function () {
                    var select = document.getElementById('plandalf_checkout_offer');
                    var name = document.getElementById('plandalf_checkout_offer_name');
                    if (!select || !name) { return; }
                    select.addEventListener('change', function () {
                        var option = select.options[select.selectedIndex];
                        name.value = option && option.value ? (option.getAttribute('data-name') || '') : '';
                    });
                })();
            </script>
        </div>
        <?php
    }

    private static function render_memberships_step(bool $ready): void
    {
        $memberships = Plandalf_Mepr_Links::memberships();
        $links = $ready ? Plandalf_Mepr_Api::from_settings()->links([
            'system' => Plandalf_Mepr_Links::SYSTEM,
            'site' => Plandalf_Mepr_Links::site(),
        ]) : [];

        $by_membership = [];
        foreach (is_wp_error($links) ? [] : $links as $link) {
            $by_membership[(int) $link['external_id']][] = $link;
        }
        if (! is_wp_error($links)) {
            foreach ($memberships as $membership) {
                if (! empty($by_membership[$membership->ID])) {
                    Plandalf_Mepr_Links::remember((int) $membership->ID, $by_membership[$membership->ID]);
                }
            }
        }
        $selling = count(array_filter($memberships, static fn ($m) => Plandalf_Mepr_Links::sells_with_plandalf($m->ID)));
        $unlinked = count(array_filter($memberships, static fn ($m) => empty($by_membership[$m->ID])));
        $done = $ready && $memberships && $selling === count($memberships);
        ?>
        <div id="memberships" class="pm-step <?php echo $done ? 'pm-done' : ''; ?> <?php echo $ready ? '' : 'pm-muted'; ?>">
            <h2><span class="pm-num"><?php echo $done ? '✓' : '3'; ?></span><?php esc_html_e('Link your memberships to Plandalf prices', 'plandalf-memberpress'); ?></h2>
            <p class="description" style="max-width:760px"><?php esc_html_e('A membership switches to Plandalf checkout once a Plandalf price grants it. Prices and billing schedules are configured in Plandalf; MemberPress controls access. Create a price from this membership or link an existing yearly plan or promo on its Plandalf tab.', 'plandalf-memberpress'); ?></p>
            <?php if (is_wp_error($links)) { ?>
                <p style="color:#b32d2e"><?php echo esc_html($links->get_error_message()); ?></p>
            <?php } ?>
            <p><strong><?php
            /* translators: 1: memberships selling with Plandalf, 2: all memberships */
            echo esc_html(sprintf(__('%1$d of %2$d memberships sell with Plandalf.', 'plandalf-memberpress'), $selling, count($memberships)));
        ?></strong></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Membership', 'plandalf-memberpress'); ?></th>
                        <th><?php esc_html_e('Granted by Plandalf prices', 'plandalf-memberpress'); ?></th>
                        <th><?php esc_html_e('Membership page', 'plandalf-memberpress'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $memberships) { ?>
                    <tr><td colspan="4"><?php esc_html_e('No memberships yet. Create one in MemberPress → Memberships.', 'plandalf-memberpress'); ?></td></tr>
                <?php } ?>
                <?php foreach ($memberships as $membership) { ?>
                    <?php
                $membership_links = $by_membership[$membership->ID] ?? [];
                    $settings = Plandalf_Mepr_Links::page_settings($membership->ID);
                    $drifting = count(array_filter($membership_links, static fn ($l) => ! empty($l['drift']) && empty($l['drift_acknowledged'])));
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url(get_edit_post_link($membership->ID)); ?>"><?php echo esc_html(get_the_title($membership)); ?></a></strong></td>
                        <td>
                            <?php foreach ($membership_links as $link) { ?>
                                <?php
                                $price = (array) ($link['price'] ?? []);
                                $product_key = (string) ($price['product']['lookup_key'] ?? '');
                                $label = Plandalf_Mepr_Membership_Tab::terms($price).' — '.($price['product']['name'] ?? '');
                                ?>
                                <?php if ($product_key !== '') { ?>
                                    <a href="<?php echo esc_url(Plandalf_Mepr_Links::product_url($product_key)); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Configured in Plandalf', 'plandalf-memberpress'); ?>"><?php echo esc_html($label); ?> ↗</a><br />
                                <?php } else { ?>
                                    <?php echo esc_html($label); ?><br />
                                <?php } ?>
                            <?php } ?>
                            <?php if ($drifting) { ?>
                                <a href="<?php echo esc_url(get_edit_post_link($membership->ID)); ?>" style="color:#996800"><?php echo esc_html(sprintf(
                                    /* translators: %d: number of prices */
                                    _n('%d price differs from MemberPress — review', '%d prices differ from MemberPress — review', $drifting, 'plandalf-memberpress'),
                                    $drifting
                                )); ?></a>
                            <?php } ?>
                            <?php if (! $membership_links) { ?>
                                <span class="description"><?php esc_html_e('None yet', 'plandalf-memberpress'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if (Plandalf_Mepr_Links::sells_with_plandalf($membership->ID)) { ?>
                                <span style="color:#0a6b34">● <?php esc_html_e('Plandalf checkout', 'plandalf-memberpress'); ?></span>
                                <?php if ($settings['offer_is_override']) { ?>
                                    <br /><span class="description"><?php echo esc_html($settings['offer_name']); ?></span>
                                <?php } ?>
                            <?php } else { ?>
                                <span class="description">○ <?php esc_html_e('MemberPress signup form', 'plandalf-memberpress'); ?></span>
                            <?php } ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (! $membership_links && $ready) { ?>
                                <?php self::create_price_button((int) $membership->ID, __('Create Plandalf price', 'plandalf-memberpress')); ?>
                            <?php } ?>
                            <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($membership->ID).'#plandalf'); ?>"><?php esc_html_e('Manage', 'plandalf-memberpress'); ?></a>
                            <a class="button button-small" href="<?php echo esc_url(get_permalink($membership->ID)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View page', 'plandalf-memberpress'); ?></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php if ($ready && $unlinked > 1) { ?>
                <p><?php self::create_price_button(0, sprintf(
                    /* translators: %d: number of memberships */
                    __('Create Plandalf prices for all %d unlinked memberships', 'plandalf-memberpress'),
                    $unlinked
                )); ?></p>
            <?php } ?>
        </div>
        <?php
    }

    private static function create_price_button(int $membership_id, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('plandalf_mepr_create_price'); ?>
            <input type="hidden" name="action" value="plandalf_mepr_create_price" />
            <input type="hidden" name="membership" value="<?php echo (int) $membership_id; ?>" />
            <button type="submit" class="button button-small button-primary"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private static function render_events(): void
    {
        $events = Plandalf_Mepr_Event_Log::recent(50);
        ?>
        <div id="events" class="pm-step">
            <h2><?php esc_html_e('Events', 'plandalf-memberpress'); ?></h2>
            <p class="description"><?php esc_html_e('Purchase events Plandalf sent to this site and what the plugin did with each one.', 'plandalf-memberpress'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Received', 'plandalf-memberpress'); ?></th>
                        <th><?php esc_html_e('Event', 'plandalf-memberpress'); ?></th>
                        <th><?php esc_html_e('Result', 'plandalf-memberpress'); ?></th>
                        <th><?php esc_html_e('Details', 'plandalf-memberpress'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $events) { ?>
                    <tr><td colspan="4"><?php esc_html_e('No events yet. They appear here after the first purchase.', 'plandalf-memberpress'); ?></td></tr>
                <?php } ?>
                <?php foreach ($events as $event) { ?>
                    <tr>
                        <td><?php echo esc_html(get_date_from_gmt($event['received_at'], 'Y-m-d H:i:s')); ?></td>
                        <td><code><?php echo esc_html($event['type']); ?></code><?php if (! empty($event['invoice'])) { ?> · <?php echo esc_html($event['invoice']); ?><?php } ?></td>
                        <td><?php echo esc_html(ucfirst($event['status'])); ?></td>
                        <td><?php echo esc_html((string) $event['message']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function connected_url(): string
    {
        return admin_url('admin-post.php?action=plandalf_mepr_connected');
    }

    private static function submitted_api_base(): string
    {
        $submitted = esc_url_raw(wp_unslash($_POST['api_base'] ?? ''));

        return untrailingslashit($submitted ?: Plandalf_Mepr_Settings::api_base());
    }

    private static function mode_badge(string $mode): void
    {
        $test = $mode === 'test';
        printf(
            '<span class="pm-badge" style="background:%s;color:%s">%s</span>',
            $test ? '#fcf0d0' : '#d7f5e3',
            $test ? '#7a5a00' : '#0a6b34',
            esc_html($test ? __('Test mode', 'plandalf-memberpress') : __('Live', 'plandalf-memberpress'))
        );
    }

    private static function render_notices(): void
    {
        foreach (['notice' => 'success', 'error' => 'error'] as $param => $class) {
            $message = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
            if ($message !== '') {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
            }
        }
    }

    private static function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'plandalf-memberpress'), 403);
        }
        check_admin_referer($action);
    }

    /** @param array<string, string> $args */
    private static function back(array $args, string $anchor = ''): never
    {
        wp_safe_redirect(self::url(array_map('rawurlencode', $args)).($anchor !== '' ? '#'.$anchor : ''));
        exit;
    }
}
