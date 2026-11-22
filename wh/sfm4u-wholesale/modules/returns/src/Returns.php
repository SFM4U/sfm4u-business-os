<?php
declare(strict_types=1);

namespace SFM4U\Modules\Returns;

use DomainException;
use SFM4U\Wholesale\{ModuleContract, Modules};
use WC_Order;
use WC_Order_Refund;
use WP_Error;
use WP_REST_Request;

final class Returns implements ModuleContract
{
    private const SLUG = 'returns';
    private const ROUTE = '/returns';

    public static function register(): void
    {
        add_filter('sfm4u_bos_modules', static fn(array $list): array => $list + [
            self::SLUG => ['Returns & Warranty', 'ফেরত ও ওয়ারেন্টি',
                'Order-based return, exchange and warranty requests.', '', self::class],
        ]);
        if (Modules::on(self::SLUG)) {
            add_action('rest_api_init', [self::class, 'routes']);
        }
    }

    public static function dashboard(): array
    {
        return [
            'numbers' => [],
            'pages' => [],
            'settings' => [],
            'help' => [
                'Buyers request help against an order line. Shops review their own requests. Record a completed refund from the existing order or POS workflow.',
                'ক্রেতা অর্ডারের পণ্য ধরে আবেদন করবেন। দোকান নিজের আবেদন দেখবে। বিদ্যমান অর্ডার বা POS থেকে ফেরত শেষ হলে সেটি নথিভুক্ত করুন।',
            ],
        ];
    }

    public static function routes(): void
    {
        register_rest_route('sfm4u/v1', self::ROUTE . '/(?P<order>[1-9][0-9]*)/(?P<line>[1-9][0-9]*)', [
            [
                'methods' => 'GET',
                'permission_callback' => [self::class, 'mayRead'],
                'callback' => static fn(WP_REST_Request $request) => self::privateResponse(self::read($request)),
            ],
            [
                'methods' => 'POST',
                'permission_callback' => [self::class, 'mayWrite'],
                'callback' => static fn(WP_REST_Request $request) => self::privateResponse(self::write($request)),
            ],
        ]);
    }

    private static function privateResponse(array|WP_Error $value): \WP_REST_Response|WP_Error
    {
        if ($value instanceof WP_Error) {
            return $value;
        }
        return new \WP_REST_Response($value, 200, ['Cache-Control' => 'private, no-store']);
    }

    public static function mayRead(WP_REST_Request $request): bool
    {
        $order = self::order($request);
        return $order && (self::isBuyer($order) || self::isReviewer($order));
    }

    public static function mayWrite(WP_REST_Request $request): bool
    {
        return self::mayRead($request) && wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest') !== false;
    }

    public static function read(WP_REST_Request $request): array|WP_Error
    {
        $row = self::stored(self::key($request));
        return is_array($row) ? $row + (array) self::stored(self::key($request) . '_resolution') : new WP_Error('sfm_returns_missing', 'Request not found.', ['status' => 404]);
    }

    public static function write(WP_REST_Request $request): array|WP_Error
    {
        $order = self::order($request);
        if (!$order || !self::mayWrite($request)) {
            return new WP_Error('sfm_returns_forbidden', 'Request not allowed.', ['status' => 403]);
        }
        try {
            $body = $request->get_json_params() ?? [];
            return ($body['action'] ?? '') === 'resolve'
                ? self::resolve($request, $order, $body)
                : self::submit($request, $order, $body);
        } catch (DomainException $error) {
            return new WP_Error('sfm_returns_rejected', $error->getMessage(), ['status' => $error->getCode()]);
        }
    }

    private static function submit(WP_REST_Request $request, WC_Order $order, array $body): array
    {
        if (!self::isBuyer($order)) {
            throw new DomainException('Only the buyer can submit a request.', 403);
        }
        $kind = $body['kind'] ?? '';
        $reason = is_string($body['reason'] ?? null) ? sanitize_textarea_field($body['reason']) : '';
        $photos = $body['photos'] ?? [];
        if (!in_array($kind, ['return', 'exchange', 'warranty'], true) || $reason === '' || strlen($reason) > 3000
            || !is_array($photos) || !array_is_list($photos) || count($photos) > 3) {
            throw new DomainException('Check the request type, reason and photographs.', 422);
        }
        $photos = array_map([self::class, 'photo'], $photos);
        $row = ['kind' => $kind, 'reason' => $reason, 'photos' => $photos];
        $key = self::key($request);
        if (!self::insert($key, $row)) {
            throw new DomainException('A request already exists for this order line.', 409);
        }
        return $row;
    }

    /** Private inline evidence. It never enters the public WordPress media library. */
    private static function photo(mixed $encoded): string
    {
        if (!is_string($encoded) || strlen($encoded) > 175000) {
            throw new DomainException('Photographs must be small base64 images.', 422);
        }
        $bytes = base64_decode($encoded, true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$image || strlen($bytes) > 131072 || $image[0] > 2048 || $image[1] > 2048
            || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new DomainException('Use a JPEG, PNG or WebP image within the size limit.', 422);
        }
        return 'data:' . $image['mime'] . ';base64,' . base64_encode($bytes);
    }

    private static function resolve(WP_REST_Request $request, WC_Order $order, array $body): array
    {
        if (!self::isReviewer($order)) {
            throw new DomainException('Shop review permission required.', 403);
        }
        $row = self::stored(self::key($request));
        $outcome = $body['outcome'] ?? '';
        $refundId = $body['refund_id'] ?? 0;
        if (!is_array($row) || !Outcome::valid($row['kind'], $outcome, $refundId)) {
            throw new DomainException('Check the request and outcome.', 422);
        }
        if ($outcome === 'refunded') {
            $refund = $refundId > 0 ? wc_get_order($refundId) : false;
            if (!$refund instanceof WC_Order_Refund || $refund->get_parent_id() !== $order->get_id()) {
                throw new DomainException('An existing refund on this order is required.', 422);
            }
            $matches = array_filter($refund->get_items(), static fn($item): bool =>
                (int) $item->get_meta('_refunded_item_id') === (int) $request['line']);
            if (!$matches) {
                throw new DomainException('The refund does not reference this order line.', 422);
            }
        }
        $resolution = ['outcome' => $outcome, 'refund_id' => $refundId];
        $key = self::key($request) . '_resolution';
        if (!self::insert($key, $resolution)) {
            throw new DomainException('This request already has an outcome.', 409);
        }
        return $row + $resolution;
    }

    private static function order(WP_REST_Request $request): ?WC_Order
    {
        if (!Modules::on(self::SLUG) || !get_current_user_id() || !function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order((int) $request['order']);
        return $order instanceof WC_Order && isset($order->get_items()[(int) $request['line']]) ? $order : null;
    }

    private static function isBuyer(WC_Order $order): bool
    {
        return $order->get_customer_id() === get_current_user_id();
    }

    private static function isReviewer(WC_Order $order): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        if (!function_exists('dokan_get_seller_id_by_order') || !current_user_can('sfm_bos_use_returns')) {
            return false;
        }
        $vendor = (int) dokan_get_seller_id_by_order($order);
        if ($vendor !== get_current_user_id()) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            if ((int) get_post_field('post_author', $item->get_product_id()) !== $vendor) {
                return false;
            }
        }
        return true;
    }

    private static function stored(string $key): ?array
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key
        ));
        $record = is_string($json) ? json_decode($json, true) : null;
        return is_array($record) ? $record : null;
    }

    private static function insert(string $key, array $record): bool
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key, wp_json_encode($record)
        ));
        if ($result === false) {
            throw new DomainException('Request storage unavailable.', 503);
        }
        return $result === 1 || self::stored($key) === $record;
    }

    private static function key(WP_REST_Request $request): string
    {
        return 'sfm_returns_' . (int) $request['order'] . '_' . (int) $request['line'];
    }
}
