<?php
namespace SFM4U\POS;

use SFM4U\Inventory\RequestJournal;
use DomainException;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/inventory-bridge/src/ManagedOrderStock.php';
require_once __DIR__ . '/Runtime.php';
require_once __DIR__ . '/RegisterProductSearch.php';

/** Local POS service boundary. Never register it as an unauthenticated HTTP route. */
final class AuthorizedRegister {
    public function __construct(
        private RequestJournal $journal,
        private ReceiptRegister $receipts,
        private SyntheticSale $sales,
        private ?RegisterSessions $sessions = null
    ) {}

    private function actor(int $merchant, string $register, bool $credit = false): array {
        if (!Runtime::allowedContext()) {
            throw new DomainException('Synthetic fixture only.',403);
        }
        $id=get_current_user_id();
        if (!$id || !current_user_can('sfm4u_pos_cashier') || ($credit && !current_user_can('sfm4u_pos_credit'))) {
            throw new DomainException('Cashier permission required.',403);
        }
        $assignments=get_user_meta($id,'_sfm4u_pos_registers',true);
        $cashier=is_array($assignments) ? ($assignments[$merchant][$register] ?? null) : null;
        if ($merchant < 1 || !preg_match('/^[A-Z0-9]{1,20}$/D',$register) ||
            !is_string($cashier) || !preg_match('/^[A-Z0-9]{1,20}$/D',$cashier)) {
            throw new DomainException('Cashier is not assigned to this merchant and register.',403);
        }
        return [$id,$cashier];
    }

    public function drawer(int $merchant, string $register, string $action, string $key = '', int $session = 0,
        int $amount = 0, string $reason = '', string $approval = '', string $expenseMemo = ''): array {
        [$actor]=$this->actor($merchant,$register);
        if (!$this->sessions || get_woocommerce_currency() !== 'BDT' || wc_get_price_decimals() !== 2) {
            throw new RuntimeException('Synthetic BDT drawer tracking is unavailable.',503);
        }
        if ($action === 'status') return $this->sessions->status($merchant,$register);
        if ($action === 'day') return $this->sessions->dailyCash($merchant,$register);
        if ($action === 'xreport') return $this->sessions->xReport($merchant,$register);
        $this->journal->requirePinnedConnection();
        $approval=trim($approval);
        $expense = $action === 'out' && $reason === RegisterSessions::EXPENSE_REASON;
        $expenseMemo = trim(sanitize_text_field($expenseMemo));
        if (!in_array($action,['open','in','out','close','count'],true) || $amount < 0 || $amount > 1000000000
            || ($action !== 'open' && $session < 1) || strlen($approval) > 250
            || ($expense && ($expenseMemo === '' || strlen($expenseMemo) > 250))
            || (isset(RegisterSessions::REASONS[$action]) && ($amount === 0 || !in_array($reason,RegisterSessions::REASONS[$action],true)))) {
            throw new DomainException('Check the drawer amount, session and reason code.',422);
        }
        $intent=compact('register','actor','action','session','amount','reason','approval');
        if ($expense) {
            $intent['expense_memo'] = $expenseMemo;
        }
        return $this->journal->execute($merchant,'pos_drawer',$key,$intent,function () use (
            $merchant,$register,$actor,$action,$session,$amount,$reason,$approval,$expense,$expenseMemo
        ) {
            if ($action === 'open') {
                $record=$this->sessions->open($merchant,$register,$actor,$amount);
            } elseif ($action === 'close') {
                $record=$this->sessions->close($merchant,$register,$session,$actor,$amount,$approval);
            } elseif ($action === 'count') {
                $record=$this->sessions->count($merchant,$register,$session,$actor,$amount,$approval);
            } else {
                $this->sessions->assertActive($merchant,$register,$session);
                $record=['session_id'=>$session,'direction'=>$action,'register'=>$register,'amount_minor'=>$amount,
                    'reason'=>$reason,'actor_id'=>$actor];
                if ($expense) {
                    $record['expense_memo'] = $expenseMemo;
                }
            }
            do_action('sfm4u_pos_drawer_before_commit',$action,$record);
            return ['response'=>$record,'movements'=>[]];
        });
    }

    public function search(int $merchant,string $register,string $term):array {
        $this->actor($merchant,$register);
        return ['kind'=>'SEARCH','products'=>RegisterProductSearch::find($merchant,$term)];
    }

    public function profit(int $merchant, string $register, int $id, int $quantity, ?string $override): array {
        $this->actor($merchant, $register);
        if (!current_user_can('manage_woocommerce')) {
            throw new DomainException('Shop manager permission required.', 403);
        }
        $product = wc_get_product($id);
        if (!$product || !RegisterProductSearch::eligible($product, $merchant)) {
            throw new DomainException('Product is outside this merchant.', 403);
        }
        $card = \SFM4U\Wholesale\ProfitCard::class;
        if (!$card::supportedCurrency() || wc_tax_enabled() || $quantity < 1 || $quantity > 10000) {
            throw new DomainException('Invalid retail estimate.', 422);
        }
        $suggestion = $card::minor((string) $product->get_regular_price());
        $current = $card::minor((string) $product->get_price());
        $storedCost = method_exists($product, 'get_cogs_value') ? $product->get_cogs_value() : null;
        $cost = $storedCost === null ? null : $card::minor(wc_format_decimal($storedCost, 2)) * $quantity;
        if ($override !== null) {
            $card::saveOverride($id, $override);
        }
        $row = $card::line($product, $quantity, $cost, $suggestion, $current, max(0, $suggestion - $current) * $quantity);
        return ['kind' => 'PROFIT', 'rows' => [$row]];
    }

    /** A parked single-line basket reserves no stock or price. Its key is also the sale key. */
    public function park(int $merchant,string $register,string $key,int $sku,int $quantity):array {
        [$actor]=$this->actor($merchant,$register);$this->journal->requirePinnedConnection();
        if($quantity<1 || $quantity>10000)throw new DomainException('Invalid basket quantity.',422);
        return $this->journal->execute($merchant,'pos_basket',$key,compact('register','actor','sku','quantity'),function()use($merchant,$register,$key,$actor,$sku,$quantity){
            if($this->journal->committed($merchant,'pos',$key)!==null)throw new DomainException('Basket key already belongs to a sale.',409);
            $product=wc_get_product($sku);
            if(!$product || !$product->is_type('simple') || !$product->managing_stock() || $product->get_status()!=='publish'
                || $product->backorders_allowed() || (int)$product->get_meta('_sfm4u_pos_merchant_id')!==$merchant)throw new DomainException('Product is outside this merchant.',403);
            return ['response'=>['kind'=>'BASKET','key'=>$key,'register'=>$register,'actor'=>$actor,'product'=>$sku,'quantity'=>$quantity],'movements'=>[]];
        });
    }

    public function resume(int $merchant,string $register,string $key):array {
        [$actor]=$this->actor($merchant,$register);
        $draft=$this->journal->committed($merchant,'pos_basket',$key);
        if(!$draft || $draft['register']!==$register || $draft['actor']!==$actor)throw new DomainException('Basket outside this register scope.',404);
        $sale=$this->recover($merchant,$register,$key);
        if($sale!==null)return ['kind'=>'BASKET_USED','receipt'=>$sale];
        $product=wc_get_product($draft['product']);
        if(!$product || (int)$product->get_meta('_sfm4u_pos_merchant_id')!==$merchant)throw new DomainException('Product is outside this merchant.',403);
        return $draft+['products'=>[RegisterProductSearch::row($product,'piece',$draft['quantity'])]];
    }

    public function sale(int $merchant,string $register,string $key,int $sku,int $quantity,int $tender,int $expectedSession=0,string $tenderType='cash',string $tenderReference=''): array {
        [$id,$cashier]=$this->actor($merchant,$register);
        $this->journal->requirePinnedConnection();
        $product=wc_get_product($sku);
        if (!$product || (int)$product->get_meta('_sfm4u_pos_merchant_id')!==$merchant) {
            throw new DomainException('Product is outside this merchant.',403);
        }
        $draft=$this->journal->committed($merchant,'pos_basket',$key);
        if($draft!==null && ($draft['register']!==$register || $draft['actor']!==$id || $draft['product']!==$sku || $draft['quantity']!==$quantity))throw new DomainException('Basket data changed. Use its recorded product, quantity and key.',409);
        return $this->sales->commit($merchant,$register,$key,$sku,$quantity,$tender,$cashier,$id,$this->sessions,$expectedSession,$tenderType,$tenderReference);
    }

    public function counter(int $merchant, string $register, string $key, array $data, bool $commit): array {
        [$actor, $cashier] = $this->actor($merchant, $register);
        if (!is_array($data['lines'] ?? null) || !is_string($data['tier'] ?? null)
            || !is_string($data['method'] ?? null) || !is_int($data['discount'] ?? null)) {
            throw new DomainException('Invalid counter fields.', 422);
        }
        $member = $data['member'] ?? '';
        if (!is_string($member)) {
            throw new DomainException('Invalid counter fields.', 422);
        }
        $intent = CounterSale::intent($data['lines'], $data['tier'], $data['discount'], $data['method'], $member);
        if (!$commit) {
            return CounterSale::quote($merchant, $intent);
        }
        if (!$this->sessions || !is_int($data['session'] ?? null) || !is_int($data['tender'] ?? null)
            || !is_string($data['digest'] ?? null)) {
            throw new DomainException('Invalid counter confirmation.', 422);
        }
        return CounterSale::commit($this->journal, $this->receipts, $this->sessions, $merchant, $register, $key,
            $actor, $cashier, $data['session'], $intent, $data['digest'], $data['tender']);
    }

    public function recover(int $merchant,string $register,string $key,bool $credit=false): ?array {
        [$id]=$this->actor($merchant,$register,$credit);
        $record=$this->journal->committed($merchant,$credit ? 'pos_credit' : 'pos',$key);
        if ($record !== null && ($record['register'] !== $register || ($record['sale']['actor_id'] ?? 0) !== $id)) {
            throw new DomainException('Receipt outside this recovery scope.',404);
        }
        return $record;
    }

    public function reprint(int $merchant,string $register,int $orderId): array {
        $this->actor($merchant,$register);
        return $this->receipts->reprint($merchant,$register,$orderId);
    }

    public function invoice(int $merchant, string $register, int $order, string $paper='a4'): array {
        $this->actor($merchant, $register);
        $styles = InvoiceTemplate::styles($paper);
        return ['kind'=>'INVOICE','paper'=>$paper,'styles'=>$styles,'html'=>InvoiceTemplate::render($this->receipts->read($merchant,$register,$order),true)];
    }

    public function credit(int $merchant,string $register,string $key,int $orderId,int $quantity,bool $resellable=true,int $expectedSession=0): array {
        [$actor,$cashier]=$this->actor($merchant,$register,true);
        $this->journal->requirePinnedConnection();
        if ($orderId<1 || $quantity<1 || $quantity>10000) throw new DomainException('Invalid credit quantity.',422);
        $intent=compact('register','actor','orderId','quantity');
        if (!$resellable) $intent['resellable']=false;
        if ($expectedSession) $intent['session']=$expectedSession;
        $lineId=$productId=0;
        try {
            return $this->journal->execute($merchant,'pos_credit',$key,$intent,
                function () use ($merchant,$register,$actor,$cashier,$orderId,$quantity,$resellable,$expectedSession,&$lineId,&$productId) {
                    $session=$this->sessions?->active($merchant,$register,$expectedSession>0);
                    if ($expectedSession && $session!==$expectedSession) {
                        throw new DomainException('Credit belongs to a different register session.',409);
                    }
                    $original=$this->receipts->read($merchant,$register,$orderId,true);
                    $sold=$original['sale'];
                    if (($original['type'] ?? 'sale') !== 'sale' || ($sold['factor'] ?? 0)!==1
                        || $sold['vat_minor']!==0 || $sold['discount_minor']!==0) {
                        throw new DomainException('This credit fixture supports untaxed piece sales without discounts only.',422);
                    }
                    $order=wc_get_order($orderId);
                    if (!$order || $order->get_currency()!=='BDT') throw new RuntimeException('Original sale unavailable.');
                    if (\SFM4U\Wholesale\Pro\Money::parse((string)$order->get_total(),'BDT')->minor !== $sold['total_minor']) {
                        throw new DomainException('Original order total changed after receipt issuance.',409);
                    }
                    $lines=array_values($order->get_items());
                    if (count($lines)!==1) throw new RuntimeException('Expected a single original sale line.');
                    $line=$lines[0];
                    $returned=abs((int)$order->get_qty_refunded_for_item($line->get_id()));
                    if ($quantity > $sold['packs']-$returned) throw new DomainException('Credit exceeds remaining items.',422);
                    $product=$line->get_product();
                    if (!$product || !$product->managing_stock()) throw new RuntimeException('Product unavailable for restock.');
                    $lineId=$line->get_id();
                    $productId=$product->get_id();
                    $before=(int)$product->get_stock_quantity();
                    $base = $quantity * $sold['unit_minor'];
                    $creditedQuantity = $returned + $quantity;
                    // Cumulative allocation makes all partial refunds sum to the original cash total.
                    $creditedRounding = intdiv($sold['rounding_minor'] * $creditedQuantity, $sold['packs']);
                    $rounding = $creditedRounding - intdiv($sold['rounding_minor'] * $returned, $sold['packs']);
                    $amount = $base + $rounding;
                    $refundLines = [$line->get_id()=>['qty'=>$quantity,'refund_total'=>self::decimal($base),'refund_tax'=>[]]];
                    if ($rounding !== 0) {
                        $orderFees = $order->get_items('fee');
                        $fees = array_filter($orderFees, static fn(\WC_Order_Item_Fee $fee): bool =>
                            $fee->get_tax_status() === 'none'
                            && \SFM4U\Wholesale\Pro\Money::parse((string)$fee->get_total(),'BDT')->minor === $sold['rounding_minor']
                            && ((string)$fee->get_meta('_sfm4u_cash_rounding') === '1' || count($orderFees) === 1));
                        if (count($fees) !== 1) {
                            throw new RuntimeException('Original rounding line unavailable.');
                        }
                        $refundLines[array_key_first($fees)] = ['qty'=>0,'refund_total'=>self::decimal($rounding),'refund_tax'=>[]];
                    }
                    $refund=\SFM4U\Inventory\ManagedOrderStock::refund(['order_id'=>$orderId,'amount'=>self::decimal($amount),'reason'=>$resellable?'Synthetic resellable return':'Synthetic damaged return',
                        'refund_payment'=>false,'restock_items'=>$resellable,
                        'line_items'=>$refundLines]);
                    if (is_wp_error($refund)) throw new RuntimeException('WooCommerce refund failed: '.$refund->get_error_code());
                    if ((int)wc_get_product($product->get_id())->get_stock_quantity()!==$before+($resellable?$quantity:0)) {
                        throw new RuntimeException('Returned stock does not match the credit quantity.');
                    }
                    do_action('sfm4u_inventory_capture_return',$line,$refund->get_id(),$returned,$quantity,$resellable);
                    $snapshot=array_replace($sold,['issued_at'=>gmdate('c'),'actor_id'=>$actor,'cashier'=>$cashier,
                        'packs'=>$quantity,'tender_minor'=>$amount,'total_minor'=>$amount,'change_minor'=>0,'rounding_minor'=>$rounding,
                        'cash_minor'=>0,'original_number'=>$original['number'],'original_order_id'=>$orderId,
                        'session_id'=>$session,
                        'reason'=>$resellable?'Resellable return · credit note recorded':'Damaged return · write-off recorded',
                        'refund_minor'=>$amount,'cash_received_minor'=>0,'cash_refund_minor'=>($sold['tender_type']??'cash')==='cash'?$amount:0,'resellable'=>$resellable,
                        'remaining_minor'=>$sold['total_minor'] - $creditedQuantity * $sold['unit_minor'] - $creditedRounding]);
                    $receipt=$this->receipts->issue($merchant,$register,$refund->get_id(),$snapshot,'credit');
                    do_action('sfm4u_synthetic_credit_before_commit',$receipt);
                    return ['response'=>$receipt,'movements'=>$resellable?[['product_id'=>$product->get_id(),'delta'=>$quantity,
                        'type'=>'return','order_id'=>$refund->get_id()]]:[]];
                });
        } catch (\Throwable $error) {
            if ($productId) ReceiptRegister::clearFailedCreditCaches($orderId,$lineId,$productId);
            throw $error;
        }
    }

    private static function decimal(int $minor): string
    {
        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', abs(intdiv($minor, 100)), abs($minor % 100));
    }
}
