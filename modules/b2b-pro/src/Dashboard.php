<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

final class Dashboard
{
    private const ENDPOINTS = ['b2b-quotes', 'b2b-lists', 'b2b-credit'];
    private const META = '_sfm4u_b2b_purchase_lists';

    private const STYLES = '
        .sfm4u-b2b article { border: 1px solid #b2c9c0; border-radius: .5rem; padding: 1rem; margin: 1rem 0; overflow-wrap: anywhere }
        .sfm4u-b2b label { display: block; font-weight: 600 }
        .sfm4u-b2b input, .sfm4u-b2b textarea { box-sizing: border-box; width: 100%; max-width: 32rem }
        .sfm4u-b2b textarea { min-height: 6rem; display: block }
        .sfm4u-b2b button { min-height: 44px; margin: .5rem .5rem .25rem 0; white-space: normal }
        .sfm4u-b2b :focus-visible { outline: 3px solid #17645d; outline-offset: 3px }
        .sfm4u-b2b fieldset { margin: 1rem 0; padding: .75rem; min-width: 0 }
        .sfm4u-b2b dl { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .25rem 1rem }
        .sfm4u-b2b dt { overflow-wrap: anywhere }
        .sfm4u-b2b dd { margin: 0; text-align: right }
        @media print {
            body.sfm4u-print-credit * { visibility: hidden }
            .sfm4u-credit-print, .sfm4u-credit-print * { visibility: visible }
            .sfm4u-print-credit .sfm4u-credit-print { position: absolute; top: 0; left: 0; width: 100% }
            .sfm4u-credit-print article { break-inside: avoid }
            .sfm4u-b2b-no-print { display: none }
        }
    ';

    public function __construct(private WooQuotes $quotes, private QuickOrderCsv $quick) {}

    public function register(): void
    {
        add_filter('woocommerce_get_query_vars', static function (array $vars): array {
            foreach (self::ENDPOINTS as $slug) {
                $vars[$slug] = $slug;
            }
            return $vars;
        });
        add_filter('woocommerce_account_menu_items', function (array $items): array {
            if (!$this->buyer()) {
                return $items;
            }
            $logout = array_intersect_key($items, ['customer-logout' => true]);
            $items = array_diff_key($items, $logout);
            foreach (self::ENDPOINTS as $slug) {
                $items[$slug] = $this->pageTitle($slug);
            }
            return $items + $logout;
        });
        foreach (self::ENDPOINTS as $slug) {
            add_action('woocommerce_account_' . $slug . '_endpoint', fn() => $this->render($slug));
        }
        add_action('wp_enqueue_scripts', static function (): void {
            if (is_account_page()) {
                wp_register_style('sfm4u-b2b-account', false, [], '0.2.0');
                wp_enqueue_style('sfm4u-b2b-account');
                wp_add_inline_style('sfm4u-b2b-account', self::STYLES);
            }
        });
        add_filter('body_class', static function (array $classes): array {
            return function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('b2b-credit')
                ? [...$classes, 'sfm4u-print-credit'] : $classes;
        });
        add_action('template_redirect', function (): void {
            if (!is_account_page() || !is_wc_endpoint_url()) {
                return;
            }
            nocache_headers();
            if (is_wc_endpoint_url('b2b-credit') && ($_GET['sfm4u_print'] ?? null) === '1' && $this->buyer()) {
                header('X-Robots-Tag: noindex, nofollow');
                $this->printStatement();
                exit;
            }
        });
    }

    public function render(string $page): void
    {
        $buyer = $this->buyer();
        if (!$buyer || !in_array($page, self::ENDPOINTS, true)) {
            $this->message(__('Sign in with a wholesale buyer account.', 'sfm4u-wholesale'));
            return;
        }
        echo '<section class="sfm4u-b2b' . ($page === 'b2b-credit' ? ' sfm4u-credit-print' : '') . '"><h2>';
        echo esc_html($this->pageTitle($page)) . '</h2>';
        try {
            match ($page) {
                'b2b-quotes' => $this->quotePage($buyer),
                'b2b-lists' => $this->listPage($buyer),
                'b2b-credit' => $this->creditPage($buyer),
            };
        } catch (\DomainException $error) {
            if ($error->getMessage() === 'credit_account_missing') {
                $this->message(__('A credit account has not been assigned.', 'sfm4u-wholesale'));
            } else {
                $this->message(__('Check the request and try again.', 'sfm4u-wholesale'), true);
            }
        } catch (\RuntimeException) {
            $this->message(__('The data could not be loaded.', 'sfm4u-wholesale'), true);
        }
        echo '</section>';
    }

    public function printStatement(): void
    {
        if (!$this->buyer()) {
            return;
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . esc_html($this->pageTitle('b2b-credit')) . '</title><style>'
            . self::STYLES . 'body { max-width: 52rem; margin: 1rem auto; padding: 0 1rem; font: 1rem/1.5 system-ui; color: #172f2c }'
            . '</style></head><body><main>';
        $this->render('b2b-credit');
        echo '</main></body></html>';
    }

    private function changeOffer(int $buyer): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['sfm4u_quote_action'])) {
            return;
        }
        $input = static fn(string $name): string => is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
        $action = $input('sfm4u_quote_action');
        $id = $input('sfm4u_quote_id');
        $key = $input('sfm4u_quote_key');
        $revision = $input('sfm4u_quote_revision');
        if (!wp_is_uuid($id, 4) || !wp_is_uuid($key, 4) || !preg_match('/^[0-9]{1,9}$/D', $revision)
            || !in_array($action, ['accept', 'revise'], true)
            || !wp_verify_nonce($input('sfm4u_quote_nonce'), 'sfm4u_quote_' . $id)) {
            $this->message(__('Refresh the page before responding to the offer.', 'sfm4u-wholesale'), true);
            return;
        }
        $payload = ['expected_revision' => (int) $revision];
        if ($action === 'revise') {
            $note = $input('sfm4u_quote_note');
            if (strlen($note) > 1000) {
                $this->message(__('Keep the revision note within 1000 bytes.', 'sfm4u-wholesale'), true);
                return;
            }
            $payload['note'] = sanitize_textarea_field($note);
        }
        try {
            $this->quotes->change($buyer, 'buyer', $id, $key, $action, $payload);
            $this->message($action === 'accept' ? __('Offer accepted.', 'sfm4u-wholesale')
                : __('Revision requested.', 'sfm4u-wholesale'));
        } catch (\DomainException $error) {
            $this->message($error->getMessage() === 'offer_changed'
                ? __('The vendor sent a new offer. Review it before responding.', 'sfm4u-wholesale')
                : __('This offer could not be updated. Check its current status.', 'sfm4u-wholesale'), true);
        }
    }

    private function quotePage(int $buyer): void
    {
        $this->changeOffer($buyer);
        $states = [
            'submitted' => __('Submitted', 'sfm4u-wholesale'), 'offered' => __('Offered', 'sfm4u-wholesale'),
            'accepted' => __('Accepted', 'sfm4u-wholesale'), 'converted' => __('Order created', 'sfm4u-wholesale'),
            'rejected' => __('Rejected', 'sfm4u-wholesale'), 'expired' => __('Expired', 'sfm4u-wholesale'),
            'cancelled' => __('Cancelled', 'sfm4u-wholesale'), 'draft' => __('Draft', 'sfm4u-wholesale'),
        ];
        $quotes = $this->quotes->forBuyer($buyer);
        if (!$quotes) {
            $this->message(__('You have no quotes yet.', 'sfm4u-wholesale'));
        }
        foreach ($quotes as $quote) {
            echo '<article><h3>' . esc_html($quote['id']) . '</h3><p>';
            $state = $quote['state'] === 'offered' && $quote['valid_until'] <= time() ? 'expired' : $quote['state'];
            echo esc_html($states[$state]);
            echo '</p><ul>';
            foreach ($quote['offer_lines'] ?: $quote['request_lines'] as $line) {
                echo '<li>' . esc_html($line['sku']) . ' × ' . esc_html((string) $line['qty']);
                if (isset($line['unit_price_minor'])) {
                    echo ' · ' . esc_html($this->money($line['unit_price_minor'], $quote['currency']));
                }
                echo '</li>';
            }
            echo '</ul>';
            if ($state === 'offered') {
                echo '<p>' . esc_html(sprintf(__('Offer valid until %s', 'sfm4u-wholesale'),
                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), $quote['valid_until']))) . '</p>';
                $noteId = 'sfm4u-quote-note-' . $quote['id'];
                echo '<form method="post"><fieldset><legend>' . esc_html__('Respond to offer', 'sfm4u-wholesale') . '</legend>';
                wp_nonce_field('sfm4u_quote_' . $quote['id'], 'sfm4u_quote_nonce', false);
                foreach (['id' => $quote['id'], 'revision' => $quote['revision'], 'key' => wp_generate_uuid4()] as $field => $value) {
                    echo '<input type="hidden" name="sfm4u_quote_' . esc_attr($field) . '" value="' . esc_attr((string) $value) . '">';
                }
                echo '<label for="' . esc_attr($noteId) . '">' . esc_html__('Revision note (optional)', 'sfm4u-wholesale') . '</label>'
                    . '<textarea id="' . esc_attr($noteId) . '" name="sfm4u_quote_note" maxlength="1000"></textarea>'
                    . '<button type="submit" name="sfm4u_quote_action" value="accept">' . esc_html__('Accept offer', 'sfm4u-wholesale') . '</button>'
                    . '<button type="submit" name="sfm4u_quote_action" value="revise">' . esc_html__('Request revision', 'sfm4u-wholesale') . '</button>'
                    . '</fieldset></form>';
            }
            if ($quote['order_id']) {
                echo '<p><a href="' . esc_url(wc_get_endpoint_url('view-order', $quote['order_id'], wc_get_page_permalink('myaccount'))) . '">';
                $this->say(__('View order', 'sfm4u-wholesale'));
                echo '</a></p>';
            }
            echo '</article>';
        }
    }

    private function listPage(int $buyer): void
    {
        $stored = get_user_meta($buyer, self::META, true);
        $lists = is_array($stored) ? $stored : [];
        if (isset($_POST['sfm4u_list_action'])) {
            $nonce = sanitize_text_field(wp_unslash((string) ($_POST['sfm4u_list_nonce'] ?? '')));
            if (!wp_verify_nonce($nonce, 'sfm4u_list')) {
                $this->message(__('Refresh the page before submitting.', 'sfm4u-wholesale'), true);
                return;
            }
            $action = sanitize_key(wp_unslash((string) $_POST['sfm4u_list_action']));
            if ($action === 'save') {
                $this->saveList($buyer, $stored, $lists);
            } elseif (in_array($action, ['reorder', 'add_to_cart'], true)) {
                $id = sanitize_key(wp_unslash((string) ($_POST['list'] ?? '')));
                if (!isset($lists[$id])) {
                    throw new \DomainException('list_missing');
                }
                $this->renderReorder($buyer, $lists[$id], $action === 'add_to_cart');
            }
        }
        foreach ($lists as $id => $list) {
            echo '<article><h3>' . esc_html($list['name']) . '</h3><form method="post">';
            wp_nonce_field('sfm4u_list', 'sfm4u_list_nonce', false);
            echo '<input type="hidden" name="list" value="' . esc_attr($id) . '"><button name="sfm4u_list_action" value="reorder">';
            $this->say(__('Check current price and stock', 'sfm4u-wholesale'));
            echo '</button><button name="sfm4u_list_action" value="add_to_cart">';
            $this->say(__('Add list to cart', 'sfm4u-wholesale'));
            echo '</button></form></article>';
        }
        echo '<form method="post"><fieldset><legend>';
        $this->say(__('Save a purchase list', 'sfm4u-wholesale'));
        echo '</legend>';
        wp_nonce_field('sfm4u_list', 'sfm4u_list_nonce', false);
        echo '<label for="sfm4u-list-name">';
        $this->say(__('List name', 'sfm4u-wholesale'));
        echo '</label><input id="sfm4u-list-name" name="list_name" required maxlength="60"><label for="sfm4u-list-items">';
        $this->say(__('One SKU,quantity pair per line. Example: WATER,5', 'sfm4u-wholesale'));
        echo '</label><textarea id="sfm4u-list-items" name="list_items" required maxlength="4000"></textarea><button name="sfm4u_list_action" value="save">';
        $this->say(__('Save list', 'sfm4u-wholesale'));
        echo '</button></fieldset></form>';
    }

    private function saveList(int $buyer, mixed $stored, array &$lists): void
    {
        $name = sanitize_text_field(wp_unslash((string) ($_POST['list_name'] ?? '')));
        $input = wp_unslash((string) ($_POST['list_items'] ?? ''));
        $input = strtr($input, ['০'=>'0', '১'=>'1', '২'=>'2', '৩'=>'3', '৪'=>'4',
            '৫'=>'5', '৬'=>'6', '৭'=>'7', '৮'=>'8', '৯'=>'9']);
        if ($name === '' || strlen($name) > 240 || strlen($input) > 4000 || count($lists) >= 20) {
            throw new \DomainException('list_invalid');
        }
        $id = wp_generate_uuid4();
        $list = new PurchaseList($id, (string) $buyer, $name);
        foreach (preg_split('/\R/', trim($input)) as $line) {
            if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]{0,63}),([1-9][0-9]{0,5})$/D', trim($line), $match)) {
                $this->message(__('Use SKU,quantity on each line.', 'sfm4u-wholesale'), true);
                return;
            }
            $list->set($match[1], (int) $match[2]);
        }
        $updated = $lists + [$id => ['name' => $name, 'items' => $list->items()]];
        $saved = is_array($stored) ? update_user_meta($buyer, self::META, $updated, $stored) : add_user_meta($buyer, self::META, $updated, true);
        if (!$saved) {
            throw new \RuntimeException('list_changed');
        }
        $lists = $updated;
        $this->message(__('Purchase list saved.', 'sfm4u-wholesale'));
    }

    private function renderReorder(int $buyer, array $saved, bool $addToCart): void
    {
        $list = new PurchaseList('reorder', (string) $buyer, $saved['name']);
        foreach ($saved['items'] as $sku => $qty) {
            $list->set((string) $sku, $qty);
        }
        $result = $list->reorder($this->quick);
        if ($addToCart && !$result['errors'] && $result['lines']) {
            $this->addToCart($result['lines']);
            $this->message(__('Purchase list added to your cart.', 'sfm4u-wholesale'));
            return;
        }
        echo '<div role="status"><ul>';
        foreach ($result['lines'] as $line) {
            echo '<li>' . esc_html($line['sku'] . ' × ' . $line['qty_base'] . ' · '
                . $this->money($line['line_total_minor'], $line['currency'])) . '</li>';
        }
        foreach ($result['errors'] as $row) {
            echo '<li>' . esc_html((string) $row['sku']) . ' · ';
            echo esc_html(RestApi::messageForLocale($row['code'], 'en_US'));
            echo '</li>';
        }
        echo '</ul></div>';
    }

    private function addToCart(array $lines): void
    {
        $cart = WC()->cart;
        if (!$cart) {
            throw new \RuntimeException('cart_unavailable');
        }
        $original = $cart->get_cart();
        try {
            foreach ($lines as $line) {
                $product = wc_get_product(wc_get_product_id_by_sku($line['sku']));
                if (!$product) {
                    throw new \DomainException('sku_not_found');
                }
                $variation = $product->is_type('variation') ? $product->get_id() : 0;
                $parent = $variation ? $product->get_parent_id() : $product->get_id();
                $attributes = $variation ? $product->get_variation_attributes() : [];
                $data = [\SFM4U\Wholesale\Commerce::PACK => 'piece'];
                if (!apply_filters('woocommerce_add_to_cart_validation', true, $parent, $line['qty_base'], $variation, $attributes, $data)
                    || !$cart->add_to_cart($parent, $line['qty_base'], $variation, $attributes, $data)) {
                    throw new \DomainException('cart_rejected');
                }
            }
        } catch (\Throwable $error) {
            $cart->set_cart_contents($original);
            $cart->calculate_totals();
            throw $error;
        }
    }

    private function creditPage(int $buyer): void
    {
        $statement = $this->quotes->statement($buyer);
        $currency = $statement['currency'];
        echo '<p class="sfm4u-b2b-no-print">';
        if (($_GET['sfm4u_print'] ?? null) !== '1') {
            $printUrl = add_query_arg('sfm4u_print', '1', wc_get_account_endpoint_url('b2b-credit'));
            echo '<a href="' . esc_url($printUrl) . '">' . esc_html__('Print-friendly statement', 'sfm4u-wholesale') . '</a>';
        } else {
            $this->say(__('Use your browser’s Print menu to print or save a PDF.', 'sfm4u-wholesale'));
        }
        echo '</p><dl>';
        $this->field(__('Terms (days)', 'sfm4u-wholesale'), (string) $statement['net_days']);
        $this->field(__('Credit limit', 'sfm4u-wholesale'), $this->money($statement['credit_limit_minor'], $currency));
        $this->field(__('Open balance', 'sfm4u-wholesale'), $this->money($statement['balance_minor'], $currency));
        $this->field(__('Overdue balance', 'sfm4u-wholesale'), $this->money($statement['overdue_minor'], $currency));
        echo '</dl>';
        foreach ($statement['invoices'] as $invoice) {
            echo '<article><h3>#' . esc_html((string) $invoice['order_id']) . '</h3><dl>';
            $this->field(__('Due', 'sfm4u-wholesale'), wp_date('Y-m-d', $invoice['due_at']));
            $this->field(__('Charged', 'sfm4u-wholesale'), $this->money($invoice['amount_minor'], $currency));
            $this->field(__('Allocated', 'sfm4u-wholesale'), $this->money($invoice['paid_minor'], $currency));
            $this->field(__('Open', 'sfm4u-wholesale'), $this->money($invoice['open_minor'], $currency));
            if ($invoice['overdue']) {
                $this->field(__('Status', 'sfm4u-wholesale'), __('Overdue', 'sfm4u-wholesale'));
            }
            if ($invoice['released_at']) {
                $this->field(__('Released', 'sfm4u-wholesale'), wp_date('Y-m-d', $invoice['released_at']));
            }
            echo '</dl></article>';
        }
        if ($statement['payments']) {
            echo '<h3>' . esc_html(__('Recorded Payments', 'sfm4u-wholesale')) . '</h3><dl>';
            foreach ($statement['payments'] as $payment) {
                $this->field($payment['reference'] . ' · ' . wp_date('Y-m-d', $payment['recorded_at']),
                    $this->money($payment['amount_minor'], $currency));
            }
            echo '</dl>';
        }
    }

    private function buyer(): int
    {
        $buyer = get_current_user_id();
        return $buyer && current_user_can('sfm4u_b2b_buy')
            && get_user_meta($buyer, '_sfmwh_buyer_status', true) !== 'suspended' ? $buyer : 0;
    }

    private function money(int $minor, string $currency): string
    {
        return $currency . ' ' . Money::of($minor, $currency)->format();
    }

    private function pageTitle(string $page): string
    {
        return match ($page) {
            'b2b-quotes' => __('My Quotes', 'sfm4u-wholesale'),
            'b2b-lists' => __('Purchase Lists', 'sfm4u-wholesale'),
            'b2b-credit' => __('Credit Statement', 'sfm4u-wholesale'),
        };
    }

    private function field(string $label, string $value): void
    {
        echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
    }

    private function say(string $label): void
    {
        echo esc_html($label);
    }

    private function message(string $label, bool $error = false): void
    {
        echo $error ? '<p role="alert">' : '<p role="status">';
        $this->say($label);
        echo '</p>';
    }
}
