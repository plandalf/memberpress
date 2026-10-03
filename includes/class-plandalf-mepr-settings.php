<?php

defined('ABSPATH') || exit;

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
            'oauth' => [],
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

        return ! empty($all['oauth']['access_token']) && ! empty($all['organization']['id']) && ! empty($all['endpoint']['secret']);
    }

    public static function mode(): string
    {
        return (string) (self::all()['organization']['mode'] ?? 'live');
    }

    public static function issuer(): string
    {
        return defined('PLANDALF_MEPR_OAUTH_ISSUER')
            ? untrailingslashit(PLANDALF_MEPR_OAUTH_ISSUER) : self::DEFAULT_API_BASE;
    }

    /** Tokens are encrypted at rest using the site's WordPress salt. */
    public static function seal(string $value): string
    {
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new RuntimeException('Unable to encrypt the Plandalf credential.');
        }

        return base64_encode($iv.$tag.$encrypted);
    }

    public static function unseal(string $value): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < 29) {
            return '';
        }
        $decrypted = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));

        return $decrypted === false ? '' : $decrypted;
    }

    public static function events_url(): string
    {
        return rest_url('plandalf/v1/events');
    }
}
