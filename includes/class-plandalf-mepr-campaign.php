<?php

defined('ABSPATH') || exit;

/** Explicit campaign selection for a membership page; all tier prices remain server-owned. */
class Plandalf_Mepr_Campaign
{
    public const META = '_plandalf_campaign';

    public const ENABLED = '_plandalf_campaign_selected';

    public static function saved(int $membership_id): array
    {
        $value = get_post_meta($membership_id, self::META, true);

        return is_array($value) ? $value : [];
    }

    public static function selected(int $membership_id): bool
    {
        return get_post_meta($membership_id, self::ENABLED, true) === '1';
    }

    /** Revalidate both the remote mapping and its ownership before using it. */
    public static function status(int $membership_id): array|WP_Error
    {
        $saved = self::saved($membership_id);
        $settings = Plandalf_Mepr_Links::page_settings($membership_id);
        if (empty($saved['promo']) || empty($saved['link_id']) || ($saved['site'] ?? '') !== Plandalf_Mepr_Links::site()
            || ($saved['offer'] ?? '') !== $settings['offer']) {
            return new WP_Error('plandalf_campaign_changed', __('Save the campaign mapping for this site and checkout design again.', 'plandalf-memberpress'));
        }
        $links = Plandalf_Mepr_Links::for_membership($membership_id, true);
        if (is_wp_error($links)) {
            return $links;
        }
        if (! self::owns($links, (int) $saved['link_id'])) {
            return new WP_Error('plandalf_campaign_link', __('The campaign link no longer grants this membership.', 'plandalf-memberpress'));
        }
        $result = Plandalf_Mepr_Api::from_settings()->campaign_binding($saved['promo']);
        if (is_wp_error($result)) {
            return $result;
        }
        if (($result['promo'] ?? null) !== $saved['promo'] || ($result['binding']['link_id'] ?? null) !== $saved['link_id']
            || ($result['offer'] ?? null) !== $saved['offer']) {
            return new WP_Error('plandalf_campaign_changed', __('The campaign mapping has changed. Save it again.', 'plandalf-memberpress'));
        }

        return $result;
    }

    public static function save(int $membership_id, int $link_id, string $promo): string|WP_Error
    {
        $saved = self::saved($membership_id);
        if ($saved && (($saved['promo'] ?? '') !== $promo || ($saved['link_id'] ?? 0) !== $link_id)) {
            return new WP_Error('plandalf_campaign_replace', __('Stop using the saved campaign on this page before choosing another mapping.', 'plandalf-memberpress'));
        }
        $links = Plandalf_Mepr_Links::for_membership($membership_id, true);
        if (is_wp_error($links)) {
            return $links;
        }
        if ($promo === '' || strlen($promo) > 255 || ! self::owns($links, $link_id)) {
            return new WP_Error('plandalf_campaign_input', __('Enter a campaign slug and choose a link that grants this membership.', 'plandalf-memberpress'));
        }
        $offer = Plandalf_Mepr_Links::page_settings($membership_id)['offer'];
        $result = Plandalf_Mepr_Api::from_settings()->save_campaign_binding($link_id, $promo, $offer);
        if (is_wp_error($result)) {
            return $result;
        }
        if (($result['promo'] ?? null) !== $promo || ($result['binding']['link_id'] ?? null) !== $link_id) {
            return new WP_Error('plandalf_campaign_response', __('The saved mapping could not be confirmed. Check the campaign in Plandalf.', 'plandalf-memberpress'));
        }
        update_post_meta($membership_id, self::META, ['promo' => $promo, 'link_id' => $link_id, 'offer' => $offer, 'site' => Plandalf_Mepr_Links::site()]);

        return __('Campaign mapping saved. Review its status before using it on this page.', 'plandalf-memberpress');
    }

    public static function select(int $membership_id): string|WP_Error
    {
        $status = self::status($membership_id);
        if (is_wp_error($status)) {
            return $status;
        }
        if (($status['checkout_enabled'] ?? false) !== true) {
            return new WP_Error('plandalf_campaign_unavailable', __('Checkout is not available for this campaign yet. This page keeps its current pricing.', 'plandalf-memberpress'));
        }
        update_post_meta($membership_id, self::ENABLED, '1');

        return __('This page now uses campaign pricing.', 'plandalf-memberpress');
    }

    public static function forget(int $membership_id): string
    {
        delete_post_meta($membership_id, self::ENABLED);
        delete_post_meta($membership_id, self::META);

        return __('This page uses its linked fixed price again. The campaign mapping in Plandalf was not deleted.', 'plandalf-memberpress');
    }

    private static function owns(array $links, int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        foreach ($links as $link) {
            if ((int) ($link['id'] ?? 0) === $id && ($link['role'] ?? 'grants') === 'grants') {
                return true;
            }
        }

        return false;
    }
}
