<?php

defined('ABSPATH') || exit;

/**
 * "Plandalf" columns on MemberPress's own admin lists, so the connection is
 * visible where merchants already work:
 *
 *   Memberships    which Plandalf prices grant it, drift, and whether its
 *                  page shows the Plandalf checkout
 *   Subscriptions  which rows are billed by Plandalf, with the Plandalf
 *                  subscription / invoice reference
 *
 * Reads the local link cache only — no API calls while rendering a list.
 */
class Plandalf_Mepr_List_Columns
{
    public const COLUMN = 'plandalf';

    public static function register(): void
    {
        add_filter('mepr-admin-memberships-columns', [self::class, 'membership_columns']);
        add_action('manage_pages_custom_column', [self::class, 'membership_cell'], 10, 2);
        add_action('manage_posts_custom_column', [self::class, 'membership_cell'], 10, 2);

        add_filter('mepr-admin-subscriptions-cols', [self::class, 'subscription_columns'], 10, 2);
        add_action('mepr-admin-subscriptions-cell', [self::class, 'subscription_cell'], 10, 4);
    }

    /** @param array<string, string> $columns */
    public static function membership_columns(array $columns): array
    {
        return self::insert_after($columns, 'terms', self::COLUMN, __('Plandalf', 'plandalf-memberpress'));
    }

    public static function membership_cell(string $column, int $post_id): void
    {
        if ($column !== self::COLUMN || get_post_type($post_id) !== MeprProduct::$cpt) {
            return;
        }

        if (! Plandalf_Mepr_Settings::is_connected()) {
            printf('<span class="description">%s</span>', esc_html__('Not connected', 'plandalf-memberpress'));

            return;
        }

        $prices = Plandalf_Mepr_Links::linked_prices($post_id);
        $manage = get_edit_post_link($post_id).'#plandalf';

        if (! $prices) {
            printf(
                '<span class="description">%s</span> · <a href="%s">%s</a>',
                esc_html__('No Plandalf price', 'plandalf-memberpress'),
                esc_url($manage),
                esc_html__('Connect', 'plandalf-memberpress')
            );

            return;
        }

        foreach ($prices as $price) {
            $product_key = (string) ($price['product_lookup_key'] ?? '');
            if ($product_key !== '') {
                printf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s ↗</a>',
                    esc_url(Plandalf_Mepr_Links::product_url($product_key)),
                    esc_attr__('Configured in Plandalf', 'plandalf-memberpress'),
                    esc_html($price['label'])
                );
            } else {
                echo esc_html($price['label']);
            }
            if (! empty($price['drift'])) {
                printf(' <a href="%s" style="color:#996800" title="%s">⚠</a>', esc_url($manage), esc_attr__('Differs from MemberPress — review', 'plandalf-memberpress'));
            }
            echo '<br />';
        }

        echo '<span class="description">'.esc_html__('Prices managed in Plandalf', 'plandalf-memberpress').'</span><br />';

        echo Plandalf_Mepr_Links::sells_with_plandalf($post_id)
            ? '<span style="color:#0a6b34">● '.esc_html__('Plandalf checkout', 'plandalf-memberpress').'</span>'
            : '<span class="description">○ '.esc_html__('MemberPress signup form', 'plandalf-memberpress').'</span>';
    }

    /** @param array<string, string> $columns */
    public static function subscription_columns(array $columns, string $prefix = 'col_'): array
    {
        return self::insert_after($columns, $prefix.'gateway', $prefix.self::COLUMN, __('Plandalf', 'plandalf-memberpress'));
    }

    /**
     * @param  object  $rec  MemberPress list row (subscription, or transaction for lifetime rows)
     */
    public static function subscription_cell(string $column_name, $rec, $table = null, string $attributes = ''): void
    {
        if (! str_ends_with($column_name, '_'.self::COLUMN)) {
            return;
        }

        $gateway_id = (string) get_option(Plandalf_Mepr_Plugin::GATEWAY_OPTION, '');
        $billed_by_plandalf = $gateway_id !== '' && (string) ($rec->gateway ?? '') === $gateway_id;
        $reference = (string) ($rec->subscr_id ?? $rec->trans_num ?? '');

        echo '<td '.$attributes.'>';
        if ($billed_by_plandalf) {
            printf(
                '<span style="color:#0a6b34">●</span> <code>%s</code>',
                esc_html(Plandalf_Mepr_Fulfillment::plandalf_subscription_id($reference))
            );
        } else {
            echo '<span class="description">—</span>';
        }
        echo '</td>';
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    private static function insert_after(array $columns, string $after, string $key, string $label): array
    {
        if (! array_key_exists($after, $columns)) {
            return $columns + [$key => $label];
        }

        $out = [];
        foreach ($columns as $existing => $value) {
            $out[$existing] = $value;
            if ($existing === $after) {
                $out[$key] = $label;
            }
        }

        return $out;
    }
}
