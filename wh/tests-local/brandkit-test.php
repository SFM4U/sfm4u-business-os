<?php
use SFM4U\Modules\BrandKit\BrandKit;

if (PHP_SAPI !== 'cli' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Synthetic loopback fixture required.');
}
wp_set_current_user(1);
$test = [
    BrandKit::usesStyle(BrandKit::PAGE),
    !BrandKit::usesStyle('edit'),
    str_contains(BrandKit::header('<test>', 'Synthetic'), '&lt;test&gt;'),
    str_contains(BrandKit::header('Synthetic', ''), '/assets/brand/sfm4u-logo-128.png'),
    str_contains(BrandKit::card('Synthetic', '<em>'), '&lt;em&gt;'),
    str_contains(BrandKit::tile('Synthetic', '3'), '<dd>3</dd>'),
    str_contains(BrandKit::table(['Item'], [['<test>']]), '<td>&lt;test&gt;</td>'),
    !str_contains(BrandKit::button('Synthetic', 'javascript:void(0)'), 'javascript:'),
];
ob_start();
BrandKit::page();
$html = ob_get_clean();
$test[] = str_contains($html, 'About SFM4U') && str_contains($html, 'pending Owner confirmation');
BrandKit::enqueue('sfm-bos-about');
$test[] = !wp_style_is('sfm4u-ui', 'enqueued');
$_GET['page'] = BrandKit::PAGE;
BrandKit::enqueue('sfm-bos-about');
$test[] = wp_style_is('sfm4u-ui', 'enqueued');
$fail = count(array_filter($test, static fn(bool $ok): bool => !$ok));
echo 'pass ' . (count($test) - $fail) . " fail $fail\n";
if ($fail) { exit(1); }
