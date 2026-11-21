<?php
$vid = (int) file_get_contents('/tmp/stock_vendor.txt');
$pid = wc_get_product_id_by_sku('WH-DAL-1-V' . $vid); $p = wc_get_product($pid);
echo ($p && (int) $p->get_stock_quantity() === 20 && $p->get_status() === 'draft' && (int) get_post_field('post_author', $pid) === $vid) ? "pass receiver stock 20\n" : "FAIL receiver " . ($p ? $p->get_stock_quantity() : 'none') . "\n";
