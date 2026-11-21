<?php
namespace SFM4U\Wholesale\Members;

/**
 * The approved SFM4U Membership Card design (CR80, drawn at 1012 × 638 and scaled).
 * Front: logo, "Membership Card", tier badge, photo, (business name), name, NID, date of birth,
 *        ONE date: when the member reached the current card type.
 * Back:  card number (7 digits) top-right + Code128 barcode + number, name/shop/address/phone,
 *        "SFM4U property" and how to report a lost card (issuing shop's phone).
 * Numbers and "SFM4U" are always English; other text is Bangla or English (customer's choice).
 * NID and date of birth appear only to the card owner or staff printing it — never on public pages.
 */
final class CardView {
    private const T = [
        'bn' => ['father' => 'বাবার নাম', 'photo' => 'ছবি', 'biz' => 'ব্যবসার নাম', 'owner' => 'মালিকের নাম', 'name' => 'নাম', 'nid' => 'NID নম্বর', 'brn' => 'জন্ম নিবন্ধন নম্বর', 'dob' => 'জন্ম তারিখ',
            'since' => 'সদস্য থেকে', 'bsince' => 'বিজনেস মেম্বার থেকে', 'psince' => 'প্রিমিয়াম থেকে', 'caddr' => 'ঠিকানা', 'mother' => 'মায়ের নাম', 'mobile' => 'মোবাইল', 'email' => 'ইমেইল', 'shop' => 'দোকান', 'addr' => 'দোকানের ঠিকানা', 'phone' => 'ফোন', 'cardno' => 'কার্ড নং',
            'prop' => 'এই কার্ড SFM4U-এর সম্পত্তি। অন্য কেউ ব্যবহার করতে পারবে না।', 'lost' => 'হারিয়ে গেলে sfm4u.com-এ জানান, অথবা কার্ড দেওয়া দোকানে ফোন করুন: %s',
            'src' => 'নাম, পিতা-মাতার নাম, জন্ম তারিখ ও ঠিকানা সদস্যের সম্মতিতে তাঁর জাতীয় পরিচয়পত্র থেকে নেওয়া। এটি সরকারি পরিচয়পত্র নয়।'],
        'en' => ['father' => "Father's name", 'photo' => 'Photo', 'biz' => 'Business name', 'owner' => 'Owner name', 'name' => 'Name', 'nid' => 'NID number', 'brn' => 'Birth reg. number', 'dob' => 'Date of birth',
            'since' => 'Member since', 'bsince' => 'Business member since', 'psince' => 'Premium since', 'caddr' => 'Address', 'mother' => "Mother's name", 'mobile' => 'Mobile', 'email' => 'Email', 'shop' => 'Shop', 'addr' => 'Shop address', 'phone' => 'Phone', 'cardno' => 'Card No',
            'prop' => 'This card is SFM4U property and cannot be used by anyone else.', 'lost' => 'If lost, report at sfm4u.com or call the shop that issued it: %s',
            'src' => 'Name, father\'s name, date of birth and address were taken from the member\'s national ID card with their consent. This is not a government ID.'],
    ];
    private const BADGE = ['black' => 'BUSINESS BLACK', 'business' => 'BUSINESS MEMBER', 'premium' => 'PREMIUM MEMBER', 'regular' => 'MEMBER'];
    private const BADGE_BN = ['black' => 'বিজনেস ব্ল্যাক', 'business' => 'বিজনেস মেম্বার', 'premium' => 'প্রিমিয়াম মেম্বার', 'regular' => 'সদস্য'];

    private static function asset(string $file): string {
        return plugins_url('assets/' . $file, dirname(__DIR__, 2) . '/sfm4u-wholesale.php');
    }

    private static function e(string $s): string { return esc_html($s); }

    /** Group digits for reading: 10-digit NID 286 502 3705 (owner: 3-3-4); 13 digits 3-3-3-4; 17 digits (old NID / birth registration) 4-4-4-5. */
    public static function groupDigits(string $d): string {
        $sizes = ['10' => [3, 3, 4], '13' => [3, 3, 3, 4], '17' => [4, 4, 4, 5]][(string) strlen($d)] ?? null;
        if (!$sizes) { return trim(implode(' ', str_split($d, 4))); }
        $out = [];
        $pos = 0;
        foreach ($sizes as $n) { $out[] = substr($d, $pos, $n); $pos += $n; }
        return implode(' ', $out);
    }

    /** The single date shown: when the member reached this card type (regular: account opened). */
    public static function sinceDate(array $card, \WP_User $u): string {
        $src = $card['tier'] === 'regular' ? $u->user_registered : $card['created_at'];
        return get_date_from_gmt($src, 'm/Y');
    }

    /** Front text is always English; back text is always Bangla (owner decision 2026-11-20). $lang is kept for old links and ignored. */
    public static function render(array $card, string $lang, bool $private): string {
        unset($lang);
        $en = self::T['en'];
        $bn = self::T['bn'];
        $demo = isset($card['_demo']) && is_array($card['_demo']) ? $card['_demo'] : null;
        if ($demo) {
            $u = new \WP_User();
            $u->ID = 0;
            $u->display_name = (string) ($demo['display_name'] ?? 'SAMPLE');
            $u->user_email = (string) ($demo['user_email'] ?? '');
            $u->user_registered = (string) ($demo['user_registered'] ?? gmdate('Y-m-d H:i:s'));
        } else {
            $u = get_userdata((int) $card['user_id']);
            if (!$u) { return ''; }
        }
        $tier = $card['tier'];
        $bc = $tier === 'black' ? '#d4af37' : '#22e3c3'; // same design; Business Black uses gold lines
        $meta = static function (string $k) use ($u, $demo): string { return trim($demo ? (string) ($demo[$k] ?? '') : (string) get_user_meta($u->ID, $k, true)); };
        $memberNo = $demo ? (string) ($demo['member_no'] ?? '0000000') : Members::memberNo((int) $card['user_id']);
        $nid = $private ? ($demo ? (string) ($demo['nid'] ?? '') : Members::nationalId($card)) : '';
        $dob = $private ? $meta('sfm_wr_dob') : '';
        $shop = Members::storeInfo((string) ($card['issued_store'] ?: 'main'));
        $district = $demo ? ($demo['district'] ?? null) : BdMap::districtFor($u->ID);
        $sig = $private ? ($demo ? (string) ($demo['signature'] ?? '') : Members::signature($u->ID)) : '';
        $photo = $demo ? (string) ($demo['photo'] ?? '') : Members::photoUrl($card);
        $ghost = $demo ? $photo : (!empty($card['photo_id']) ? Members::photoUrl($card) : '');
        $nameEn = $meta('sfm_wr_name_en') ?: $u->display_name;
        $bizEn = $meta('sfm_wr_business_en') ?: (string) ($card['business_name'] ?? '');
        $fatherEn = $private ? ($meta('sfm_wr_father_en') ?: $meta('sfm_wr_father')) : '';
        $sinceLbl = ['black' => $en['bsince'], 'business' => $en['bsince'], 'premium' => $en['psince'], 'regular' => $en['since']][$tier] ?? $en['since'];
        $item = static function (string $lbl, string $val, int $size = 30, string $cls = 'c-item'): string {
            return '<div class="' . $cls . '"><span>' . esc_html($lbl) . '</span><b style="font-size:' . $size . 'px">' . esc_html($val) . '</b></div>';
        };
        $names = in_array($tier, ['business', 'black'], true) && $bizEn !== ''
            ? '<span class="c-lbl">' . self::e($en['biz']) . '</span><b class="c-big">' . self::e($bizEn) . '</b><span class="c-lbl c-gap">' . self::e($en['owner']) . '</span><b class="c-mid">' . self::e($nameEn) . '</b>'
            : '<span class="c-lbl">' . self::e($en['name']) . '</span><b class="c-big">' . self::e($nameEn) . '</b>';
        $extra = '';
        if ($fatherEn !== '') { $extra .= '<div class="c-sub"><span>' . self::e($en['father']) . '</span><b>' . self::e($fatherEn) . '</b></div>'; }
        if ($dob !== '') { $extra .= '<div class="c-sub"><span>' . self::e($en['dob']) . '</span><b class="c-num">' . self::e(wp_date('d/m/Y', strtotime($dob . ' 12:00:00'))) . '</b></div>'; }
        if ($extra !== '') { $names .= '<div class="c-subs">' . $extra . '</div>'; }
        $nidItem = $nid !== '' ? $item($card['id_type'] === 'brn' ? $en['brn'] : $en['nid'], self::groupDigits($nid)) : '<div></div>';
        $front = '<div class="sfm-cv-card sfm-cv-front" lang="en" style="--bc:' . esc_attr($bc) . '">'
            . '<img class="c-bg" src="' . esc_url(self::asset('sfm4u-logo-wide.jpg')) . '" alt=""><div class="c-shade"></div><div class="c-topbar"></div>'
            . '<div class="c-brand"><img src="' . esc_url(self::asset('sfm4u-logo-round.jpg')) . '" alt="SFM4U"><div><b>SFM<i>4</i>U</b><span>Membership Card <em class="c-no">No. ' . self::e($memberNo) . '</em></span></div></div>'
            . '<div class="c-badge">' . self::e(self::BADGE[$tier] ?? 'MEMBER') . '</div>'
            . ($district ? BdMap::districtOutlineSvg($district, 'c-map', '') : BdMap::countrySvg(null, 'c-map'))
            . '<img class="c-photo" src="' . esc_url($photo) . '" alt="">'
            . ($sig !== '' ? '<img class="c-sign" src="' . esc_attr($sig) . '" alt="">' : '')
            . '<div class="c-names">' . $names . '</div>'
            . '<div class="c-row c-row2">' . $nidItem . $item($sinceLbl, self::sinceDate($card, $u), 26, 'c-item c-right') . '</div>'
            . '</div>';
        $rows = '';
        $row = static function (string $lbl, string $val, string $tag = 'em') use (&$rows): void {
            if ($val !== '') { $rows .= '<span>' . esc_html($lbl) . '</span><' . $tag . '>' . esc_html($val) . '</' . $tag . '>'; }
        };
        $row($bn['name'], $meta('sfm_wr_name_bn') ?: $u->display_name, 'b');
        if ($private) {
            $row($bn['father'], $meta('sfm_wr_father'));
            $row($bn['mother'], $meta('sfm_wr_mother'));
            $row($bn['caddr'], $meta('sfm_wr_address'));
            $row($bn['mobile'], $meta('billing_phone'));
            $row($bn['email'], (string) $u->user_email);
        }
        if (in_array($tier, ['business', 'black'], true)) {
            $row($bn['shop'], $meta('sfm_wr_shop_name') ?: (string) ($card['business_name'] ?? ''), 'b');
            $row($bn['addr'], $meta('sfm_wr_shop_address'));
        }
        $shopPhone = $shop['phone'] !== '' ? $shop['phone'] : 'sfm4u.com';
        $home = BdMap::countrySvg($district, 'c-bdmap', $ghost, true);
        if ($district) { $home .= '<div class="c-dname">' . self::e(BdMap::info($district)['bn']) . '</div>'; }
        $back = '<div class="sfm-cv-card sfm-cv-back" lang="bn" style="--bc:' . esc_attr($bc) . '">'
            . '<img class="c-wm" src="' . esc_url(self::asset('sfm4u-logo-watermark.png')) . '" alt="">'
            . '<div class="c-head"><b>SFM<i>4</i>U · ' . self::e(self::BADGE_BN[$tier] ?? 'সদস্য') . '</b><span><small>' . self::e($bn['cardno']) . ' </small>' . self::e($memberNo) . '</span></div>'
            . '<div class="c-grid">' . $rows . '</div>'
            . '<div class="c-home">' . $home . '</div>'
            . '<div class="c-bar">' . Barcode::code128Svg($memberNo) . '</div><div class="c-barno">' . self::e($memberNo) . '</div>'
            . '<div class="c-note"><p>' . self::e($bn['prop']) . '</p><p>' . self::e(sprintf($bn['lost'], $shopPhone)) . '</p>' . ($nid !== '' ? '<p class="c-src">' . self::e($bn['src']) . '</p>' : '') . '</div><div class="c-botbar"></div>'
            . '</div>';
        return '<div class="sfm-cv"><div class="sfm-cv-scale">' . $front . '</div><div class="sfm-cv-scale">' . $back . '</div></div>';
    }

    public static function css(): string {
        return '.sfm-cv{display:flex;flex-wrap:wrap;gap:16px}.sfm-cv-scale{width:404.8px;max-width:100%;aspect-ratio:1012/638;height:auto;overflow:hidden;border-radius:15px;box-shadow:0 2px 10px rgba(0,0,0,.25)}'
            . '.sfm-cv-card{width:1012px;height:638px;position:relative;overflow:hidden;transform:scale(var(--sfm-s,.4));transform-origin:0 0;font-family:"Hind Siliguri","Noto Sans Bengali",Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.sfm-cv-card b i{font-style:normal;color:#22e3c3}'
            . '.sfm-cv-front{background:#050807;color:#f2f5f4}.c-bg{position:absolute;inset:0;width:1012px;height:638px;object-fit:cover;opacity:.38}'
            . '.c-shade{position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,8,7,.55),rgba(5,8,7,.8) 34%,rgba(5,8,7,.62))}.c-topbar,.c-botbar{position:absolute;left:0;width:1012px;height:10px;background:var(--bc)}.c-topbar{top:0}.c-botbar{bottom:0}'
            . '.c-brand{position:absolute;left:52px;top:34px;display:flex;align-items:center;gap:18px}.c-brand img{width:108px;height:108px;border-radius:50%}.c-brand div{display:flex;flex-direction:column}'
            . '.c-brand b{font:700 44px/1 "Space Grotesk",Arial,sans-serif;letter-spacing:1px}.c-brand span{font:21px/1.3 "Space Grotesk",Arial,sans-serif;color:#b9c6c2;letter-spacing:1px}.c-no{font-style:normal;font-weight:700;color:#22e3c3;letter-spacing:2px;margin-left:10px}'
            . '.c-badge{position:absolute;right:56px;top:52px;padding:10px 22px;border:2px solid var(--bc);border-radius:999px;font:700 22px "Space Grotesk",Arial,sans-serif;letter-spacing:3px;color:var(--bc)}'
            . '.c-photo{position:absolute;left:56px;top:176px;width:230px;height:290px;border-radius:22px;border:3px solid var(--bc);object-fit:cover;background:#e9eeec}'
            . '.c-names{position:absolute;left:330px;top:176px;right:236px;display:flex;flex-direction:column;gap:4px}.c-lbl{font:21px/1.25 "Space Grotesk",Arial,sans-serif;color:#9fb0ab;letter-spacing:.5px}.c-gap{margin-top:10px}.c-big{font:700 46px/1.12 "Space Grotesk",Arial,sans-serif;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
            . '.c-subs{display:flex;flex-direction:column;gap:8px;margin-top:12px}.c-sub:last-child{flex:none}.c-sub{display:flex;flex-direction:column;gap:2px;min-width:0}.c-sub span{font:19px/1.25 "Space Grotesk",Arial,sans-serif;color:#9fb0ab}.c-sub b{font:600 26px/1.25 "Space Grotesk",Arial,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.c-num{font-family:"Space Grotesk",Arial,sans-serif;letter-spacing:1px}.c-map{position:absolute;right:40px;top:122px;width:180px;height:250px}.c-mid{font:600 34px/1.2 "Space Grotesk",Arial,sans-serif}'
            . '.c-row{position:absolute;left:330px;right:56px;display:flex;justify-content:space-between;gap:20px}.c-row2{bottom:44px;align-items:flex-end}.c-right{text-align:right}'
            . '.c-item{display:flex;flex-direction:column;gap:2px}.c-item span{font:19px/1.25 "Space Grotesk",Arial,sans-serif;color:#9fb0ab}.c-item b{font-family:"Space Grotesk",Arial,sans-serif;font-weight:700;letter-spacing:1px}'
            . '.sfm-cv-back{background:#f4f7f6;color:#0b0f0e}.c-wm{position:absolute;left:0;top:17px;width:1012px;height:617px;object-fit:cover;opacity:.34}'
            . '.c-head{position:absolute;left:0;top:0;width:1012px;height:74px;background:#050807;display:flex;align-items:center;justify-content:space-between;box-sizing:border-box;padding:0 56px;color:#f2f5f4}'
            . '.c-head b{font:700 28px "Space Grotesk",Arial,sans-serif;letter-spacing:1px}.c-head span{font:26px "Space Grotesk",Arial,sans-serif;letter-spacing:3px}.c-head small{font-size:22px;color:#b9c6c2;letter-spacing:0;font-family:"Hind Siliguri",Arial,sans-serif}'
            . '.c-grid{position:absolute;left:56px;top:94px;right:262px;display:grid;grid-template-columns:150px 1fr;row-gap:3px;column-gap:16px;font-size:22px;line-height:1.24;max-height:300px;overflow:hidden}.c-grid em,.c-grid b{overflow-wrap:anywhere}.c-home{position:absolute;right:50px;top:92px;width:196px;display:flex;flex-direction:column;align-items:center;gap:4px}.c-bdmap{width:168px;height:232px}.c-flag{width:96px;height:58px;box-shadow:0 1px 4px rgba(0,0,0,.25)}.c-dist{width:170px;height:200px}.c-dname{font-size:24px;font-weight:700;color:#006a4e;line-height:1.2}.c-grid span{color:#4e5b57}.c-grid b{font-weight:600}.c-grid em{font-style:normal}'
            . '.c-bar{position:absolute;left:56px;bottom:112px;width:560px;height:120px;background:#fff;padding:12px 18px;box-sizing:border-box;border-radius:10px}.c-bar svg{width:100%;height:100%;display:block}'
            . '.c-barno{position:absolute;left:56px;bottom:70px;width:560px;text-align:center;font:700 28px "Space Grotesk",Arial,sans-serif;letter-spacing:8px}'
            . '.c-note{position:absolute;right:56px;bottom:64px;width:300px;font-size:18px;line-height:1.35;color:#3b4744}.c-note p{margin:0 0 6px}.c-src{font-size:15px;color:#5d6b67}.c-sign{position:absolute;left:56px;top:478px;width:230px;height:72px;object-fit:contain;filter:invert(1) brightness(1.6)}'
            . '@media print{body *{visibility:hidden}.sfm-cv,.sfm-cv *{visibility:visible}.sfm-cv{position:absolute;left:0;top:0;display:block}'
            . '.sfm-cv-scale{width:85.6mm!important;max-width:none!important;height:54mm!important;aspect-ratio:auto;border-radius:0;box-shadow:none;break-after:page}.sfm-cv-card{transform:scale(.3197)!important}@page{size:85.6mm 54mm;margin:0}}';
    }
}
