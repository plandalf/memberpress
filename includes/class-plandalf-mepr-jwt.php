<?php

defined('ABSPATH') || exit;

/**
 * Signs the identity token passed to `plandalf.identify()` for logged-in
 * members. HS256 with the site's API key; the `kid` header is the key id
 * Plandalf reported from GET /api/v1/organization.
 */
class Plandalf_Mepr_Jwt
{
    public const TTL_SECONDS = 3600;

    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, string $secret, string $kid): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid];
        $segments = [
            self::base64url((string) wp_json_encode($header)),
            self::base64url((string) wp_json_encode($claims)),
        ];
        $segments[] = self::base64url(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    /** Token for a WordPress user, or null when the site is not connected. */
    public static function for_user(WP_User $user): ?string
    {
        $settings = Plandalf_Mepr_Settings::all();
        $kid = $settings['organization']['api_key_id'] ?? '';

        if ($settings['api_key'] === '' || $kid === '') {
            return null;
        }

        $name = trim($user->first_name.' '.$user->last_name) ?: $user->display_name;
        $claims = [
            'sub' => 'wp:'.$user->ID,
            'email' => $user->user_email,
            'name' => $name,
            'iat' => time(),
            'exp' => time() + self::TTL_SECONDS,
        ];

        /**
         * Filters the identity claims before they are signed.
         *
         * @param  array<string, mixed>  $claims
         * @param  WP_User  $user
         */
        $claims = (array) apply_filters('plandalf_mepr_identity_claims', $claims, $user);

        return self::encode($claims, (string) $settings['api_key'], (string) $kid);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
