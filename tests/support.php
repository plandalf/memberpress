<?php

defined('ABSPATH') || exit(1);

/**
 * Tiny test harness: registration, isolation, assertions, fixtures.
 */
final class Plandalf_Test
{
    /** @var array<string, callable> */
    private static array $tests = [];

    /** @var array<int, array{url: string, method: string, body: mixed}> */
    public static array $requests = [];

    /** @var array<string, callable> url substring => handler(array): array */
    private static array $routes = [];

    /** @var array<int, array<string, mixed>> */
    public static array $mail = [];

    public static function add(string $name, callable $test): void
    {
        self::$tests[$name] = $test;
    }

    public static function run(string $filter = ''): int
    {
        global $wpdb;

        add_filter('pre_http_request', [self::class, 'fake_http'], 1, 3);
        add_filter('pre_wp_mail', [self::class, 'capture_mail'], 5, 2);

        $passed = 0;
        $failed = [];

        foreach (self::$tests as $name => $test) {
            if ($filter !== '' && stripos($name, $filter) === false) {
                continue;
            }

            $wpdb->query('START TRANSACTION');
            self::reset();

            try {
                $test();
                $passed++;
                WP_CLI::log("  \u{2713} {$name}");
            } catch (Throwable $e) {
                $failed[] = $name;
                WP_CLI::log("  \u{2717} {$name}");
                WP_CLI::log('      '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
            } finally {
                $wpdb->query('ROLLBACK');
                self::reset();
            }
        }

        WP_CLI::log('');
        WP_CLI::log(sprintf('%d passed, %d failed', $passed, count($failed)));

        return $failed ? 1 : 0;
    }

    private static function reset(): void
    {
        wp_cache_flush();
        MeprOptions::fetch(true);
        Plandalf_Mepr_Links::flush_cache();
        Plandalf_Mepr_Password_Setup::reset_state();
        self::$requests = [];
        self::$routes = [];
        self::$mail = [];
        $_POST = [];
        $_GET = [];
        wp_set_current_user(0);
    }

    // ── fakes ──────────────────────────────────────────────────────────────

    /** Answer requests whose URL contains $fragment with $handler(array $args): [status, body]. */
    public static function route(string $fragment, callable $handler): void
    {
        self::$routes[$fragment] = $handler;
    }

    public static function fake_http($preempt, array $args, string $url)
    {
        $body = isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : ($args['body'] ?? null);
        self::$requests[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'body' => $body];

        foreach (self::$routes as $fragment => $handler) {
            if (str_contains($url, $fragment)) {
                [$status, $response] = $handler($args, $body);

                return [
                    'headers' => [],
                    'body' => wp_json_encode($response),
                    'response' => ['code' => $status, 'message' => ''],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
        }

        return new WP_Error('unfaked_http', 'Test made an unexpected HTTP request: '.$url);
    }

    public static function capture_mail($short_circuit, array $atts)
    {
        if ($short_circuit === null) {
            self::$mail[] = $atts;
        }

        return true;
    }

    // ── assertions ─────────────────────────────────────────────────────────

    public static function same(mixed $expected, mixed $actual, string $what = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(trim("{$what} expected ".var_export($expected, true).', got '.var_export($actual, true)));
        }
    }

    public static function true(mixed $actual, string $what = ''): void
    {
        self::same(true, $actual, $what);
    }

    public static function false(mixed $actual, string $what = ''): void
    {
        self::same(false, $actual, $what);
    }

    public static function contains(string $needle, string $haystack, string $what = ''): void
    {
        if (! str_contains($haystack, $needle)) {
            throw new RuntimeException(trim("{$what} expected to contain ".var_export($needle, true).' in '.var_export(substr($haystack, 0, 300), true)));
        }
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    public const SECRET = 'whsec_test_secret';

    public static function connect(string $mode = 'test'): void
    {
        Plandalf_Mepr_Settings::update([
            'api_key' => '',
            'oauth' => [
                'access_token' => Plandalf_Mepr_Settings::seal('oauth-access-test'),
                'refresh_token' => Plandalf_Mepr_Settings::seal('oauth-refresh-test'),
                'expires_at' => time() + 3600, 'client_id' => '11111111-1111-4111-8111-111111111111',
                'issuer' => 'https://plandalf.example',
            ],
            'api_base' => 'https://plandalf.example',
            'organization' => ['id' => 1, 'name' => 'Acme', 'api_key_id' => 'site-key-abc', 'mode' => $mode, 'sdk_url' => 'https://acme.plandalf.example/js/plandalf-sdk.js'],
            'endpoint' => ['id' => 7, 'secret' => self::SECRET, 'url' => Plandalf_Mepr_Settings::events_url()],
            'checkout_offer' => 'design-offer-ABC',
            'checkout_offer_name' => 'Site design',
            'replace_checkout' => true,
        ]);
        Plandalf_Mepr_Plugin::ensure_gateway();
    }

    /** @param array<string, mixed> $attributes */
    public static function membership(string $title, float $price, string $period_type = 'months', array $attributes = []): MeprProduct
    {
        $membership = new MeprProduct;
        $membership->post_title = $title;
        $membership->post_status = 'publish';
        $membership->price = $price;
        $membership->period = 1;
        $membership->period_type = $period_type;
        foreach ($attributes as $key => $value) {
            $membership->{$key} = $value;
        }
        $membership->store();

        return new MeprProduct($membership->ID);
    }

    /** Mark a membership as linked to a Plandalf price (the local cache the checkout reads). */
    public static function linked(MeprProduct $membership, int $price_id = 501, ?string $lookup_key = 'gold-monthly'): void
    {
        update_post_meta($membership->ID, Plandalf_Mepr_Links::META_LINKED, [
            ['id' => $price_id, 'lookup_key' => $lookup_key, 'label' => 'Gold · USD 29.00 / month'],
        ]);
    }

    /** @return array<string, mixed> link as it appears on a webhook invoice line */
    public static function grants(MeprProduct $membership, ?string $site = null): array
    {
        return [
            'system' => 'memberpress',
            'site' => $site ?? Plandalf_Mepr_Links::site(),
            'external_type' => 'membership',
            'external_id' => (string) $membership->ID,
            'role' => 'grants',
        ];
    }

    /**
     * An `invoice.paid` object.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function invoice(MeprProduct $membership, array $overrides = []): array
    {
        $recurring = $overrides['recurring'] ?? true;
        unset($overrides['recurring']);

        return array_replace_recursive([
            'object' => 'invoice',
            'id' => '01J'.strtoupper(wp_generate_password(23, false)),
            'number' => 'INV-'.wp_rand(10000, 99999),
            'status' => 'paid',
            'billing_reason' => 'checkout',
            'currency' => 'usd',
            'total' => 2900,
            'customer' => ['id' => 11, 'email' => 'buyer-'.wp_rand().'@example.com', 'name' => 'Grace Buyer', 'external_id' => null],
            'subscription' => $recurring ? ['id' => 'sub_'.wp_generate_password(12, false), 'status' => 'active', 'current_period_end' => time() + 30 * DAY_IN_SECONDS] : null,
            'lines' => [[
                'description' => $membership->post_title,
                'amount' => 2900,
                'price' => ['id' => 501, 'recurring' => $recurring ? ['interval' => 'month', 'interval_count' => 1] : null],
                'links' => [self::grants($membership)],
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function event(string $type, array $object): array
    {
        return ['id' => 'evt_'.wp_generate_password(20, false), 'type' => $type, 'created' => time(), 'livemode' => false, 'data' => ['object' => $object]];
    }

    /**
     * POST an event to the plugin's REST receiver, signed like Plandalf does.
     *
     * @param  array<string, mixed>  $event
     */
    public static function deliver(array $event, ?string $secret = null, ?int $timestamp = null): WP_REST_Response
    {
        $body = (string) wp_json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);

        $request = new WP_REST_Request('POST', '/plandalf/v1/events');
        $request->set_body($body);
        $request->set_header('plandalf-signature', "t={$timestamp},v1={$signature}");

        return Plandalf_Mepr_Events::receive($request);
    }

    /** @return array<int, object> */
    public static function transactions(int $user_id): array
    {
        global $wpdb;
        $db = new MeprDb;

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$db->transactions} WHERE user_id = %d ORDER BY id", $user_id));
    }

    public static function mail_to(string $email): array
    {
        return array_values(array_filter(self::$mail, static fn ($m) => str_contains((string) (is_array($m['to']) ? implode(',', $m['to']) : $m['to']), $email)));
    }
}
