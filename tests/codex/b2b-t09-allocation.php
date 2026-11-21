<?php
wp_set_current_user(1);
$costShares=\SFM4U\Inventory\LandedCostAllocation::allocate(33,[22=>300,11=>300]);
check($costShares===[11=>17,22=>16], 'T09 value allocation conserves minor units with deterministic largest-remainder ties');
check(\SFM4U\Inventory\LandedCostAllocation::allocate(1000000000,[11=>1000000000000000,22=>3000000000000000])===[11=>250000000,22=>750000000],
    'T09 large quantity-value allocation avoids overflowing intermediate multiplication');
try{\SFM4U\Inventory\LandedCostAllocation::allocate(1,[11=>0]);check(false,'zero weights must fail');}catch(DomainException){check(true,'T09 zero-value basis is rejected instead of inventing an allocation');}
$allocIds=[];
foreach(['A','B'] as $label){$p=new WC_Product_Simple();$p->set_name('Synthetic allocated '.$label);$p->set_regular_price('5.00');$p->set_manage_stock(true);$p->set_stock_quantity(0);$allocIds[]=$p->save();}
[$allocA,$allocB]=$allocIds;
$allocPo=$purchase->issue(wp_generate_uuid4(),'Synthetic allocation supplier',[['product'=>$allocA,'quantity'=>3,'unit_minor'=>100],['product'=>$allocB,'quantity'=>1,'unit_minor'=>300]]);
$allocLines=[['product'=>$allocA,'quantity'=>3],['product'=>$allocB,'quantity'=>1]];$allocKey=wp_generate_uuid4();
$allocated=$purchase->receiveAllocated($allocKey,$allocPo['id'],$allocLines,100,'quantity');
check($allocated===$purchase->receiveAllocated($allocKey,$allocPo['id'],array_reverse($allocLines),100,'quantity')
    && $allocated['receipt'][$allocA]['landed_minor']===75 && $allocated['receipt'][$allocB]['landed_minor']===25
    && $valuation->totals($allocA)['stock_micros']===3750000 && $valuation->totals($allocB)['stock_micros']===3250000,
    'T09 quantity allocation receives both products with exact replay and combined goods plus freight value');
$allocatedSale=wc_create_order(['created_via'=>'checkout']);$allocatedSale->set_currency('BDT');$allocatedSale->add_product(wc_get_product($allocA),1);$allocatedSale->calculate_totals(false);$allocatedSale->update_status('processing');
$lateKey=wp_generate_uuid4();$lateAllocation=$purchase->postAllocatedLateCost($lateKey,$allocPo['id'],$allocated['receipt_id'],20,'value');
check($lateAllocation===$purchase->postAllocatedLateCost($lateKey,$allocPo['id'],$allocated['receipt_id'],20,'value')
    && $lateAllocation['allocation']===[$allocA=>10,$allocB=>10] && $lateAllocation['stock_micros']+$lateAllocation['cogs_adjustment_micros']===200000
    && $lateAllocation['cogs_adjustment_micros']===33333,
    'T09 late value allocation splits remaining inventory and already-sold COGS without losing a micro unit');
$retryPo=$purchase->issue(wp_generate_uuid4(),'Synthetic allocated retry',[['product'=>$allocA,'quantity'=>1,'unit_minor'=>100],['product'=>$allocB,'quantity'=>1,'unit_minor'=>300]]);
$allocationPage=new \SFM4U\Inventory\SupplierOrderPage($purchase);$retryKey=wp_generate_uuid4();$retryRows="$allocA,1,0\n$allocB,1,0";
$_GET=['po'=>(string)$retryPo['id']];$_SERVER['REQUEST_METHOD']='POST';
$_POST=['po_action'=>'receive','po_nonce'=>wp_create_nonce('sfm4u_supplier_order'),'po_key'=>$retryKey,'po_lines'=>$retryRows,'allocation_basis'=>'quantity','landed_total'=>'20'];
$allocFailure=static fn()=>throw new RuntimeException('Synthetic allocated receipt rollback');add_action('sfm4u_synthetic_receipt_before_commit',$allocFailure);
ob_start();$allocationPage->render();$retryHtml=ob_get_clean();remove_action('sfm4u_synthetic_receipt_before_commit',$allocFailure);
check(str_contains($retryHtml,$retryKey) && str_contains($retryHtml,$retryRows) && (int)wc_get_product($allocA)->get_stock_quantity()===2,
    'T09 failed receiving form preserves its original request key and fields after atomic rollback');
ob_start();$allocationPage->render();ob_end_clean();ob_start();$allocationPage->render();ob_end_clean();
check((int)wc_get_product($allocA)->get_stock_quantity()===3 && (int)wc_get_product($allocB)->get_stock_quantity()===2 && $purchase->find($retryPo['id'])['status']==='received',
    'T09 restored receiving form retries the allocated receipt exactly once');
$_POST=[];$_GET=[];$_SERVER['REQUEST_METHOD']='GET';
