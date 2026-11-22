<?php defined('ABSPATH') || exit; get_header();
// SFM4U Business Software home: complete modules first, upcoming next, business plans on the side (Owner 2026-11-22).
$store = post_type_exists('sfm_module');
$items = $store ? get_posts(['post_type' => 'sfm_module', 'post_status' => current_user_can('read_private_posts') ? ['publish', 'private'] : 'publish', 'numberposts' => 400, 'orderby' => 'menu_order', 'order' => 'ASC']) : [];
$done = []; $soon = []; $plans = [];
foreach ($items as $p) {
    $types = wp_get_post_terms($p->ID, 'sfm_module_type', ['fields' => 'names']);
    if (in_array('Business plan', (array) $types, true)) { $plans[] = $p; continue; }
    $g = wp_get_post_terms($p->ID, 'sfm_module_group', ['fields' => 'names']); $g = $g ? $g[0] : 'Other';
    $st = strtoupper((string) get_post_meta($p->ID, '_sfm_status', true));
    if (str_contains($st, 'BUILT')) { $done[$g][] = $p; } else { $soon[$g][] = $p; }
}
$count = fn(array $a) => array_sum(array_map('count', $a));
$card = static function (WP_Post $p, bool $ready): string {
    $v = (string) get_post_meta($p->ID, '_sfm_version', true); $bn = (string) get_post_meta($p->ID, '_sfm_name_bn', true);
    return '<a class="card" style="display:block;color:inherit" href="' . esc_url(get_permalink($p)) . '"><span class="chip ' . ($ready ? 'built">Ready' : 'soon">Soon') . '</span>'
        . '<small style="color:var(--mid);font-weight:700">' . esc_html((string) get_post_meta($p->ID, '_sfm_code', true)) . ($v !== '' ? ' · v' . esc_html($v) : '') . '</small>'
        . '<h3 style="margin:6px 0 2px">' . esc_html(get_the_title($p)) . '</h3>' . ($bn !== '' ? '<p class="bn">' . esc_html($bn) . '</p>' : '') . '</a>';
};
?>
<main>
  <section class="hero">
    <div class="wrap">
      <p><span class="badge">SFM4U Business Software</span></p>
      <h1>One software for every business</h1>
      <p class="lead">From buying stock to the customer's door: shop, products, wholesale and retail prices, POS counter, member cards, khata, stock, delivery, reports and ERP link — in Bangla and English. Take the full software, or only the modules you need.<br><span lang="bn">মাল কেনা থেকে কাস্টমারের বাসা পর্যন্ত — পুরো সফটওয়্যার, অথবা দরকারি মডিউল আলাদা করে।</span></p>
      <div class="btns"><a class="btn solid" href="#complete">Complete modules</a><a class="btn" href="<?php echo esc_url(home_url('/modules/')); ?>">All modules</a></div>
    </div>
  </section>

  <section class="s" id="complete"><div class="wrap split">
    <div>
      <h2>Complete — working now <span class="badge"><?php echo (int) $count($done); ?></span></h2>
      <p class="sub">সম্পূর্ণ মডিউল — এখনই কাজ করছে।</p>
      <?php if (!$done) { echo '<p class="note">Modules appear here when the SFM4U Module Store is active.</p>'; } ?>
      <?php foreach ($done as $g => $ps) { echo '<h3 style="margin:18px 0 8px;color:var(--teal)">' . esc_html($g) . '</h3><div class="grid">'; foreach ($ps as $p) { echo $card($p, true); } echo '</div>'; } ?>
      <h2 style="margin-top:36px" id="upcoming">Upcoming <span class="badge"><?php echo (int) $count($soon); ?></span></h2>
      <p class="sub">আসছে — প্রতিদিন একটি একটি করে যোগ হচ্ছে। <a href="<?php echo esc_url(home_url('/modules/?status=soon')); ?>">See all upcoming →</a></p>
      <div class="grid"><?php foreach ($soon as $g => $ps) { echo '<a class="card" style="display:block;color:inherit" href="' . esc_url(home_url('/modules/?status=soon')) . '"><h3 style="margin:0 0 4px">' . esc_html($g) . '</h3><p class="bn">' . (int) count($ps) . ' modules coming</p></a>'; } ?></div>
    </div>
    <aside class="side" id="plans">
      <h2>Plans</h2>
      <div class="card"><h3>Full software</h3><p>Every module, setup and updates.</p><p class="price" style="color:var(--teal);font-weight:800">Coming soon</p></div>
      <div class="card"><h3>Single modules</h3><p>Only what your business needs.</p><p class="price" style="color:var(--teal);font-weight:800">Coming soon</p></div>
      <?php foreach ($plans as $p) { echo $card($p, true); } ?>
      <p class="note">Buying and downloads open soon. To get the software now, open a module and send a request, or write to <a href="mailto:support@sfm4u.com">support@sfm4u.com</a>.</p>
    </aside>
  </div></section>
</main>
<?php get_footer();
