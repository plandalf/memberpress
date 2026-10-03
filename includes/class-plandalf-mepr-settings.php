<?php

defined('ABSPATH') || exit;

/**
 * Typed access to the plugin's single option row.
 *
 * Shape:
 *   api_key      string  the Plandalf API key (live_… or test_…)
 *   api_base     string  Plandalf app URL, e.g. https://admin.plandalf.dev
 *   organization array   { id, name, api_key_id, mode, sdk_url } from GET /api/v1/organization
 *   endpoint     array   { id, secret, url } — this site's registered event endpoint
 *   checkout_offer       string  offer slug used as the checkout design for every membership
 *   checkout_offer_name  string
 *   replace_checkout     bool    replace MemberPress's signup form site-wide (default on)
 */
class Plandalf_Mepr_Settings
{
    public const OPTION = 'plandalf_mepr_settings';

    public const DEFAULT_API_BASE = 'https://admin.plandalf.dev';

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return wp_parse_args(is_array($stored) ? $stored : [], [
            'api_key' => '',
            'api_base' => self::DEFAULT_API_BASE,
            'organization' => [],
            'endpoint' => [],
            'checkout_offer' => '',
            'checkout_offer_name' => '',
            'replace_checkout' => true,
        ]);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    /** @param array<string, mixed> $changes */
    public static function update(array $changes): void
    {
        update_option(self::OPTION, array_merge(self::all(), $changes), false);
    }

    public static function api_base(): string
    {
        return untrailingslashit((string) (self::get('api_base') ?: self::DEFAULT_API_BASE));
    }

    public static function is_connected(): bool
    {
        $all = self::all();

        return $all['api_key'] !== '' && ! empty($all['organization']['id']) && ! empty($all['endpoint']['secret']);
    }

    public static function mode(): string
    {
        return (string) (self::all()['organization']['mode'] ?? 'live');
    }

    public static function events_url(): string
    {
        return rest_url('plandalf/v1/events');
    }
}
