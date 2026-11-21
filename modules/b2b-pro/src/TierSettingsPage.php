<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

final class TierSettingsPage
{
    public const OPTION = 'sfm4u_b2b_spend_tiers';

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('B2B Spend Tiers', 'sfm4u-wholesale'),
                __('B2B Spend Tiers', 'sfm4u-wholesale'), 'manage_woocommerce', 'sfm4u-b2b-tiers', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot manage spend tiers.', 'sfm4u-wholesale'), '', ['response' => 403]);
        }
        $stored = get_option(self::OPTION, []);
        $rows = [];
        foreach (is_array($stored) ? $stored : [] as $tier) {
            if (is_array($tier) && is_string($tier['tier'] ?? null) && is_int($tier['min_spend_minor'] ?? null)) {
                $rows[] = $tier['tier'] . ',' . $tier['min_spend_minor'];
            }
        }
        $value = implode("\n", $rows);
        $error = '';
        $saved = false;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sfm4u_save_tiers'])) {
            $input = $_POST['sfm4u_tiers'] ?? null;
            if (is_string($input) && strlen($input) <= 3000) {
                $value = wp_unslash($input);
            }
            try {
                $this->save($input, $_POST['sfm4u_tier_nonce'] ?? null);
                $saved = true;
            } catch (\InvalidArgumentException | \RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
        echo '<div class="wrap"><h1>' . esc_html__('B2B Spend Tiers', 'sfm4u-wholesale') . '</h1>';
        if ($error !== '') {
            echo '<div class="notice notice-error" id="sfm4u-tier-error" role="alert"><p>' . esc_html($error) . '</p></div>';
        } elseif ($saved) {
            echo '<div class="notice notice-success" role="status"><p>' . esc_html__('Spend tiers saved.', 'sfm4u-wholesale') . '</p></div>';
        }
        echo '<form method="post">';
        wp_nonce_field('sfm4u_save_tiers', 'sfm4u_tier_nonce', false);
        echo '<label for="sfm4u-tiers"><strong>' . esc_html__('Tier thresholds', 'sfm4u-wholesale') . '</strong></label>';
        echo '<p id="sfm4u-tier-help">' . esc_html(sprintf(
            __('Enter one tier slug and a whole amount in %s minor units per line. Use one zero threshold. Example: base,0 and silver,6000 on separate lines.', 'sfm4u-wholesale'),
            get_woocommerce_currency()
        )) . '</p>';
        echo '<textarea class="large-text code" name="sfm4u_tiers" id="sfm4u-tiers" rows="8" maxlength="3000" required'
            . ' aria-describedby="sfm4u-tier-help' . ($error !== '' ? ' sfm4u-tier-error" aria-invalid="true' : '') . '">'
            . esc_textarea($value) . '</textarea>';
        submit_button(__('Save tiers', 'sfm4u-wholesale'), 'primary', 'sfm4u_save_tiers');
        echo '</form></div>';
    }

    private function save(mixed $input, mixed $nonce): void
    {
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'sfm4u_save_tiers')) {
            throw new \InvalidArgumentException(__('Refresh the page before saving.', 'sfm4u-wholesale'));
        }
        if (!is_string($input) || strlen($input) > 3000 || wc_get_price_decimals() !== 2) {
            throw new \InvalidArgumentException(__('Enter up to 30 tiers for a two-decimal store currency.', 'sfm4u-wholesale'));
        }
        $rows = preg_split('/\R/', trim(wp_unslash($input)));
        if (count($rows) > 30) {
            throw new \InvalidArgumentException(__('Enter at most 30 tiers.', 'sfm4u-wholesale'));
        }
        $tiers = [];
        $slugs = [];
        $amounts = [];
        $max = (string) PHP_INT_MAX;
        foreach ($rows as $index => $row) {
            if (!preg_match('/^([a-z0-9_-]{1,64}),\s*(0|[1-9][0-9]*)$/D', trim($row), $match)
                || strlen($match[2]) > strlen($max)
                || (strlen($match[2]) === strlen($max) && strcmp($match[2], $max) > 0)) {
                throw new \InvalidArgumentException(sprintf(__('Line %d requires a tier slug and a whole amount in minor units.', 'sfm4u-wholesale'), $index + 1));
            }
            $amount = (int) $match[2];
            if (isset($slugs[$match[1]]) || isset($amounts[$amount])) {
                throw new \InvalidArgumentException(__('Each tier slug and threshold must be unique.', 'sfm4u-wholesale'));
            }
            $slugs[$match[1]] = true;
            $amounts[$amount] = true;
            $tiers[] = ['tier' => $match[1], 'min_spend_minor' => $amount];
        }
        if (!isset($amounts[0])) {
            throw new \InvalidArgumentException(__('Add a baseline tier with a zero threshold.', 'sfm4u-wholesale'));
        }
        usort($tiers, static fn(array $left, array $right): int => $left['min_spend_minor'] <=> $right['min_spend_minor']);
        if (!update_option(self::OPTION, $tiers) && get_option(self::OPTION) !== $tiers) {
            throw new \RuntimeException(__('Spend tiers could not be saved. Try again.', 'sfm4u-wholesale'));
        }
    }
}
