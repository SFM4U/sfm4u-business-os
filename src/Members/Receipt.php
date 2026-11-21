<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

defined('ABSPATH') || exit;

/**
 * 80 mm shop receipt for a card purchase (points ledger) or a khata entry.
 * Uses the selling shop's trade-licence name/number, address, phone and a QR code to its
 * policy page (owner decision). Labels in Bangla, numbers in English digits.
 */
final class Receipt {
    /** "P-12" (purchase, points row) or "K-34" (khata row). */
    public static function parseRef(string $ref): ?array {
        return preg_match('/^([PK])-(\d{1,12})$/D', strtoupper(trim($ref)), $m) ? [$m[1], (int) $m[2]] : null;
    }

    public static function url(string $type, int $id): string {
        return add_query_arg(['page' => 'sfm-wr-scan', 'receipt' => $type . '-' . $id], admin_url('admin.php'));
    }

    /** @return array<string,mixed>|null */
    public static function load(string $type, int $id): ?array {
        global $wpdb;
        if ($type === 'P') {
            $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Rewards::points() . " WHERE id=%d AND kind='earn'", $id), ARRAY_A);
            if (!$r) { return null; }
            $store = preg_match('/\[shop: ([a-z0-9-]+)\]/', (string) $r['note'], $m) ? $m[1] : 'main';
            return ['type' => 'P', 'id' => $id, 'user_id' => (int) $r['user_id'], 'store' => $store, 'amount' => (int) $r['purchase_minor'], 'method' => '',
                'points' => (int) $r['points'], 'points_balance' => (int) $r['balance'], 'pool' => (string) $r['pool'],
                'note' => trim((string) preg_replace('/\s*\[shop: [a-z0-9-]+\]/', '', (string) $r['note'])), 'staff' => (int) $r['staff_id'], 'at' => (string) $r['created_at'],
                'khata_balance' => Members::balance((int) $r['user_id'], $store), 'kind' => 'purchase'];
        }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Members::khata() . ' WHERE id=%d', $id), ARRAY_A);
        if (!$r) { return null; }
        return ['type' => 'K', 'id' => $id, 'user_id' => (int) $r['user_id'], 'store' => (string) $r['store'], 'amount' => abs((int) $r['amount_minor']), 'method' => (string) $r['method'],
            'points' => null, 'points_balance' => null, 'pool' => '', 'note' => (string) $r['note'], 'staff' => (int) $r['staff_id'], 'at' => (string) $r['created_at'],
            'khata_balance' => (int) $r['balance_minor'], 'kind' => (string) $r['kind'], 'signed' => (int) $r['amount_minor']];
    }

    public static function html(array $r): string {
        $shop = Members::storeInfo($r['store']);
        $u = get_userdata($r['user_id']);
        $card = Members::bestCardFor($r['user_id'], $r['store']) ?: (Members::cardsOf($r['user_id'])[0] ?? null);
        $nameBn = trim((string) get_user_meta($r['user_id'], 'sfm_wr_name_bn', true));
        $title = ['purchase' => 'ক্রয়ের রসিদ', 'payment' => 'খাতার টাকা জমার রসিদ', 'charge' => 'বাকিতে পণ্যের রসিদ', 'adjust' => 'খাতা সংশোধন'][$r['kind']] ?? 'রসিদ';
        $methods = ['cash' => 'নগদ', 'mobile' => 'বিকাশ / নগদ', 'bank' => 'ব্যাংক', 'online' => 'অনলাইন', 'other' => 'অন্যান্য'];
        $row = static fn(string $k, string $v): string => $v === '' ? '' : '<tr><td>' . esc_html($k) . '</td><td>' . esc_html($v) . '</td></tr>';
        $staff = $r['staff'] ? get_userdata($r['staff']) : null;
        $out = '<div class="sfm-rcpt" lang="bn"><div class="r-h"><b>' . esc_html($shop['licence_name'] !== '' ? $shop['licence_name'] : $shop['name']) . '</b>';
        if ($shop['licence_name'] !== '' && $shop['licence_name'] !== $shop['name']) { $out .= '<div>' . esc_html($shop['name']) . '</div>'; }
        if ($shop['address'] !== '') { $out .= '<div>' . esc_html($shop['address']) . '</div>'; }
        if ($shop['phone'] !== '') { $out .= '<div>ফোন: ' . esc_html($shop['phone']) . '</div>'; }
        if ($shop['licence_no'] !== '') { $out .= '<div>ট্রেড লাইসেন্স: ' . esc_html($shop['licence_no']) . '</div>'; }
        $out .= '</div><div class="r-t">' . esc_html($title) . '</div><table class="r-tb">'
            . $row('রসিদ নং', $r['type'] . '-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT))
            . $row('তারিখ', get_date_from_gmt($r['at'], 'd/m/Y h:i A'))
            . $row('সদস্য', $nameBn !== '' ? $nameBn : ($u ? $u->display_name : ''))
            . $row('কার্ড নং', Members::memberNo($r['user_id']) . ($card ? ' · ' . Members::tierLabel((string) $card['tier']) : ''))
            . '</table><div class="r-amt"><span>' . esc_html($r['kind'] === 'purchase' ? 'মোট কেনা' : 'টাকা') . '</span><b>' . esc_html(Members::money((int) $r['amount'])) . '</b></div><table class="r-tb">'
            . $row('পরিশোধ', $methods[$r['method']] ?? '')
            . ($r['points'] !== null ? $row('পয়েন্ট পেলেন', '+' . $r['points']) . $row('মোট পয়েন্ট', (string) $r['points_balance']) : '')
            . $row('এই দোকানে বাকি', Members::money((int) $r['khata_balance']))
            . $row('নোট', (string) $r['note'])
            . $row('বিক্রেতা', $staff ? $staff->display_name : '')
            . '</table>';
        if ($shop['policy_url'] !== '') {
            $out .= '<div class="r-qr"><div class="sfm-wr-qr" data-qr="' . esc_attr($shop['policy_url']) . '"></div><small>ফেরত ও নীতিমালা দেখতে স্ক্যান করুন</small></div>';
        }
        $out .= '<div class="r-f">SFM4U সদস্য কার্ড · ধন্যবাদ</div></div>';
        return $out;
    }

    public static function css(): string {
        return '.sfm-rcpt{width:72mm;max-width:100%;background:#fff;color:#000;font:12px/1.35 "Hind Siliguri","Noto Sans Bengali",Arial,sans-serif;padding:3mm;box-sizing:border-box;border:1px dashed #999;margin:10px 0}'
            . '.sfm-rcpt .r-h{text-align:center;border-bottom:1px dashed #000;padding-bottom:4px}.sfm-rcpt .r-h b{font-size:15px}.sfm-rcpt .r-t{text-align:center;font-weight:700;margin:6px 0;font-size:13px}'
            . '.sfm-rcpt .r-tb{width:100%;border-collapse:collapse}.sfm-rcpt .r-tb td{padding:1px 0;vertical-align:top}.sfm-rcpt .r-tb td:last-child{text-align:right;font-family:Arial,sans-serif}'
            . '.sfm-rcpt .r-amt{display:flex;justify-content:space-between;border-top:1px dashed #000;border-bottom:1px dashed #000;margin:6px 0;padding:4px 0;font-size:15px}.sfm-rcpt .r-amt b{font-family:Arial,sans-serif}'
            . '.sfm-rcpt .r-qr{text-align:center;margin-top:6px}.sfm-rcpt .sfm-wr-qr{width:26mm;margin:0 auto}.sfm-rcpt .sfm-wr-qr svg{width:100%;height:auto;display:block}.sfm-rcpt .r-qr small{display:block}.sfm-rcpt .r-f{text-align:center;border-top:1px dashed #000;margin-top:6px;padding-top:4px}'
            . '@media print{body *{visibility:hidden}.sfm-rcpt,.sfm-rcpt *{visibility:visible}.sfm-rcpt{position:absolute;left:0;top:0;border:0;margin:0}@page{size:80mm auto;margin:0}}';
    }
}
