<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

use DomainException;
use SFM4U\Wholesale\Commerce;
use SFM4U\Wholesale\Rule;

final class VendorDashboard
{
    private const ROUTE = 'sfm4u-wholesale';
    private const PAGES = ['rfqs' => 'Incoming RFQs', 'products' => 'Wholesale Product Rules', 'credit' => 'Credit Customers'];

    public function __construct(private WooQuotes $quotes) {}

    public function register(): void
    {
        add_filter('query_vars', static fn(array $vars): array => [...$vars, self::ROUTE]);
        add_filter('dokan_get_dashboard_nav', function (array $menu): array {
            if ($this->vendor()) {
                $menu[self::ROUTE] = ['title' => __('Wholesale', 'sfm4u-wholesale'), 'url' => $this->url('rfqs'), 'pos' => 85];
            }
            return $menu;
        });
        add_action('dokan_load_custom_template', function (array $vars): void {
            if (isset($vars[self::ROUTE])) {
                $this->render(is_string($vars[self::ROUTE]) ? $vars[self::ROUTE] : '');
            }
        });
        add_action('template_redirect', static function (): void {
            if (get_query_var(self::ROUTE) !== '') {
                nocache_headers();
            }
        });
    }

    public function render(string $page): void
    {
        $vendor = $this->vendor();
        if (!$vendor || !isset(self::PAGES[$page])) {
            echo '<p>' . esc_html__('Wholesale vendor access is required.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        echo '<div class="dokan-dashboard-content"><h2>' . esc_html(__(self::PAGES[$page], 'sfm4u-wholesale')) . '</h2><nav aria-label="' . esc_attr__('Wholesale', 'sfm4u-wholesale') . '">';
        foreach (self::PAGES as $tab => $label) {
            echo '<p><a href="' . esc_url($this->url($tab)) . '"' . ($page === $tab ? ' aria-current="page"' : '') . '>'
                . esc_html(__($label, 'sfm4u-wholesale')) . '</a></p>';
        }
        echo '</nav>';
        try {
            $this->submit($page, $vendor);
            match ($page) {
                'rfqs' => $this->rfqs($vendor),
                'products' => $this->products($vendor),
                'credit' => $this->credit($vendor),
            };
        } catch (DomainException | \InvalidArgumentException $error) {
            echo '<p role="alert">' . esc_html(preg_match('/^[a-z_]+(?::|$)/', $error->getMessage()) ? RestApi::messageForLocale(explode(':', $error->getMessage())[0], determine_locale()) : $error->getMessage()) . '</p>';
        } catch (\RuntimeException) {
            echo '<p role="alert">' . esc_html__('The wholesale request could not be completed. Try again.', 'sfm4u-wholesale') . '</p>';
        }
        echo '</div>';
    }

    private function submit(string $page, int $vendor): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['sfm4u_vendor_submit'])) {
            return;
        }
        if ($page === 'credit' || !wp_verify_nonce($this->post('nonce'), 'sfm4u_vendor_' . $page)) {
            throw new DomainException(__('Refresh this page before submitting.', 'sfm4u-wholesale'));
        }
        if ($page === 'rfqs') {
            $id = $this->post('quote');
            $key = $this->post('key');
            $revision = Rule::number($this->post('revision'), 0, 1000000000);
            if (!wp_is_uuid($id, 4) || !wp_is_uuid($key, 4)) {
                throw new DomainException(__('Choose an incoming quote.', 'sfm4u-wholesale'));
            }
            $lines = [];
            foreach ($this->rows('offer', 3) as [$sku, $quantity, $minor]) {
                $lines[] = ['sku' => $sku, 'qty' => Rule::number($quantity, 1, 1000000),
                    'unit_price_minor' => Rule::number($minor, 0, 1000000000)];
            }
            $valid = $this->post('valid_until');
            $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valid, wp_timezone());
            if (!$expiry || $expiry->format('Y-m-d\TH:i') !== $valid) {
                throw new DomainException(__('Enter a valid offer expiry in the store time zone.', 'sfm4u-wholesale'));
            }
            try {
                $this->quotes->change($vendor, 'seller', $id, $key, 'offer', ['offer_lines' => $lines, 'valid_until' => $expiry->getTimestamp(), 'expected_revision' => $revision]);
            } catch (DomainException | \InvalidArgumentException $error) {
                throw new DomainException(RestApi::messageForLocale(explode(':', $error->getMessage())[0], determine_locale()));
            }
            echo '<p role="status">' . esc_html__('Offer sent.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        $product = $this->ownedProduct(Rule::number($this->post('product'), 1, 9999999999), $vendor);
        $prior = $product->get_meta(Commerce::META, true, 'edit');
        $rule = [
            'minor' => $this->post('minor'), 'min' => $this->post('minimum'),
            'max' => $this->post('maximum'), 'step' => $this->post('step'),
            'buyer' => is_array($prior) ? ($prior['buyer'] ?? 'logged_in') : 'logged_in',
            'stock_unit' => is_array($prior) ? ($prior['stock_unit'] ?? 'piece') : 'piece',
            'tiers' => [], 'packs' => [], 'pack_minor' => [],
        ];
        foreach ($this->rows('tiers', 2) as [$threshold, $minor]) {
            $threshold = Rule::number($threshold, 1, 1000000);
            if (isset($rule['tiers'][$threshold])) {
                throw new DomainException(__('Each price threshold must be unique.', 'sfm4u-wholesale'));
            }
            $rule['tiers'][$threshold] = $minor;
        }
        foreach ($this->rows('packs', 3) as [$name, $quantity, $minor]) {
            if (isset($rule['packs'][$name])) {
                throw new DomainException(__('Each pack name must be unique.', 'sfm4u-wholesale'));
            }
            $rule['packs'][$name] = $quantity;
            if ($minor !== '') $rule['pack_minor'][$name] = $minor;
        }
        Commerce::saveRule($product, $rule);
        echo '<p role="status">' . esc_html__('Wholesale rules saved.', 'sfm4u-wholesale') . '</p>';
    }

    private function rfqs(int $vendor): void
    {
        $quotes = $this->quotes->incomingForVendor($vendor);
        if (!$quotes) echo '<p>' . esc_html__('No incoming RFQs.', 'sfm4u-wholesale') . '</p>';
        foreach ($quotes as $quote) {
            echo '<article><h3>' . esc_html($quote['id']) . '</h3><ul>';
            foreach ($quote['request_lines'] as $line) {
                echo '<li>' . esc_html($line['sku'] . ' × ' . $line['qty']) . '</li>';
            }
            echo '</ul>';
            if ($quote['revision_note']) echo '<p>' . esc_html($quote['revision_note']) . '</p>';
            if ($quote['state'] === 'submitted') {
                echo '<form method="post"><fieldset><legend>' . esc_html__('Send offer', 'sfm4u-wholesale') . '</legend>';
                $this->nonce('rfqs');
                $this->hidden('quote', $quote['id']);
                $this->hidden('key', wp_generate_uuid4());
                $this->hidden('revision', (string) $quote['revision']);
                $field = 'offer-' . $quote['id'];
                $initial = array_map(static fn(array $line): string => $line['sku'] . ',' . $line['qty'] . ',', $quote['request_lines']);
                $this->textarea('offer', $field, __('Offer lines (SKU, quantity, unit price in minor units)', 'sfm4u-wholesale'), implode("\n", $initial));
                $this->input('valid_until', 'until-' . $quote['id'], __('Valid until (store time)', 'sfm4u-wholesale'), '', 'datetime-local');
                echo '<button name="sfm4u_vendor_submit" value="1">' . esc_html__('Send offer', 'sfm4u-wholesale') . '</button></fieldset></form>';
            } else {
                echo '<p>' . esc_html__('Offer sent.', 'sfm4u-wholesale') . '</p>';
            }
            echo '</article>';
        }
    }

    private function products(int $vendor): void
    {
        $id = isset($_GET['sfm4u_product']) && is_string($_GET['sfm4u_product']) ? absint($_GET['sfm4u_product']) : 0;
        $products = get_posts(['post_type' => 'product', 'post_status' => ['publish', 'pending', 'draft', 'private'],
            'author' => $vendor, 'numberposts' => 50, 'orderby' => 'ID', 'order' => 'DESC']);
        echo '<ul>';
        foreach ($products as $post) {
            echo '<li><a href="' . esc_url(add_query_arg('sfm4u_product', $post->ID, $this->url('products'))) . '">'
                . esc_html($post->post_title) . '</a></li>';
        }
        echo '</ul>';
        if (!$id) return;
        $product = $this->ownedProduct($id, $vendor);
        $rule = $product->get_meta(Commerce::META, true, 'edit');
        $rule = is_array($rule) ? $rule : [];
        echo '<h3>' . esc_html($product->get_name()) . '</h3>';
        if ($product->is_type('variable')) {
            echo '<ul>';
            foreach ($product->get_children() as $variation) {
                echo '<li><a href="' . esc_url(add_query_arg('sfm4u_product', $variation, $this->url('products'))) . '">'
                    . esc_html(sprintf(__('Variation #%s', 'sfm4u-wholesale'), $variation)) . '</a></li>';
            }
            echo '</ul>';
            return;
        }
        echo '<form method="post">';
        $this->nonce('products');
        $this->hidden('product', (string) $id);
        foreach (['minor' => ['Base price in minor units', 'minor', ''], 'minimum' => ['Minimum quantity', 'min', '1'],
            'maximum' => ['Maximum quantity', 'max', '1000000'], 'step' => ['Quantity step', 'step', '1']] as $key => [$label, $field, $default]) {
            $this->input($key, 'sfm4u-' . $key, $label, (string) ($rule[$field] ?? $default), 'number');
        }
        $tiers = [];
        foreach ($rule['tiers'] ?? [] as $threshold => $minor) $tiers[] = "$threshold,$minor";
        $packs = [];
        foreach ($rule['packs'] ?? [] as $name => $quantity) $packs[] = "$name,$quantity," . ($rule['pack_minor'][$name] ?? '');
        $this->textarea('tiers', 'sfm4u-tiers', __('Price tiers (minimum quantity, unit price in minor units)', 'sfm4u-wholesale'), implode("\n", $tiers));
        $this->textarea('packs', 'sfm4u-packs', __('Packs (name, stock units, optional price in minor units)', 'sfm4u-wholesale'), implode("\n", $packs));
        echo '<button name="sfm4u_vendor_submit" value="1">' . esc_html__('Save wholesale rules', 'sfm4u-wholesale') . '</button></form>';
    }

    private function credit(int $vendor): void
    {
        $rows = $this->quotes->creditCustomersForVendor($vendor);
        if (!$rows) echo '<p>' . esc_html__('No credit customers with an order from your shop.', 'sfm4u-wholesale') . '</p>';
        echo '<ul>';
        foreach ($rows as $row) {
            $buyer = get_userdata((int) $row['buyer']);
            if ($buyer) {
                echo '<li>' . esc_html($buyer->display_name . ' · NET ' . $row['net_days'] . ' · ' . $row['currency']) . '</li>';
            }
        }
        echo '</ul>';
    }

    private function ownedProduct(int $id, int $vendor): \WC_Product
    {
        $product = wc_get_product($id);
        $ownerId = $product ? ($product->get_parent_id() ?: $id) : 0;
        if (!$product || (int) get_post_field('post_author', $ownerId) !== $vendor || !current_user_can('edit_post', $ownerId)) {
            throw new DomainException(__('You cannot edit this product.', 'sfm4u-wholesale'));
        }
        return $product;
    }

    private function vendor(): int
    {
        $id = get_current_user_id();
        return $id && current_user_can('sfm4u_b2b_offer') && dokan_is_user_seller($id) && dokan_is_seller_enabled($id) ? $id : 0;
    }

    private function url(string $page): string
    {
        $id = (int) dokan_get_option('dashboard', 'dokan_pages', 0);
        return $id ? add_query_arg(self::ROUTE, $page, get_permalink($id)) : '';
    }

    private function post(string $key): string
    {
        return is_string($_POST[$key] ?? null) ? wp_unslash($_POST[$key]) : '';
    }

    private function rows(string $field, int $width): array
    {
        $input = trim($this->post($field));
        if ($input === '') return [];
        $rows = preg_split('/\R/', $input);
        if (strlen($input) > 4000 || count($rows) > 30) throw new DomainException(__('Enter at most 30 lines.', 'sfm4u-wholesale'));
        return array_map(static function (string $row) use ($width): array {
            $line = array_map('trim', explode(',', $row));
            if (count($line) !== $width) throw new DomainException(__('Check the columns in each line.', 'sfm4u-wholesale'));
            return $line;
        }, $rows);
    }

    private function nonce(string $page): void
    {
        wp_nonce_field('sfm4u_vendor_' . $page, 'nonce', false);
    }

    private function hidden(string $name, string $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private function input(string $name, string $id, string $label, string $value, string $type): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html(__($label, 'sfm4u-wholesale')) . '</label><br><input required type="' . esc_attr($type)
            . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></p>';
    }

    private function textarea(string $name, string $id, string $label, string $value): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html(__($label, 'sfm4u-wholesale')) . '</label><br><textarea rows="4" maxlength="4000"'
            . ' id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></p>';
    }
}
