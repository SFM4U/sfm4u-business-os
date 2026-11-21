<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

defined('ABSPATH') || exit;

/**
 * Members-only guide: a SAMPLE card with made-up data, what is printed where,
 * how to check a genuine card, how to fill or correct details, and the card rules.
 *
 * The text is NOT hard-wired: every section can be edited in wp-admin (SFM4U Members → Card guide text),
 * one section at a time, one point per line, no code. Saving one section never touches another.
 * "Restore default" brings a section back to the text below. Look and feel: assets/card-guide.css.
 */
final class CardGuide {
    public const OPTION = 'sfm_wr_guide_text'; // section key => ['title' => string, 'items' => string[]]

    private static function asset(string $file): string {
        return plugins_url('assets/' . $file, dirname(__DIR__, 2) . '/sfm4u-wholesale.php');
    }

    /** Default text (Bangla). Edit in wp-admin instead of here. */
    public static function defaults(): array {
        return [
            'sample' => ['title' => 'নমুনা কার্ড', 'items' => ['নিচের কার্ডটি শুধু নমুনা। এতে কোনো আসল মানুষের তথ্য নেই।']],
            'plans' => ['title' => 'চার ধরনের কার্ড', 'items' => [
                'Regular (সদস্য): ফ্রি। শুধু অনলাইন কার্ড, আপনার অ্যাকাউন্টে থাকে। যেকোনো SFM4U দোকানে কেনাকাটায় পয়েন্ট, আর অনলাইনে কুপন।',
                'Premium: ফ্রি, ছাপানো প্লাস্টিক কার্ড। আমাদের হোলসেল দোকান থেকে কেনাকাটায় পয়েন্ট, আর এক বারে নির্দিষ্ট টাকার বেশি কিনলে বিশেষ উপহার।',
                'Business: AlgiBazar.com-এ দোকান খোলা দোকানদার ও ভেন্ডরদের জন্য। পাইকারি দাম।',
                'Business Black: মাসিক ফি দিয়ে নিতে হয়। আমাদের হোলসেল থেকে কিনলে কেনা মাল নিজে নিজে আপনার AlgiBazar দোকানের স্টকে যোগ হয়; আবার হাতে স্টক তুলতে হয় না।',
            ]],
            'front' => ['title' => 'সামনে (ইংরেজিতে)', 'items' => [
                'SFM4U লোগো, "Membership Card" আর কার্ড নম্বর (৭ অঙ্ক, যেমন No. 0000001)।',
                'কার্ডের ধরন: MEMBER (অনলাইন কার্ড), PREMIUM MEMBER, BUSINESS MEMBER বা BUSINESS BLACK। সব কার্ডের ডিজাইন একই, শুধু এই লেখা আলাদা; BUSINESS BLACK কার্ডে সোনালি রেখা।',
                'সদস্যের ছবি আর ছবির নিচে সদস্যের স্বাক্ষর।',
                'নাম, বাবার নাম, জন্ম তারিখ। বিজনেস কার্ডে উপরে ব্যবসার নাম, নিচে মালিকের নাম।',
                'NID বা জন্ম নিবন্ধন যাচাই হয়েছে কিনা (✓ চিহ্ন; নম্বর কার্ডে ছাপা হয় না), আর কবে থেকে এই ধরনের সদস্য। পেছনে বারকোডের উপরে যে সেবাগুলোর সদস্য তার নাম/লোগো। এটি সরকারি পরিচয়পত্র নয়।',
                'ডান পাশে সদস্যের নিজ জেলার মানচিত্র (চিকন রেখা)।',
            ]],
            'back' => ['title' => 'পেছনে (বাংলায়)', 'items' => [
                'উপরে কার্ডের ধরন আর একই কার্ড নম্বর।',
                'নাম, বাবার নাম, মায়ের নাম, ঠিকানা, মোবাইল, ইমেইল। কার্ড হারালে কেউ যেন ফেরত দিতে পারে।',
                'বাংলাদেশের মানচিত্র: ভেতরে সদস্যের ছবি হালকা করে, নিজ জেলা সবুজ, নিচে জেলার নাম।',
                'বারকোড: এর ভেতরে কার্ড নম্বর থাকে। দোকানে স্ক্যান করলে সদস্যের অ্যাকাউন্ট খোলে।',
                'কার্ড হারালে কী করবেন, আর কার্ড দেওয়া দোকানের ফোন নম্বর।',
            ]],
            'genuine' => ['title' => 'আসল কার্ড কীভাবে চিনবেন', 'items' => [
                'সামনের কার্ড নম্বর আর পেছনের কার্ড নম্বর হুবহু এক হবে।',
                'পেছনের বারকোড স্ক্যান করলে একই নম্বর আসবে, আর SFM4U সিস্টেমে সেই সদস্যের নাম ও ছবি দেখাবে। ছবি না মিললে কার্ডটি আসল নয় বা অন্য কেউ ব্যবহার করছে।',
                'কার্ডের ছবি, পেছনের মানচিত্রের ভেতরের হালকা ছবি আর সিস্টেমের ছবি একই মানুষের হবে।',
                'কার্ড বন্ধ (লক) থাকলে দোকানের স্ক্রিনে তা দেখাবে। লক করা কার্ডে কেনাকাটা, পয়েন্ট বা বাকি চলবে না।',
                'সন্দেহ হলে যেকোনো SFM4U দোকানে কার্ডটি স্ক্যান করে মিলিয়ে নিন। কার্ডে এখন কোনো হলোগ্রাম বা চিপ নেই; আসল-নকল যাচাই হয় সিস্টেমে মিলিয়ে।',
            ]],
            'edit' => ['title' => 'তথ্য দেওয়া ও ভুল ঠিক করা', 'items' => [
                'অ্যাকাউন্টের "My card" পেজে "My card details (as on my NID)" খুলুন। আপনার জাতীয় পরিচয়পত্র বা জন্ম নিবন্ধন দেখে বাংলা ও ইংরেজিতে সব তথ্য লিখুন।',
                'ফোনের ক্যামেরা দিয়ে NID-এর পেছনের বারকোড স্ক্যান করলে ইংরেজি নাম, জন্ম তারিখ আর নম্বর নিজে থেকে বসে যায়। বাংলা নাম, বাবা-মায়ের নাম আর ঠিকানা হাতে লিখতে হয়।',
                'আপনি নিজে দুইবার সেভ করতে পারবেন: প্রথমবার পূরণ, আর একবার ভুল ঠিক করা। এরপর কিছু বদলাতে NID নিয়ে দোকানে যোগাযোগ করুন।',
                'স্বাক্ষর যেকোনো সময় বদলানো যায়: "My signature" খুলে আঙুল দিয়ে সই করুন, পছন্দ না হলে Clear চাপুন, আবার সই করে Save চাপুন।',
                'ছবি নিজেই বদলাতে পারবেন (ক্যামেরা বা গ্যালারি থেকে)। নতুন প্লাস্টিক কার্ড ছাপাতে দোকানে যোগাযোগ করুন; ছাপার খরচ আছে।',
            ]],
            'lost' => ['title' => 'কার্ড হারালে', 'items' => [
                'অ্যাকাউন্টের "My card" পেজে "My card is lost: lock it" চাপুন। সাথে সাথে কার্ড বন্ধ হয়ে যাবে।',
                'sfm4u.com-এ জানান, অথবা কার্ড দেওয়া দোকানে ফোন করুন (নম্বর কার্ডের পেছনে আছে)।',
                'কার্ড আবার চালু শুধু দোকান বা ম্যানেজার করতে পারবে, আপনার পরিচয় যাচাই করে।',
            ]],
            'terms' => ['title' => 'শর্তাবলি ও নিষেধ', 'items' => [
                'এই কার্ড SFM4U-এর সম্পত্তি। এটা শুধু কার্ডধারী সদস্যের জন্য; অন্য কাউকে দেওয়া, বিক্রি করা বা ধার দেওয়া যাবে না।',
                'একজন মানুষ একটাই SFM4U অ্যাকাউন্ট রাখবেন। কার্ডের তথ্য আপনার জাতীয় পরিচয়পত্র বা জন্ম নিবন্ধনের সাথে মিলতে হবে।',
                'ভুল বা অন্যের তথ্য দিলে, বা কার্ডের অপব্যবহার হলে SFM4U কার্ড বন্ধ বা বাতিল করতে পারে।',
                'এটা সরকারি পরিচয়পত্র নয়। সরকারি কাজে পরিচয়ের প্রমাণ হিসেবে এই কার্ড ব্যবহার করা যাবে না।',
                'কার্ড নকল করা, বদলানো বা ভুয়া কার্ড বানানো নিষেধ।',
                'প্রত্যেক দোকানের বাকি (খাতা) সেই দোকানের নিজস্ব হিসাব। পয়েন্টের নিয়ম SFM4U ও দোকান ঠিক করে, আগে জানিয়ে বদলাতে পারে।',
                'কার্ডে টাকা রাখার (ওয়ালেট) সুবিধা এখন চালু নেই। লাইসেন্স বা অনুমোদন পাওয়ার পর জানানো হবে।',
            ]],
            'privacy' => ['title' => 'আপনার তথ্যের গোপনীয়তা', 'items' => [
                'আপনার NID বা জন্ম নিবন্ধন নম্বর তালাবদ্ধ (এনক্রিপ্ট করা) অবস্থায় রাখা হয়। দোকানের স্ক্রিনে শুধু শেষের ৪ অঙ্ক দেখায়।',
                'পুরো নম্বর, জন্ম তারিখ আর ঠিকানা দেখতে পারেন শুধু আপনি (নিজের অ্যাকাউন্টে) আর দোকানের ম্যানেজার। ক্যাশিয়ার এগুলো দেখতে পারে না।',
                'পাবলিক যাচাই লিংকে শুধু "কার্ড বৈধ কিনা", নামের প্রথম অংশ আর কার্ডের ধরন দেখায়।',
                'আপনার অনুমতি ছাড়া আপনার তথ্য অন্য কাউকে দেওয়া হয় না। ঠিকানা, মোবাইল আর ইমেইল কার্ডের পেছনে ছাপা থাকে; কার্ড যার হাতে থাকবে সে এগুলো দেখতে পারবে।',
                'কে, কখন আপনার তথ্য বদলেছে তার রেকর্ড রাখা হয়।',
            ]],
        ];
    }

    /** Text in use: the saved edit of a section, or its default. */
    public static function sections(): array {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::defaults() as $k => $d) {
            $s = $saved[$k] ?? null;
            $out[$k] = is_array($s) && isset($s['title'], $s['items']) && is_array($s['items']) ? ['title' => (string) $s['title'], 'items' => array_map('strval', $s['items']), 'edited' => true] : $d + ['edited' => false];
        }
        return $out;
    }

    /** Save one section. $text = one point per line. Other sections are left exactly as they are. */
    public static function saveSection(string $key, string $title, string $text): void {
        if (!isset(self::defaults()[$key])) { throw new \DomainException('Unknown section.'); }
        $title = trim(sanitize_text_field($title));
        $items = array_values(array_filter(array_map(static fn($l) => trim(sanitize_text_field($l)), preg_split('/\R/u', $text) ?: []), 'strlen'));
        if ($title === '' || !$items) { throw new \DomainException(__('A section needs a title and at least one line.', 'sfm4u-wholesale')); }
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $saved[$key] = ['title' => mb_substr($title, 0, 120), 'items' => array_slice($items, 0, 40)];
        update_option(self::OPTION, $saved, false);
    }

    public static function resetSection(string $key): void {
        $saved = get_option(self::OPTION, []);
        if (is_array($saved) && isset($saved[$key])) { unset($saved[$key]); update_option(self::OPTION, $saved, false); }
    }

    /** Made-up data only. Never a real person. */
    public static function sampleCard(): array {
        return ['user_id' => 0, 'tier' => 'regular', 'business_name' => '', 'issued_store' => 'main', 'id_type' => 'nid', 'photo_id' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'), '_demo' => [
                'display_name' => 'SAMPLE', 'user_email' => 'member@example.com', 'user_registered' => gmdate('Y-m-d H:i:s'),
                'member_no' => '0000000', 'nid' => '1234567890', 'district' => 'Noakhali',
                'photo' => self::asset('sample-person.jpg'), 'signature' => self::asset('sample-signature.png'),
                'sfm_wr_name_en' => 'SAMPLE MEMBER', 'sfm_wr_name_bn' => 'নমুনা সদস্য', 'sfm_wr_father_en' => 'SAMPLE FATHER',
                'sfm_wr_father' => 'নমুনা পিতা', 'sfm_wr_mother' => 'নমুনা মাতা', 'sfm_wr_dob' => '2000-01-01',
                'sfm_wr_address' => 'নমুনা ঠিকানা, নোয়াখালী', 'billing_phone' => '01XXXXXXXXX',
            ]];
    }

    public static function html(): string {
        wp_enqueue_style('sfm4u-card-guide', self::asset('card-guide.css'), [], (string) @filemtime(dirname(__DIR__, 2) . '/assets/card-guide.css'));
        $s = self::sections();
        $h = static fn(string $t): string => '<h3>' . esc_html($t) . '</h3>';
        $ul = static fn(array $items): string => '<ul>' . implode('', array_map(static fn($i) => '<li>' . esc_html($i) . '</li>', $items)) . '</ul>';
        $out = '<div class="sfm-card-guide" lang="bn">';
        if (current_user_can(Members::manageCap())) {
            $out .= '<p class="sfm-guide-admin"><small>Admin only: edit this text in wp-admin → SFM4U Members → Card guide text. Review the rules (and ask a lawyer if needed) before launch.</small></p>';
        }
        // Card layout CSS comes from the card itself (sizes are computed); the SAMPLE overlay lives in card-guide.css.
        $out .= '<style>' . CardView::css() . '</style>';
        $out .= $h($s['sample']['title']);
        foreach ($s['sample']['items'] as $p) { $out .= '<p>' . esc_html($p) . '</p>'; }
        $out .= CardView::render(self::sampleCard(), 'en', true);
        $out .= $h($s['plans']['title']) . $ul($s['plans']['items']);
        $out .= $h('কার্ডের কোথায় কী থাকে');
        $out .= '<p><strong>' . esc_html($s['front']['title']) . ':</strong></p>' . $ul($s['front']['items']);
        $out .= '<p><strong>' . esc_html($s['back']['title']) . ':</strong></p>' . $ul($s['back']['items']);
        foreach (['genuine', 'edit', 'lost', 'terms', 'privacy'] as $k) { $out .= $h($s[$k]['title']) . $ul($s[$k]['items']); }
        return $out . '</div>';
    }
}
