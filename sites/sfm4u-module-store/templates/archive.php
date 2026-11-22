<?php
use function SFM4U\ModuleStore\{meta, chip};
use const SFM4U\ModuleStore\{CPT, TAX_GROUP, TAX_TYPE};
defined('ABSPATH') || exit;
get_header();
$q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
$st = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
$base = get_post_type_archive_link(CPT);
$term = is_tax() ? get_queried_object() : null;
$byGroup = [];
while (have_posts()) { the_post(); $g = get_the_terms(get_the_ID(), TAX_GROUP); $gn = $g && !is_wp_error($g) ? $g[0]->name : 'Other'; $byGroup[$gn][] = get_post(); }
wp_reset_postdata();
$total = array_sum(array_map('count', $byGroup));
// One menu bar under the title (Owner 2026-11-22). Order fixed; a type appears only if it has items.
$tabs = [['All', $base, !$term && $st === '' && $q === ''], ['Complete', add_query_arg('status', 'ready', $base), !$term && $st === 'ready'], ['Upcoming', add_query_arg('status', 'soon', $base), !$term && $st === 'soon']];
foreach (['Business plan' => 'Business plans', 'Module' => 'Modules', 'SFM4U product' => 'SFM4U products', 'Business Software' => 'Business Software', 'Plugin' => 'Plugins', 'Theme' => 'Themes'] as $name => $label) {
    $t = get_term_by('name', $name, TAX_TYPE);
    if ($t && $t->count > 0) { $tabs[] = [$label, get_term_link($t), $term && (int) $term->term_id === (int) $t->term_id]; }
}
$title = (string) get_theme_mod('sfm_mp_modules_title', 'SFM4U Business Software');
$lead = (string) get_theme_mod('sfm_mp_modules_lead', 'One software, many modules — built in Noakhali. Open any item to see what it does; to get it, send a request.');
$leadBn = (string) get_theme_mod('sfm_mp_modules_lead_bn', 'একটি সফটওয়্যার, অনেক মডিউল — প্রতিটির বিস্তারিত দেখুন, নিতে চাইলে অনুরোধ পাঠান।');
?>
<main class="ms">
  <section class="ms-top"><div class="wrap">
    <h1><?php echo esc_html($title); ?></h1>
    <nav class="ms-tabs" aria-label="SFM4U Business Software sections">
      <?php foreach ($tabs as [$label, $url, $on]) { echo '<a class="' . ($on ? 'on' : '') . '" href="' . esc_url($url) . '"' . ($on ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>'; } ?>
    </nav>
    <p class="lead"><?php echo esc_html($lead); ?><br><span lang="bn"><?php echo esc_html($leadBn); ?></span></p>
    <p class="count"><?php echo $q !== '' ? esc_html(sprintf('Search: "%s" — ', $q)) : ''; ?><?php echo (int) $total; ?> items<?php echo $term ? ' · ' . esc_html($term->name) : ''; ?></p>
  </div></section>
  <div class="wrap">
    <?php if (!$byGroup) { echo '<p class="note">Nothing found. <a href="' . esc_url($base) . '">See everything</a></p>'; } ?>
    <?php foreach ($byGroup as $gname => $posts) { ?>
      <section class="ms-group"><h2><?php echo esc_html($gname); ?></h2>
        <div class="ms-grid">
          <?php foreach ($posts as $p) { [$label, $cls] = chip(meta($p->ID, 'status')); $v = meta($p->ID, 'version'); ?>
            <a class="ms-card" href="<?php echo esc_url(get_permalink($p)); ?>">
              <span class="code"><?php echo esc_html(meta($p->ID, 'code')); ?><?php echo $v !== '' ? ' · v' . esc_html($v) : ''; ?><?php if ($p->post_status === 'private') { echo ' · Private'; } ?></span>
              <strong><?php echo esc_html(get_the_title($p)); ?></strong>
              <?php if (meta($p->ID, 'name_bn') !== '') { echo '<span class="bn">' . esc_html(meta($p->ID, 'name_bn')) . '</span>'; } ?>
              <span class="what"><?php echo esc_html(wp_trim_words((string) $p->post_excerpt, 18)); ?></span>
              <span class="chip <?php echo esc_attr($cls); ?>"><?php echo esc_html($label); ?></span>
            </a>
          <?php } ?>
        </div>
      </section>
    <?php } ?>
  </div>
</main>
<?php get_footer();
