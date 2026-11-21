<?php
declare(strict_types=1);
namespace SFM4U\POS;

use DomainException;
use SFM4U\Wholesale\Pro\Money;

/** Print an already committed receipt snapshot; rendering never allocates another serial. */
final class InvoiceTemplate
{
    public static function render(array $receipt, bool $copy=true): string
    {
        $sale=$receipt['sale']??[];$currency=$sale['currency']??'';
        $cart = $sale['lines'] ?? null;
        if (is_array($cart)) {
            $sale['packs'] = 1;
            $sale['unit_minor'] = array_sum(array_column($cart, 'subtotal_minor'));
        }
        foreach(['packs','unit_minor','discount_minor','vat_minor','rounding_minor','total_minor'] as $field){
            if(!is_int($sale[$field]??null))throw new DomainException('Invoice snapshot is incomplete.',409);
            if($field!=='rounding_minor' && $sale[$field]<0)throw new DomainException('Invalid invoice amount.',409);
        }
        if($sale['packs']<1 || $sale['vat_minor']>intdiv(PHP_INT_MAX,10000))throw new DomainException('Invalid invoice quantity or tax.',409);
        $net=$sale['packs']*$sale['unit_minor']-$sale['discount_minor'];
        if(!is_int($net) || $net<0 || $net+$sale['vat_minor']+$sale['rounding_minor']!==$sale['total_minor'])throw new DomainException('Invoice snapshot does not reconcile.',409);
        $format=static fn(int $amount):string=>Money::of($amount,$currency)->format();
        $profile=$sale['merchant_profile']??['name'=>sprintf(__('Merchant #%d','sfm4u-wholesale'),$receipt['merchant']),'address'=>'','bin'=>'','note'=>''];
        $html='<article class="sfm4u-invoice"><header><h2>'.esc_html__(($receipt['type']??'sale')==='credit'?'Credit note':'Invoice','sfm4u-wholesale')
            .($copy?' · '.esc_html__('COPY','sfm4u-wholesale'):'').'</h2><strong>'.esc_html((string)$profile['name']).'</strong><p>'
            .nl2br(esc_html((string)$profile['address'])).'</p></header><dl>';
        foreach(['Invoice number'=>$receipt['number'],'Issued at'=>$sale['issued_at']??'','Merchant BIN'=>$profile['bin'],
            'Buyer'=>$sale['buyer_profile']['name']??__('Walk-in customer','sfm4u-wholesale'),
            'Buyer BIN'=>$sale['buyer_profile']['bin']??'','Cashier'=>$sale['cashier']??'','Original receipt'=>$sale['original_number']??''] as $label=>$value){
            if($value==='')continue;$html.='<dt>'.esc_html(__($label,'sfm4u-wholesale')).'</dt><dd>'.esc_html((string)$value).'</dd>';
        }
        $html.='</dl><table><thead><tr>';
        foreach(['Product','Quantity','Unit price','Net value','VAT amount','Total'] as $label)$html.='<th>'.esc_html(__($label,'sfm4u-wholesale')).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($cart ?? [['name' => $sale['name'] ?? $sale['sku'] ?? '', 'quantity' => $sale['packs'],
            'unit_minor' => $sale['unit_minor'], 'total_minor' => $net]] as $line) {
            $tax = $cart === null ? $sale['vat_minor'] : 0;
            $lineTotal = $cart === null ? $sale['total_minor'] : $line['total_minor'];
            $html .= '<tr><td>' . esc_html($line['name']) . '</td><td>' . esc_html((string) $line['quantity']) . '</td><td>'
                . esc_html($format($line['unit_minor'])) . '</td><td>' . esc_html($format($line['total_minor']))
                . '</td><td>' . esc_html($format($tax)) . '</td><td>' . esc_html($format($lineTotal)) . '</td></tr>';
        }
        $html .= '</tbody></table><dl>';
        $rate=$net>0?\SFM4U\Wholesale\Rule::decimal(intdiv($sale['vat_minor']*10000+intdiv($net,2),$net),2).'%':'—';
        $html.='<dt>'.esc_html__('VAT rate','sfm4u-wholesale').'</dt><dd>'.esc_html($rate).'</dd>';
        foreach(['Discount'=>$sale['discount_minor'],'Cash rounding'=>$sale['rounding_minor'],'VAT amount'=>$sale['vat_minor'],'Total'=>$sale['total_minor'],
            (($sale['tender_type']??'cash')==='cash'?'Cash tender':'Recorded tender amount')=>$sale['tender_minor']??0,'Change'=>$sale['change_minor']??0] as $label=>$amount)$html.='<dt>'.esc_html(__($label,'sfm4u-wholesale')).'</dt><dd>'.esc_html($currency.' '.$format($amount)).'</dd>';
        if (($receipt['type'] ?? 'sale') === 'sale' && isset($sale['previous_due_minor'])) {
            foreach (['Previous due' => 'previous_due_minor', 'Paid toward previous due' => 'due_payment_minor',
                'Sale on credit' => 'credit_minor', 'New due' => 'due_minor', 'Advance' => 'advance_minor'] as $label => $field) {
                $html .= '<dt>' . esc_html(__($label, 'sfm4u-wholesale')) . '</dt><dd>'
                    . esc_html($currency . ' ' . $format($sale[$field])) . '</dd>';
            }
        }
        if (isset($sale['method_label'], CounterSale::METHODS[$sale['method_label']])) {
            $html .= '<dt>' . esc_html__('Payment method', 'sfm4u-wholesale') . '</dt><dd>'
                . esc_html(CounterSale::methodLabel($sale['method_label'])) . '</dd>';
        }
        if(($sale['tender_type']??'cash')!=='cash')$html.='<dt>'.esc_html__('Tender status','sfm4u-wholesale').'</dt><dd>'.esc_html__('Unverified manual record; no transfer initiated.','sfm4u-wholesale').'</dd><dt>'.esc_html__('Manual tender reference','sfm4u-wholesale').'</dt><dd>'.esc_html((string)($sale['tender_reference']??'')).'</dd>';
        $html.='</dl><p>'.nl2br(esc_html((string)$profile['note'])).'</p><p>'.esc_html__('Print from the recorded invoice snapshot. No new payment or stock movement is created.','sfm4u-wholesale').'</p>';
        if(!isset($sale['merchant_profile']))$html.='<p>'.esc_html__('Merchant information was not recorded with this receipt.','sfm4u-wholesale').'</p>';
        return $html.'</article>';
    }

    public static function styles(string $paper = 'a4'): string
    {
        if(!in_array($paper,['a4','thermal','thermal58'],true))throw new DomainException('Invalid invoice paper.',422);
        if ($paper === 'thermal58') return self::styles('thermal') . '<style>.sfm4u-invoice{max-width:52mm;font-size:8pt}.sfm4u-invoice dl{grid-template-columns:22mm 1fr}@media print{@page{size:58mm 200mm;margin:3mm}.sfm4u-invoice tr{break-inside:avoid}}</style>';
        if($paper==='thermal')return self::styles('a4').'<style>.sfm4u-invoice{max-width:72mm;font-size:9pt}.sfm4u-invoice dl{grid-template-columns:27mm 1fr}.sfm4u-invoice th,.sfm4u-invoice td{padding:2px}.sfm4u-invoice th:nth-child(4),.sfm4u-invoice td:nth-child(4),.sfm4u-invoice th:nth-child(5),.sfm4u-invoice td:nth-child(5){display:none}@media print{@page{size:80mm 260mm;margin:4mm}}</style>';
        return '<style>.sfm4u-invoice{box-sizing:border-box;max-width:190mm;margin:auto;background:white;color:black;font:12pt sans-serif;line-height:1.4}.sfm4u-invoice dl{display:grid;grid-template-columns:minmax(100px,1fr) 2fr;gap:4px}.sfm4u-invoice dd{margin:0;overflow-wrap:anywhere}.sfm4u-invoice table{border-collapse:collapse;width:100%;table-layout:fixed}.sfm4u-invoice th,.sfm4u-invoice td{border:1px solid #333;padding:6px;overflow-wrap:anywhere}@media print{@page{size:A4;margin:12mm}body{margin:0!important}body *{visibility:hidden}body *:has(.sfm4u-invoice){position:static!important;margin:0!important;padding:0!important;min-height:0!important;width:auto!important}body *:not(:has(.sfm4u-invoice)):not(.sfm4u-invoice):not(.sfm4u-invoice *){display:none!important}.sfm4u-invoice,.sfm4u-invoice *{visibility:visible}}</style>';
    }
}
