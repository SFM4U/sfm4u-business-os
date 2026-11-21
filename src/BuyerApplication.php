<?php
namespace SFM4U\Wholesale;

final class BuyerApplication {
    private const REQUESTED = '_sfmwh_buyer_application';
    private const NONCE = 'sfmwh_buyer_application';

    public static function register(): void {
        add_action('woocommerce_account_dashboard', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handle']);
    }

    public static function status(int $userId): string {
        return match (get_user_meta($userId, '_sfmwh_buyer_status', true)) {
            'approved' => __('Approved', 'sfm4u-wholesale'),
            'suspended' => __('Suspended', 'sfm4u-wholesale'),
            default => metadata_exists('user', $userId, self::REQUESTED)
                ? __('Pending review', 'sfm4u-wholesale') : '',
        };
    }

    public static function submit(string $nonce): bool {
        $userId = get_current_user_id();
        if (!$userId || !wp_verify_nonce($nonce, self::NONCE . '_' . $userId)) {
            return false;
        }
        if (self::status($userId) !== '') {
            return true;
        }
        // Keep the request independent of staff approval, including concurrent profile edits.
        if (add_user_meta($userId, self::REQUESTED, 1, true)) {
            do_action('sfmwh_buyer_application_created', $userId);
            return true;
        }
        return metadata_exists('user', $userId, self::REQUESTED);
    }

    public static function handle(): void {
        if (!is_account_page() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !isset($_POST[self::NONCE])) {
            return;
        }
        $nonce = is_string($_POST[self::NONCE]) ? wp_unslash($_POST[self::NONCE]) : '';
        if (!self::submit($nonce)) {
            wp_die(esc_html__('Unable to submit the wholesale application. Reload your account and try again.', 'sfm4u-wholesale'),
                '', ['response' => 400]);
        }
        wp_safe_redirect(wc_get_page_permalink('myaccount'), 303);
        exit;
    }

    public static function render(): void {
        $userId = get_current_user_id();
        if (!$userId) {
            return;
        }
        echo '<h2>' . esc_html__('Wholesale buying', 'sfm4u-wholesale') . '</h2>';
        $status = self::status($userId);
        if ($status !== '') {
            echo '<p>' . esc_html($status) . '</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(wc_get_page_permalink('myaccount')) . '">';
        wp_nonce_field(self::NONCE . '_' . $userId, self::NONCE, false);
        echo '<button type="submit" class="button">' . esc_html__('Apply for wholesale buying', 'sfm4u-wholesale') . '</button></form>';
    }
}
