<?php

defined('ABSPATH') || exit;

/**
 * Every verified Plandalf event the site receives, and what the plugin did
 * with it. Doubles as the de-duplication record: an event already applied or
 * ignored is acknowledged again without being re-applied.
 */
class Plandalf_Mepr_Event_Log
{
    public const APPLIED = 'applied';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    public const WAITING = 'waiting';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'plandalf_mepr_events';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(64) NOT NULL,
            type varchar(64) NOT NULL,
            invoice varchar(64) NULL,
            status varchar(16) NOT NULL,
            message text NULL,
            payload longtext NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY invoice (invoice),
            KEY received_at (received_at)
        ) {$charset};");
    }

    /** @return array<string, mixed>|null */
    public static function find(string $event_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE event_id = %s', $event_id), ARRAY_A);

        return $row ?: null;
    }

    /** @param array<string, mixed> $event */
    public static function record(array $event, string $status, string $message = '', array $transaction_ids = []): void
    {
        global $wpdb;

        $object = (array) ($event['data']['object'] ?? []);
        $invoice = ($object['object'] ?? '') === 'invoice' ? (string) ($object['number'] ?? $object['id'] ?? '') : null;

        // Server-owned scope prevents an old connection's refunds from being
        // applied after this site connects to a different account or mode.
        $event['_plandalf_scope'] = self::connection_scope();
        $event['_plandalf_transactions'] = array_map('intval', $transaction_ids);
        if (($event['type'] ?? '') === 'invoice.refunded' && ($object['refunded'] ?? false) === true) {
            // Keep a durable invoice marker without retaining buyer details.
            $event['data']['object'] = array_intersect_key($object, array_flip(['object', 'id', 'number', 'refunded']));
        }
        $saved = $wpdb->replace(self::table(), [
            'event_id' => (string) ($event['id'] ?? ''),
            'type' => (string) ($event['type'] ?? ''),
            'invoice' => $invoice ?: null,
            'status' => $status,
            'message' => $message,
            'payload' => wp_json_encode($event),
            'received_at' => current_time('mysql', true),
        ]);
        if ($saved === false) {
            throw new RuntimeException('Could not persist the Plandalf event result.');
        }
    }

    private static function connection_scope(): string
    {
        return hash('sha256', wp_json_encode([
            Plandalf_Mepr_Settings::api_base(),
            Plandalf_Mepr_Settings::get('organization')['id'] ?? null,
            Plandalf_Mepr_Settings::mode(),
        ]));
    }

    /** Verified full refunds retained for this exact invoice and connection. */
    public static function full_refunds_for(array $invoice): array
    {
        return self::matching_events($invoice, 'invoice.refunded', [self::WAITING, self::APPLIED]);
    }

    public static function has_paid_invoice(array $invoice): bool
    {
        return (bool) self::matching_events($invoice, 'invoice.paid', [self::APPLIED, self::APPLIED]);
    }

    private static function matching_events(array $invoice, string $type, array $statuses): array
    {
        global $wpdb;
        $id = (string) ($invoice['id'] ?? '');
        $number = (string) ($invoice['number'] ?? $id);
        if ($id === '' || $number === '') {
            return [];
        }

        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT payload FROM '.self::table().' WHERE type = %s AND invoice = %s AND status IN (%s, %s) ORDER BY id',
            $type, $number, $statuses[0], $statuses[1]
        ));
        $events = [];
        foreach ($rows as $payload) {
            $event = json_decode($payload, true);
            $object = $event['data']['object'] ?? [];
            if (($event['_plandalf_scope'] ?? '') === self::connection_scope()
                && ($object['id'] ?? null) === $id
                && ($type !== 'invoice.refunded' || ($object['refunded'] ?? false) === true)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** Has an event for this Plandalf invoice number been applied here? */
    public static function invoice_applied(string $invoice): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM '.self::table().' WHERE invoice = %s AND status = %s AND type = %s LIMIT 1',
            $invoice,
            self::APPLIED,
            'invoice.paid'
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $limit = 50): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, event_id, type, invoice, status, message, received_at FROM '.self::table().' ORDER BY id DESC LIMIT %d',
            $limit
        ), ARRAY_A);
    }

    public static function prune(int $keep_days = 90): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::table().' WHERE received_at < %s AND NOT (type = %s AND status IN (%s, %s))',
            gmdate('Y-m-d H:i:s', time() - $keep_days * DAY_IN_SECONDS),
            'invoice.refunded', self::WAITING, self::APPLIED
        ));
    }
}
