<?php
declare(strict_types=1);
namespace SFM4U\POS;

use DomainException;
use SFM4U\Wholesale\Pro\Money;
use SFM4U\Wholesale\Rule;

/** Authenticated cash register; the same authorization boundary as the REST route. */
final class RegisterPage
{
    private const ACTIONS = ['search'=>'Find product', 'profit'=>'Preview profit', 'park'=>'Park basket', 'resume'=>'Resume basket', 'status' => 'Drawer status', 'xreport' => 'X-report', 'open' => 'Open drawer', 'sale' => 'Cash sale',
        'credit' => 'Credit note', 'invoice' => 'Print invoice', 'thermal'=>'Print 80mm receipt', 'reprint' => 'Receipt copy', 'in' => 'Cash in', 'out' => 'Cash out', 'count'=>'Mid-shift count', 'close' => 'Close drawer'];
    private const FIELDS = ['search'=>'SKU, product name or barcode', 'basket'=>'Parked basket key', 'merchant' => 'Merchant ID', 'register' => 'Register', 'session' => 'Session ID', 'product' => 'Product ID',
        'quantity' => 'Quantity', 'tender' => 'Tender amount (BDT)', 'order' => 'Order ID', 'amount' => 'Drawer amount (BDT)',
        'tender_reference'=>'Manual tender reference', 'reason' => 'Reason code', 'approval' => 'Manager approval reference', 'expense_memo' => 'Expense memo'];

    public function __construct(private AuthorizedRegister $service) {}

    public function register(): void
    {
        add_filter('woocommerce_prevent_admin_access', static function (bool $prevent): bool {
            return current_user_can('sfm4u_pos_cashier') && is_string($_GET['page'] ?? null) && $_GET['page'] === 'sfm4u-pos' ? false : $prevent;
        });
        add_action('admin_menu', function (): void {
            add_menu_page(__('POS register', 'sfm4u-wholesale'), __('POS register', 'sfm4u-wholesale'),
                'sfm4u_pos_cashier', 'sfm4u-pos', [$this, 'render'], 'dashicons-store', 57);
        });
    }

    public function render(): void
    {
        if (!current_user_can('sfm4u_pos_cashier')) return;
        nocache_headers();
        $key = wp_generate_uuid4();
        echo '<div class="wrap"><h1>' . esc_html__('POS register', 'sfm4u-wholesale') . '</h1>';
        echo '<p>' . esc_html__('POS supports BDT untaxed simple products and whole units. Manual card/mobile records remain unverified and do not change cash; no transfer is initiated. Use WooCommerce checkout for taxes.', 'sfm4u-wholesale') . '</p>';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sfm4u_pos_register');
            $key = self::input('request_key');
            try {
                $result = $this->submit();
                $this->receipt($result);
                $key = ($result['kind']??'')==='BASKET'?$result['key']:wp_generate_uuid4();
                if(($result['kind']??'')==='BASKET'){$_POST['product']=(string)$result['product'];$_POST['quantity']=(string)$result['quantity'];$_POST['basket']=$result['key'];$_POST['register_action']='sale';}
                if(($result['kind']??'')==='SEARCH' && count($result['products'])===1){$_POST['product']=(string)$result['products'][0]['product'];$_POST['quantity']=(string)$result['products'][0]['quantity'];$_POST['register_action']='sale';}
            } catch (\Throwable) {
                echo '<p role="alert">' . esc_html__('Check the request and your register assignment. Retry with the same key.', 'sfm4u-wholesale') . '</p>';
            }
        }
        echo '<form method="post">';
        wp_nonce_field('sfm4u_pos_register');
        echo '<input type="hidden" name="request_key" value="' . esc_attr($key) . '">';
        echo '<p><label>' . esc_html__('Action', 'sfm4u-wholesale') . ' <select name="register_action">';
        foreach (self::ACTIONS as $action => $label) {
            if ($action === 'credit' && !current_user_can('sfm4u_pos_credit')) continue;
            if ($action === 'profit' && !current_user_can('manage_woocommerce')) continue;
            echo '<option value="' . esc_attr($action) . '" ' . selected(self::input('register_action'), $action, false) . '>'
                . esc_html(__($label, 'sfm4u-wholesale')) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>'.esc_html__('Tender type','sfm4u-wholesale').' <select name="tender_type">';
        foreach(['cash'=>'Cash','manual_card'=>'Card (manual record)','manual_mfs'=>'Mobile financial service (manual record)'] as $type=>$label)echo '<option value="'.esc_attr($type).'" '.selected(self::input('tender_type'),$type,false).'>'.esc_html(__($label,'sfm4u-wholesale')).'</option>';
        echo '</select></label></p>';
        foreach (self::FIELDS as $name => $label) {
            if ($name === 'reason') {
                echo '<p><label>' . esc_html__('Reason', 'sfm4u-wholesale') . ' <select name="reason">';
                foreach (['' => 'Choose reason', 'change_float' => 'Change float', 'cash_transfer_in' => 'Cash transfer in',
                    'bank_drop' => 'Bank deposit', 'cash_transfer_out' => 'Cash transfer out', 'operating_expense' => 'Operating expense'] as $code => $reason) {
                    echo '<option value="' . esc_attr($code) . '" ' . selected(self::input('reason'), $code, false) . '>'
                        . esc_html(__($reason, 'sfm4u-wholesale')) . '</option>';
                }
                echo '</select></label></p>';
                continue;
            }
            echo '<p><label for="pos-' . esc_attr($name) . '">' . esc_html(__($label, 'sfm4u-wholesale')) . '</label><br>'
                . '<input id="pos-' . esc_attr($name) . '" name="' . esc_attr($name) . '" maxlength="250" value="'
                . esc_attr(self::input($name)) . '"' . (in_array($name, ['merchant', 'register'], true) ? ' required' : '') . '></p>';
        }
        echo '<p><label><input type="checkbox" name="resellable" value="1" '
            . checked(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || self::input('resellable') === '1', true, false) . '> '
            . esc_html__('Return items to saleable stock', 'sfm4u-wholesale') . '</label></p>';
        if (current_user_can('manage_woocommerce')) {
            echo '<p><label>' . esc_html__('Your retail price per unit (BDT)', 'sfm4u-wholesale')
                . ' <input name="profit_override" type="number" min="0" max="10000000" step="0.01" value="'
                . esc_attr(self::input('profit_override')) . '"></label></p>';
            echo '<p><label><input type="checkbox" name="save_profit_override" value="yes"> '
                . esc_html__('Save retail estimate (blank resets it)', 'sfm4u-wholesale') . '</label></p>';
        }
        submit_button(__('Submit register action', 'sfm4u-wholesale'));
        echo '</form></div>';
    }

    public function submit(): array
    {
        // Nonce is enforced here as well so direct handler calls cannot bypass it.
        if (!current_user_can('sfm4u_pos_cashier') || !wp_verify_nonce(self::input('_wpnonce'), 'sfm4u_pos_register')) {
            throw new DomainException('Cashier permission required.', 403);
        }
        $merchant = Rule::number(self::input('merchant'), 1, 9999999999);
        $register = self::input('register'); $action = self::input('register_action'); $key = self::input('request_key');
        $number = static fn(string $name, int $minimum = 0): int => Rule::number(self::input($name) ?: '0', $minimum, 1000000000);
        $amount = static fn(string $name): int => Money::parse(self::input($name) ?: '0', 'BDT')->minor;
        return match ($action) {
            'profit' => $this->service->profit($merchant, $register, $number('product', 1), $number('quantity', 1),
                self::input('save_profit_override') === 'yes' ? self::input('profit_override') : null),
            'search'=>$this->service->search($merchant,$register,self::input('search')),
            'park'=>$this->service->park($merchant,$register,$key,$number('product',1),$number('quantity',1)),
            'resume'=>$this->service->resume($merchant,$register,self::input('basket')),
            'sale' => $this->service->sale($merchant, $register, $key, $number('product', 1), $number('quantity', 1), $amount('tender'), $number('session', 1),self::input('tender_type')?:'cash',self::input('tender_reference')),
            'credit' => $this->service->credit($merchant, $register, $key, $number('order', 1), $number('quantity', 1), self::input('resellable') === '1', $number('session', 1)),
            'reprint' => $this->service->reprint($merchant, $register, $number('order', 1)),
            'invoice', 'thermal' => $this->service->invoice($merchant, $register, $number('order', 1),$action==='thermal'?'thermal':'a4'),
            'status', 'xreport', 'open', 'in', 'out', 'count', 'close' => $this->service->drawer($merchant, $register, $action, $key,
                $number('session'), $amount('amount'), self::input('reason'), self::input('approval'), self::input('expense_memo')),
            default => throw new DomainException('Invalid register action.', 422),
        };
    }

    private function receipt(array $result): void
    {
        if (($result['kind'] ?? '') === 'PROFIT') {
            \SFM4U\Wholesale\ProfitCard::render($result['rows'], false);
            return;
        }
        if(($result['kind']??'')==='SEARCH' || isset($result['products'])){
            echo '<ul>';foreach($result['products'] as $row)echo '<li>'.esc_html($row['product'].' · '.$row['name'].' · '.$row['sku'].' · '.$row['unit'].' × '.$row['quantity'].' · ').esc_html__('Available quantity','sfm4u-wholesale').': '.esc_html((string)$row['available']).'</li>';echo '</ul>';
        }
        if(($result['kind']??'')==='BASKET')echo '<p>'.esc_html__('Parked basket key','sfm4u-wholesale').': <code>'.esc_html($result['key']).'</code> · '.esc_html__('No stock or price is reserved. Resume using this same key.','sfm4u-wholesale').'</p>';
        if(($result['kind']??'')==='BASKET_USED')echo '<p>'.esc_html__('This basket already has a committed sale.','sfm4u-wholesale').'</p>';
        if (($result['kind'] ?? '') === 'INVOICE') {
            echo InvoiceTemplate::styles($result['paper']??'a4'), wp_kses_post($result['html']);
            return;
        }
        $copy = ($result['kind'] ?? '') === 'COPY';
        $receipt = $result['receipt'] ?? $result;
        echo '<section class="sfm4u-pos-receipt"><h2>' . esc_html__($copy ? 'Receipt copy' : 'Register result', 'sfm4u-wholesale') . '</h2><dl>';
        foreach (['number' => 'Receipt number', 'order_id' => 'Order ID', 'session_id' => 'Session ID',
            'expected_cash_minor' => 'Expected cash (BDT)', 'counted_minor' => 'Counted cash (BDT)', 'variance_minor' => 'Variance (BDT)'] as $field => $label) {
            if (!isset($receipt[$field])) continue;
            $value = str_ends_with($field, '_minor') ? Money::of((int) $receipt[$field], 'BDT')->format() : (string) $receipt[$field];
            echo '<dt>' . esc_html(__($label, 'sfm4u-wholesale')) . '</dt><dd>' . esc_html($value) . '</dd>';
        }
        if (isset($receipt['sale'])) {
            $sale = $receipt['sale'];
            foreach (['name' => 'Product', 'packs' => 'Quantity', 'total_minor' => 'Total (BDT)', 'change_minor' => 'Change (BDT)'] as $field => $label) {
                if (!isset($sale[$field])) continue;
                echo '<dt>' . esc_html(__($label, 'sfm4u-wholesale')) . '</dt><dd>'
                    . esc_html(str_ends_with($field, '_minor') ? Money::of((int) $sale[$field], 'BDT')->format() : (string) $sale[$field]) . '</dd>';
            }
        }
        echo '</dl></section>';
    }

    private static function input(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
    }
}
