<?php
declare(strict_types=1);

define('WP_CLI', true);
function home_url(): string { return 'http://127.0.0.1'; }
function wp_parse_url(string $url, int $part): string { return parse_url($url, $part); }
function get_woocommerce_currency(): string { return 'BDT'; }
function wc_get_price_decimals(): int { return 2; }
function wc_tax_enabled(): bool { return false; }
function get_option(string $name, string $default): string { return $name === 'sfmwh_enabled' ? 'yes' : $default; }
function wc_get_product(int $id): ?WC_Product { return $GLOBALS['products'][$id] ?? null; }
function __(string $value, string $domain = ''): string { return $value; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $value, string $domain = ''): string { return esc_html($value); }

final class WC_Product
{
    public function __construct(private int $id, public string $price, private array $rule, private int $merchant = 7) {}
    public function get_status(): string { return 'publish'; }
    public function is_type(string $type): bool { return $type === 'simple'; }
    public function managing_stock(): bool { return true; }
    public function backorders_allowed(): bool { return false; }
    public function get_meta(string $key, bool $single = true, string $context = ''): array|int
    {
        return $key === '_sfm4u_pos_merchant_id' ? $this->merchant : $this->rule;
    }
    public function get_price(string $context): string { return $this->price; }
    public function get_name(): string { return 'Book <' . $this->id . '>'; }
    public function get_sku(): string { return 'BOOK-' . $this->id; }
}

$root = dirname(__DIR__);
require "$root/plugin/sfm4u-wholesale/src/Rule.php";
require "$root/plugin/sfm4u-wholesale/src/Commerce.php";
require "$root/modules/b2b-pro/src/Money.php";
require "$root/modules/pos/src/Runtime.php";

use SFM4U\POS\{CounterSale, InvoiceTemplate};
function counter_check(bool $valid, string $label): void
{
    if (!$valid) throw new RuntimeException($label);
    echo "PASS $label\n";
}

$rule = ['minor' => 700, 'min' => 2, 'tiers' => [4 => 600], 'currency' => 'BDT', 'scale' => 2];
$products = [2 => new WC_Product(2, '12.25', $rule), 3 => new WC_Product(3, '20.05', $rule), 4 => new WC_Product(4, '10', [], 9)];
$items = [['product' => 3, 'quantity' => 1], ['product' => 2, 'quantity' => 2]];
$intent = CounterSale::intent($items, 'retail', 101, 'cash');
$quote = CounterSale::quote(7, $intent);
counter_check($quote['subtotal_minor'] === 4455 && $quote['total_minor'] === 4400 && $quote['rounding_minor'] === 46
    && array_sum(array_column($quote['lines'], 'total_minor')) === 4354, 'Retail cart allocates discount and rounds cash exactly');
counter_check(CounterSale::quote(7, CounterSale::intent(array_reverse($items), 'retail', 101, 'cash'))['digest'] === $quote['digest'],
    'Cart order does not change the confirmation digest');
$products[2]->price = '12.50';
counter_check(CounterSale::quote(7, $intent)['digest'] !== $quote['digest'], 'Changed server price invalidates an offline confirmation');
$wholesale = CounterSale::quote(7, CounterSale::intent([['product' => 2, 'quantity' => 4]], 'wholesale', 0, 'bkash'));
counter_check($wholesale['total_minor'] === 2400 && $wholesale['rounding_minor'] === 0, 'Wholesale quantity tier and non-cash total use stored rules');
foreach ([
    fn() => CounterSale::intent([$items[0], $items[0]], 'retail', 0, 'cash'),
    fn() => CounterSale::intent([$items[0]], 'retail', 0, 'unknown'),
    fn() => CounterSale::quote(7, CounterSale::intent([$items[0]], 'wholesale', 0, 'card')),
    fn() => CounterSale::quote(7, CounterSale::intent([['product' => 4, 'quantity' => 1]], 'retail', 0, 'card')),
] as $invalid) {
    try { $invalid(); counter_check(false, 'invalid cart accepted'); }
    catch (DomainException) { counter_check(true, 'Counter rejects invalid scope, quantity, method or duplicate product'); }
}
$sale = $quote + ['currency' => 'BDT', 'vat_minor' => 0, 'method_label' => 'nagad', 'tender_type' => 'manual_mfs'];
$html = InvoiceTemplate::render(['sale' => $sale, 'merchant' => 7, 'number' => 'R1-1', 'type' => 'sale'], false);
counter_check(str_contains($html, 'Book &lt;2&gt;') && str_contains($html, 'Book &lt;3&gt;') && str_contains($html, 'Nagad'),
    'Committed receipt renders both escaped lines and the payment label');
counter_check(str_contains(InvoiceTemplate::styles('thermal58'), '58mm') && str_contains(InvoiceTemplate::styles('a4'), 'size:A4'),
    'Counter receipt has separate 58 mm and A4 print rules');

function current_user_can(string $capability): bool { return $GLOBALS['cashier'] && $capability === 'sfm4u_pos_cashier'; }
function get_current_user_id(): int { return $GLOBALS['cashier'] ? 2 : 0; }
function get_user_meta(int $id, string $key, bool $single): array { return [7 => ['R1' => 'C1']]; }
function esc_attr(string $text): string { return esc_html($text); }

$cashier = false;
ob_start();
\SFM4U\POS\CounterPage::render();
counter_check(ob_get_clean() === '', 'Counter view is hidden without cashier permission');
$cashier = true;
ob_start();
\SFM4U\POS\CounterPage::render();
$page = ob_get_clean();
counter_check(str_contains($page, 'value="7:R1"') && str_contains($page, 'id="sfc-term"')
    && str_contains($page, 'value="thermal58"') && str_contains($page, 'value="nagad"')
    && str_contains($page, 'value="day"') && !str_contains($page, 'id="sfc-approval"'),
    'Limited cashier view includes scanner, assigned register, methods and reports');
