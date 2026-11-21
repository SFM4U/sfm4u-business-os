<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

use DomainException;
use RuntimeException;

final class WorkEngineEvents {
    public const ACTION = 'sfmwh_deliver_work_engine_event';
    public const GROUP = 'sfmwh-work-engine';
    private const OPTION = 'sfmwh_work_engine';
    private const TYPES = ['wholesale.request', 'stock.low', 'wholesale.low_stock'];

    public static function register(): void {
        add_action('sfmwh_buyer_application_created', static fn(int $id) => self::queue('wholesale.request', $id));
        add_action('woocommerce_low_stock', [self::class, 'lowStock']);
        add_action('woocommerce_no_stock', [self::class, 'lowStock']);
        add_action('woocommerce_product_set_stock', [self::class, 'lowStock']);
        add_action('woocommerce_variation_set_stock', [self::class, 'lowStock']);
        add_action(self::ACTION, [self::class, 'deliver'], 10, 2);
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        $defaults = ['enabled' => false, 'url' => '', 'wholesale_low_stock' => false];
        return is_array($saved) ? $saved + $defaults : $defaults;
    }

    public static function saveSettings(bool $enabled, string $url, bool $wholesale_low_stock = false): void {
        if (!current_user_can('manage_options')) {
            throw new DomainException(__('Only site administrators can change Work Engine settings.', 'sfm4u-wholesale'));
        }
        if (($url !== '' && !self::validUrl($url)) || ($enabled && $url === '')) {
            throw new DomainException(__('Enter an HTTPS Work Engine webhook URL without credentials, a query or a fragment.', 'sfm4u-wholesale'));
        }
        update_option(self::OPTION, compact('enabled', 'url', 'wholesale_low_stock'), false);
    }

    public static function lowStock($product): void {
        if (!$product instanceof \WC_Product) {
            return;
        }
        $id = $product->get_stock_managed_by_id();
        $owner = $id === $product->get_id() ? $product : wc_get_product($id);
        if (!$owner) {
            return;
        }
        $type = self::stockType($owner);
        if ($type === 'wholesale.low_stock' ? LowStock::below($owner) : self::isLow($owner)) {
            self::queue($type, $id);
        }
    }

    public static function deliver(string $type, int $recordId): void {
        $config = self::deliveryConfig();
        if (!$config || !self::validEvent($type, $recordId)) {
            return;
        }
        if ($type === 'stock.low' || $type === 'wholesale.low_stock') {
            $product = wc_get_product($recordId);
            if (!$product || self::stockType($product) !== $type
                || !($type === 'wholesale.low_stock' ? LowStock::below($product) : self::isLow($product))) {
                return;
            }
        }
        $response = wp_safe_remote_post($config['url'], [
            'headers' => ['Content-Type' => 'application/json', 'X-SFM4U-Key' => $config['key']],
            'body' => wp_json_encode(['business' => 'algibazar', 'type' => $type, 'record_id' => $recordId]),
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 1024,
        ]);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            // The router may have written a card before a failed response. Avoid automatic duplicates.
            throw new RuntimeException(__('Work Engine delivery could not be confirmed.', 'sfm4u-wholesale'));
        }
    }

    private static function stockType(\WC_Product $owner): string {
        return self::settings()['wholesale_low_stock'] === true && LowStock::threshold($owner) !== null
            ? 'wholesale.low_stock' : 'stock.low';
    }

    private static function isLow(\WC_Product $product): bool {
        $quantity = $product->get_stock_quantity();
        return $product->managing_stock() && $quantity !== null
            && $quantity <= max(wc_get_low_stock_amount($product), absint(get_option('woocommerce_notify_no_stock_amount', 0)));
    }

    private static function queue(string $type, int $recordId): void {
        if (self::validEvent($type, $recordId) && self::deliveryConfig() && function_exists('as_enqueue_async_action') && did_action('action_scheduler_init')) {
            as_enqueue_async_action(self::ACTION, [$type, $recordId], self::GROUP, true);
        }
    }

    private static function validEvent(string $type, int $recordId): bool {
        return $recordId > 0 && in_array($type, self::TYPES, true);
    }

    private static function deliveryConfig(): ?array {
        $settings = self::settings();
        $key = defined('SFM4U_WORK_ENGINE_KEY') ? SFM4U_WORK_ENGINE_KEY : null;
        if ($settings['enabled'] !== true || !is_string($settings['url']) || !self::validUrl($settings['url'])
            || !is_string($key) || !preg_match('/^[\x21-\x7e]{1,256}$/D', $key)) {
            return null;
        }
        return ['url' => $settings['url'], 'key' => $key];
    }

    private static function validUrl(string $url): bool {
        $parts = wp_parse_url($url);
        return strlen($url) <= 2048 && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[\x00-\x20\x7f]/', $url);
    }
}
