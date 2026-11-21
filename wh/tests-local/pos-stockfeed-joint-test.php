<?php
// Joint C-08 + L-01 (POS staging, after counter-member-confirm.php ran): every member counter order now carries the
// member user id and is recognised by StockFeed as a POS member sale; retail counter orders are not. No network.
use SFM4U\Wholesale\Ops\StockFeed;
echo (function () {
    $orders = wc_get_orders(['limit' => 50, 'created_via' => 'sfm4u-pos-fixture', 'orderby' => 'ID', 'order' => 'ASC']);
    $member = array_filter($orders, fn($o) => $o->get_meta('_sfmwh_member_source') === 'pos');
    $retail = array_filter($orders, fn($o) => $o->get_meta('_sfmwh_member_source') !== 'pos');
    $out = [];
    $out[] = (count($member) >= 2 ? 'PASS' : 'FAIL') . ' member counter orders exist: ' . count($member);
    $okMeta = array_reduce($member, fn($c, $o) => $c && (int) $o->get_meta('_sfmwh_member_user') > 0 && StockFeed::isPosMemberSale($o) && StockFeed::memberUser($o) === (int) $o->get_meta('_sfmwh_member_user'), true);
    $out[] = ($okMeta ? 'PASS' : 'FAIL') . ' every member counter order has the member user id and is a StockFeed POS member sale';
    $okRetail = array_reduce($retail, fn($c, $o) => $c && !StockFeed::isPosMemberSale($o), true);
    $out[] = (count($retail) >= 1 && $okRetail ? 'PASS' : 'FAIL') . ' retail counter orders are not member sales: ' . count($retail);
    return implode("\n", $out) . "\n";
})();
