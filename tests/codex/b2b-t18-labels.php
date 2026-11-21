<?php
wp_set_current_user(1);
$labelsPage = new \SFM4U\Inventory\BarcodeLabelsPage();
$labelProduct = wc_get_product($t15Pid);
$labelProduct->set_name('সিন্থেটিক পণ্য / Synthetic label'); $labelProduct->save();
\SFM4U\Wholesale\Commerce::saveRule($labelProduct, ['minor'=>1000,'packs'=>['carton'=>12],'buyer'=>'everyone']);
$eanCode = static function (string $body): string { $sum=0; for($i=0;$i<12;$i++) $sum+=(int)$body[$i]*($i%2===0?1:3); return $body.(string)((10-$sum%10)%10); };
$pieceEan=$eanCode('200000000001'); $cartonEan=$eanCode('200000000002');
$_POST=['sfm4u_barcode_nonce'=>'invalid','sfm4u_barcodes'=>"piece,$pieceEan\ncarton,$cartonEan"];
$labelsPage->saveProduct($labelProduct);
check($labelProduct->get_meta('_sfm4u_label_barcodes')==='', 'T18 barcode metadata rejects invalid nonce');
$_POST['sfm4u_barcode_nonce']=wp_create_nonce('sfm4u_barcodes_'.$t15Pid);
$labelsPage->saveProduct($labelProduct); $labelProduct->save(); $_POST=[];
$labelMovements=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$wpdb->prefix.'sfm4u_inventory_movements'));
$pieceLabel=$labelsPage->labels($t15Pid,'piece'); $cartonLabel=$labelsPage->labels($t15Pid,'carton');
check(str_contains($pieceLabel,$pieceEan) && str_contains($cartonLabel,$cartonEan) && str_contains($cartonLabel,'carton × 12')
    && strlen(\SFM4U\Inventory\Ean13::bits($pieceEan))===95, 'T18 piece and carton labels preserve different validated EAN symbols and pack factors');
check($labelsPage->labels($t15Pid,'piece')===$pieceLabel && $labelMovements===(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$wpdb->prefix.'sfm4u_inventory_movements')),
    'T18 label preview and print retry do not create stock movements or document numbers');
try { \SFM4U\Inventory\Ean13::bits(substr($pieceEan,0,12).(string)(((int)$pieceEan[12]+1)%10)); check(false,'bad EAN must fail'); }
catch (DomainException) { check(true,'T18 incorrect EAN check digit fails before rendering'); }
try { $labelsPage->labels($t15Pid,'carton',101); check(false,'label limit must fail'); }
catch (DomainException) { check(true,'T18 label copy count is bounded'); }
wp_set_current_user($buyer);
try { $labelsPage->labels($t15Pid,'piece'); check(false,'buyer label must fail'); }
catch (DomainException $error) { check($error->getCode()===403,'T18 customers cannot enumerate private product labels'); }
wp_set_current_user(1);
file_put_contents('/fixture-out/t18-labels.html','<!doctype html><html lang="bn"><meta charset="utf-8">'.\SFM4U\Inventory\BarcodeLabelsPage::styles().'<body>'.$pieceLabel.$cartonLabel.'</body></html>');

$skuWithLetters = new WC_Product_Simple();
$skuWithLetters->set_name('সিন্থেটিক পণ্য / Synthetic label');
$skuWithLetters->set_sku('SFM-test-0123');
$skuWithLetters->set_regular_price('23.50');
$skuWithLetters->set_price('23.50');
$skuWithLettersId = $skuWithLetters->save();
$skuProduct = new WC_Product_Simple();
$skuProduct->set_name('Synthetic numerical SKU');
$skuProduct->set_sku('0012345678901234567890123456');
$skuProduct->set_regular_price('41.25');
$skuProduct->set_price('41.25');
$skuId = $skuProduct->save();
$skuIds = [$skuWithLettersId, $skuId];
$skuA4 = $labelsPage->skuLabels($skuIds, 'a4', 9);
$skuThermal = $labelsPage->skuLabels($skuIds, 'thermal', 1);
check(substr_count($skuA4, '<article') === 18 && substr_count($skuThermal, '<article') === 2
    && str_contains($skuThermal, '23.50') && str_contains($skuThermal, '41.25')
    && str_contains($skuThermal, 'সিন্থেটিক পণ্য'), 'SKU sheets include selected names, catalogue prices and requested copies');
check(str_contains($skuThermal, 'viewBox="0 0 198 32"') && str_contains($skuThermal, 'viewBox="0 0 209 32"')
    && substr_count($skuThermal, '<rect x="10"') === 2, 'Code 128 selects B and numeric C with ten-module quiet space');
foreach ([[[$skuWithLettersId, $skuWithLettersId], 'a4', 1], [$skuIds, 'thermal', 51], [$skuIds, 'bad-layout', 1]] as $invalidSkuSheet) {
    try { $labelsPage->skuLabels(...$invalidSkuSheet); check(false, 'Invalid SKU sheet must fail'); }
    catch (DomainException) { check(true, 'SKU sheets reject duplicate selection, excessive total or unknown format'); }
}
try { \SFM4U\Inventory\Code128::svg('অবৈধ', 53); check(false, 'Non-ASCII SKU must fail'); }
catch (DomainException) { check(true, 'Code 128 rejects unsupported SKU input'); }
try { \SFM4U\Inventory\Code128::svg(str_repeat('X', 15), 53); check(false, 'Overwide thermal SKU must fail'); }
catch (DomainException) { check(true, 'Code 128 rejects SKU too wide for thermal size'); }
wp_set_current_user($buyer);
try { $labelsPage->skuLabels($skuIds, 'a4', 1); check(false, 'Buyer SKU preview must fail'); }
catch (DomainException $error) { check($error->getCode() === 403, 'Buyer cannot print private SKU sheets'); }
wp_set_current_user(1);
foreach (['a4' => $skuA4, 'thermal' => $skuThermal] as $format => $sheet) {
    file_put_contents("/fixture-out/t18-sku-$format.html", '<!doctype html><html lang="bn"><meta charset="utf-8">'
        . \SFM4U\Inventory\BarcodeLabelsPage::skuStyles($format) . '<body><main><h1>Admin controls</h1>' . $sheet . '</main></body></html>');
}
