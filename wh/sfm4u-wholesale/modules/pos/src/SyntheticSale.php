<?php
namespace SFM4U\POS;

use SFM4U\Inventory\RequestJournal;
use DomainException;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/inventory-bridge/src/ManagedOrderStock.php';
require_once __DIR__ . '/Runtime.php';

/** Legacy-named POS service; web access is opt-in and owned by AuthorizedRegister. */
final class SyntheticSale {
    public const CASH_ROUNDING_OPTION = 'sfm4u_pos_cash_rounding_minor';

    public function __construct(private RequestJournal $journal, private ReceiptRegister $receipts) {}

    public function commit(int $merchant, string $register, string $key, int $sku, int $quantity, int $tender, string $cashier, int $actor = 0, ?RegisterSessions $sessions = null, int $expectedSession = 0, string $tenderType = 'cash', string $tenderReference = ''): array {
        if (!Runtime::allowedContext()) {
            throw new RuntimeException('Synthetic CLI fixture required.');
        }
        if ($quantity < 1 || $quantity > 10000 || $tender < 0 || $tender > 1000000000
            || !preg_match('/^[A-Z0-9]{1,20}$/D', $cashier)
            || !preg_match('/^[A-Z0-9]{1,20}$/D', $register) || get_woocommerce_currency() !== 'BDT'
            || wc_get_price_decimals() !== 2 || wc_tax_enabled()) {
            throw new DomainException('Invalid synthetic BDT cash sale.', 422);
        }
        $tenderReference=trim(sanitize_text_field($tenderReference));
        if(!in_array($tenderType,['cash','manual_card','manual_mfs'],true) || strlen($tenderReference)>128
            || ($tenderType!=='cash' && $tenderReference==='') || ($tenderType==='cash' && $tenderReference!=='')) {
            throw new DomainException('Choose a supported tender and a manual reference.',422);
        }
        $intent = compact('register','sku','quantity','tender','cashier');
        if($tenderType!=='cash')$intent+=['tender_type'=>$tenderType,'tender_reference'=>$tenderReference];
        if ($actor > 0) $intent['actor'] = $actor;
        if ($expectedSession > 0) $intent['session'] = $expectedSession;
        return $this->journal->execute($merchant, 'pos', $key, $intent,
            function () use ($merchant, $register, $sku, $quantity, $tender, $cashier, $actor, $sessions, $expectedSession, $tenderType, $tenderReference) {
                $session = $sessions?->active($merchant, $register, $expectedSession > 0);
                if ($expectedSession && $session !== $expectedSession) {
                    throw new DomainException('Sale belongs to a different register session.', 409);
                }
                $product = wc_get_product($sku);
                if (!$product || !$product->is_type('simple') || !$product->managing_stock()
                    || !$product->is_in_stock() || $product->backorders_allowed()) {
                    throw new DomainException('Synthetic POS SKU requires managed stock.', 422);
                }
                $price = (string) $product->get_price();
                if (!preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $price, $parts)) {
                    throw new DomainException('Invalid server price precision.', 422);
                }
                $unit = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0', STR_PAD_RIGHT);
                $base = $unit * $quantity;
                $increment = get_option(self::CASH_ROUNDING_OPTION, '100');
                if ((!is_string($increment) && !is_int($increment))
                    || !in_array((string) $increment, ['1', '5', '10', '25', '50', '100'], true)) {
                    throw new DomainException('Invalid cash rounding denomination.', 422);
                }
                $increment = (int) $increment;
                $rounding = $tenderType==='cash'?intdiv($base + intdiv($increment, 2), $increment) * $increment - $base:0;
                if($tenderType!=='cash' && $tender!==$base)throw new DomainException('Manual tender amount must equal the recorded total.',422);
                if ($tender < $base + $rounding) {
                    throw new DomainException('Tender is below the cash amount due.', 422);
                }
                $order = wc_create_order(['created_via'=>'sfm4u-pos-fixture']);
                if (is_wp_error($order)) {
                    throw new RuntimeException('Synthetic Woo order could not be created.');
                }
                $order->set_currency('BDT');
                if($tenderType!=='cash'){
                    $order->set_payment_method('sfm4u_'.$tenderType);
                    $order->set_payment_method_title(__('Manual tender recorded','sfm4u-wholesale'));
                }
                $order->add_product($product, $quantity);
                if ($rounding !== 0) {
                    $fee = new \WC_Order_Item_Fee();
                    $fee->set_name(__('Cash rounding', 'sfm4u-wholesale'));
                    $fee->add_meta_data('_sfm4u_cash_rounding', 1, true);
                    $fee->set_tax_status('none');
                    $fee->set_total(wc_format_decimal($rounding / 100, 2));
                    $order->add_item($fee);
                }
                $order->calculate_totals(false);
                if (wc_format_decimal($order->get_total(), 2) !== wc_format_decimal(($base + $rounding) / 100, 2)) {
                    throw new RuntimeException('Woo total differs from the synthetic cash receipt.');
                }
                wc_reserve_stock_for_order($order);
                $previousStock = (int) wc_get_product($sku)->get_stock_quantity();
                \SFM4U\Inventory\ManagedOrderStock::reduce($order, static fn() => $order->update_status($tenderType==='cash'?'processing':'on-hold'));
                wc_release_stock_for_order($order);
                if ((int) wc_get_product($sku)->get_stock_quantity() !== $previousStock - $quantity) {
                    throw new RuntimeException('Woo did not commit the expected stock deduction.');
                }
                do_action('sfm4u_inventory_capture_sale', $order);
                $sale = ['merchant_profile'=>InvoiceProfilePage::profile($merchant),'currency'=>'BDT','issued_at'=>gmdate('c'),'sku'=>$product->get_sku(),
                    'name'=>$product->get_name(),'cashier'=>$cashier,'actor_id'=>$actor,'packs'=>$quantity,'factor'=>1,
                    'unit_minor'=>$unit,'discount_minor'=>0,'vat_minor'=>0,'rounding_minor'=>$rounding,
                    'tender_minor'=>$tender,'total_minor'=>$base+$rounding,'change_minor'=>$tender-$base-$rounding,
                    'cash_received_minor'=>$tenderType==='cash'?$base+$rounding:0,'tender_type'=>$tenderType,
                    'tender_reference'=>$tenderReference,'tender_status'=>$tenderType==='cash'?'recorded_cash':'recorded_unverified'];
                if ($session !== null) $sale['session_id'] = $session;
                $receipt = $this->receipts->issue($merchant, $register, $order->get_id(), $sale);
                do_action('sfm4u_pos_synthetic_before_commit', $receipt);
                return ['response'=>$receipt, 'movements'=>[['product_id'=>$sku,'delta'=>-$quantity,
                    'type'=>'sale','order_id'=>$order->get_id()]]];
            });
    }
}
