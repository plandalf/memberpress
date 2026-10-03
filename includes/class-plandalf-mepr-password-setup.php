<?php

defined('ABSPATH') || exit;

/**
 * First login for members created by a guest checkout.
 *
 * The buyer pays in Plandalf, so they never typed a password. Their
 * thank-you page sends them straight to MemberPress's set-password page;
 * MemberPress logs them in once it's set, and we then send them to the
 * membership's content. The same link is emailed as a backup, so first
 * access doesn't depend on the site's email delivery.
 *
 * The link is handed out once, within 30 minutes, to whoever presents the
 * purchase's Plandalf invoice id — a random ULID only the buyer's browser
 * receives from checkout (not the sequential invoice number).
 */
class Plandalf_Mepr_Password_Setup
{
    public const TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

    private const HOLD = 'plandalf_mepr_setup_';

    private const META_REDIRECT = '_plandalf_after_password';

    /** Set while a Plandalf-created member sets their first password. */
    private static bool $first_password = false;

    /** Subject of the admin email to drop, once seen. */
    private static ?string $drop_subject = null;

    public static function register(): void
    {
        add_filter('mepr-process-login-redirect-url', [self::class, 'after_password_set'], 20, 2);
        add_action('wp_head', [self::class, 'no_referrer']);

        // MemberPress emails the admin "Password Lost/Changed" whenever its
        // set-password form is used. For a member who just bought through
        // Plandalf that's one pointless email per sale, so drop it — only then.
        add_filter('mepr-validate-reset-password', [self::class, 'watch_reset'], 99);
        add_filter('mepr_admin_pw_reset_title', [self::class, 'mark_admin_email'], 99);
        add_filter('pre_wp_mail', [self::class, 'drop_admin_email'], 1, 2);
    }

    /**
     * Runs just before MemberPress stores the new password.
     *
     * @param  array<int, string>  $errors
     * @return array<int, string>
     */
    public static function watch_reset($errors)
    {
        $login = sanitize_user(wp_unslash($_POST['mepr_screenname'] ?? ''));
        $user = $login !== '' ? get_user_by('login', $login) : false;

        self::$first_password = empty($errors) && $user && get_user_meta($user->ID, self::META_REDIRECT, true) !== '';

        return $errors;
    }

    public static function mark_admin_email(string $subject): string
    {
        if (self::$first_password) {
            self::$drop_subject = $subject;
        }

        return $subject;
    }

    /**
     * @param  null|bool  $short_circuit
     * @param  array<string, mixed>  $atts
     */
    public static function drop_admin_email($short_circuit, array $atts)
    {
        if (self::$drop_subject !== null && ($atts['subject'] ?? null) === self::$drop_subject) {
            self::$drop_subject = null;
            self::$first_password = false;

            return true; // Pretend it was sent.
        }

        return $short_circuit;
    }

    /** For tests: forget per-request state. */
    public static function reset_state(): void
    {
        self::$first_password = false;
        self::$drop_subject = null;
    }

    public static function hold(string $invoice_id, int $user_id, string $link, MeprProduct $membership): void
    {
        if (strlen($invoice_id) < 20) {
            return; // Not an unguessable id; the email is the only way in.
        }

        set_transient(self::key($invoice_id), ['user_id' => $user_id, 'link' => $link], self::TTL_SECONDS);

        $destination = (string) ($membership->access_url ?: '');
        update_user_meta($user_id, self::META_REDIRECT, $destination !== '' ? $destination : MeprOptions::fetch()->account_page_url());
    }

    /** The set-password link for this purchase, once. */
    public static function claim(string $invoice_id): ?string
    {
        if (strlen($invoice_id) < 20) {
            return null;
        }

        $held = get_transient(self::key($invoice_id));
        delete_transient(self::key($invoice_id));

        return is_array($held) && ! empty($held['link']) ? (string) $held['link'] : null;
    }

    /** MemberPress's "Set Your New Password" email, with our one link. */
    public static function email(MeprUser $member, string $link): void
    {
        $locals = [
            'user_login' => $member->user_login,
            'user_data' => get_user_by('id', $member->ID),
            'first_name' => $member->first_name,
            'mepr_blogname' => MeprUtils::blogname(),
            'mepr_blogurl' => home_url(),
            'reset_password_link' => $link,
        ];

        $subject = MeprHooks::apply_filters(
            'mepr_set_new_password_title',
            /* translators: %s: site name */
            sprintf(__('[%s] Set Your New Password', 'memberpress'), $locals['mepr_blogname'])
        );

        ob_start();
        MeprView::render('/emails/user_set_password', compact('locals') + $locals);
        $message = (string) ob_get_clean();

        MeprUtils::wp_mail($member->formatted_email(), $subject, $message, ['Content-Type: text/html']);
    }

    /**
     * After MemberPress logs the new member in, go to what they bought
     * instead of the generic login redirect. One time only.
     */
    public static function after_password_set(string $url, $user = null): string
    {
        $user_id = $user instanceof WP_User ? $user->ID : (int) ($user->ID ?? 0);
        if (! $user_id) {
            return $url;
        }

        $destination = (string) get_user_meta($user_id, self::META_REDIRECT, true);
        if ($destination === '') {
            return $url;
        }

        delete_user_meta($user_id, self::META_REDIRECT);

        return $destination;
    }

    /** The thank-you URL carries the invoice id; don't leak it to other sites. */
    public static function no_referrer(): void
    {
        if (isset($_GET['plandalf_ref'])) {
            echo '<meta name="referrer" content="no-referrer" />'."\n";
        }
    }

    private static function key(string $invoice_id): string
    {
        return self::HOLD.hash('sha256', $invoice_id);
    }
}
