<?php

defined('ABSPATH') || exit;

/**
 * Thin client for Plandalf's public REST API (/api/v1). Every call is
 * authenticated with the site's OAuth access token. Errors come back as WP_Error with
 * the HTTP status and Plandalf's message so admin screens can show them.
 */
class Plandalf_Mepr_Api
{
    public function __construct(
        private string $access_token,
        private string $api_base,
    ) {}

    public static function from_settings(): self
    {
        return new self('', Plandalf_Mepr_Settings::api_base());
    }

    public function connection(): array|WP_Error
    {
        return $this->request('GET', 'site-connection');
    }

    public function revoke(): array|WP_Error
    {
        return $this->request('DELETE', 'site-connection');
    }

    public function identity(array $claims): array|WP_Error
    {
        return $this->request('POST', 'site-connection/identity', $claims);
    }

    /** @return array<string, mixed>|WP_Error */
    public function organization(): array|WP_Error
    {
        return $this->request('GET', 'organization');
    }

    /** @return array<int, array<string, mixed>>|WP_Error */
    public function offers(): array|WP_Error
    {
        $response = $this->request('GET', 'offers');

        return is_wp_error($response) ? $response : (array) ($response['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|WP_Error
     */
    public function upsert_product(array $product): array|WP_Error
    {
        return $this->request('POST', 'products', $product);
    }

    /**
     * @param  array<string, mixed>  $price
     * @return array<string, mixed>|WP_Error
     */
    public function upsert_price(array $price): array|WP_Error
    {
        return $this->request('POST', 'prices', $price);
    }

    /**
     * @param  array<int, string>  $events
     * @return array<string, mixed>|WP_Error
     */
    public function register_endpoint(string $url, array $events, string $description): array|WP_Error
    {
        return $this->request('POST', 'webhook_endpoints', [
            'url' => $url,
            'events' => $events,
            'description' => $description,
        ]);
    }

    /** @return array<string, mixed>|WP_Error */
    public function delete_endpoint(int $id): array|WP_Error
    {
        return $this->request('DELETE', 'webhook_endpoints/'.$id);
    }

    /** @return array<int, array<string, mixed>>|WP_Error */
    public function prices(string $search = ''): array|WP_Error
    {
        $response = $this->request('GET', 'prices'.($search !== '' ? '?'.http_build_query(['search' => $search]) : ''));

        return is_wp_error($response) ? $response : (array) ($response['data'] ?? []);
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function links(array $filters): array|WP_Error
    {
        $response = $this->request('GET', 'links?'.http_build_query($filters));

        return is_wp_error($response) ? $response : (array) ($response['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>|WP_Error
     */
    public function create_link(array $link): array|WP_Error
    {
        return $this->request('POST', 'links', $link);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function refresh_links(array $object): array|WP_Error
    {
        $response = $this->request('POST', 'links/refresh', $object);

        return is_wp_error($response) ? $response : (array) ($response['data'] ?? []);
    }

    /** @return array<string, mixed>|WP_Error */
    public function acknowledge_link(int $id): array|WP_Error
    {
        return $this->request('POST', 'links/'.$id.'/acknowledge');
    }

    /** @return array<string, mixed>|WP_Error */
    public function delete_link(int $id): array|WP_Error
    {
        return $this->request('DELETE', 'links/'.$id);
    }

    /** @return array<string, mixed>|WP_Error */
    public function campaign_binding(string $promo): array|WP_Error
    {
        return $this->request('GET', 'promos/'.rawurlencode($promo).'/catalog-binding');
    }

    /** @return array<string, mixed>|WP_Error */
    public function save_campaign_binding(int $link_id, string $promo, string $offer): array|WP_Error
    {
        return $this->request('POST', 'links/'.$link_id.'/promo-bindings', ['promo' => $promo, 'offer' => $offer]);
    }

    /** @return array<string, mixed>|WP_Error */
    public function subscription(string $id, bool $include_recovery = false): array|WP_Error
    {
        return $this->request('GET', 'subscriptions/'.rawurlencode($id).($include_recovery ? '?include_recovery=1' : ''));
    }

    /** @return array<string, mixed>|WP_Error */
    public function cancel_subscription(string $id, bool $at_period_end = true): array|WP_Error
    {
        return $this->request('POST', 'subscriptions/'.rawurlencode($id).'/cancel', [
            'at_period_end' => $at_period_end,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $method, string $path, ?array $body = null): array|WP_Error
    {
        $token = $this->access_token !== '' ? $this->access_token : Plandalf_Mepr_Connection::access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $args = [
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'User-Agent' => 'plandalf-memberpress/'.PLANDALF_MEPR_VERSION.'; '.home_url(),
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->api_base.'/api/v1/'.ltrim($path, '/'), $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? sprintf(
                /* translators: %d: HTTP status code */
                __('Plandalf returned HTTP %d.', 'plandalf-memberpress'),
                $status
            );

            return new WP_Error('plandalf_http_'.$status, (string) $message, [
                'status' => $status,
                'errors' => $decoded['errors'] ?? null,
            ]);
        }

        return $decoded;
    }
}
