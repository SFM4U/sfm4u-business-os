<?php
use function SFM4U\ModuleStore\{meta, chip};
use const SFM4U\ModuleStore\{CPT, TAX_GROUP, TAX_TYPE};
defined('ABSPATH') || exit;
get_header();
$q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
$st = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
$base = get_post_type_archive_link(CPT);
$term = is_tax() ? get_queried_object() : null;
// group posts by group term (in catalog order)
$byGroup = [];
while (have_posts()) { the_post(); $g = get_the_terms(get_the_ID(), TAX_GROUP); $gn = $g && !is_wp_error($g) ? $g[0]->name : 'Other'; $byGroup[$gn][] = get_post(); }
wp_reset_postdata();
$total = array_sum(array_map('count', $byGroup));
?>
<main class="ms">
  <section class="ms-hero"><div class="wrap">
    <p><span class="badge">SFM4U Module Store</span></p>
    <h1><?php echo $term ? esc_html($term->name) : 'Every module of SFM4U Business Software'; ?></h1>
    <p class="lead">One software, many modules — built in Noakhali. Look at what each module does. To get a module, open it and send a request.<br><span lang="bn">একটি সফটওয়্যার, অনেক মডিউল — প্রতিটি মডিউলের বিস্তারিত দেখুন, নিতে চাইলে অনুরোধ পাঠান।</span></p>
    <form class="ms-find" method="get" action="<?php echo esc_url($term ? get_term_link($term) : $base); ?>">
      <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Search modules: POS, wholesale, delivery… / খুঁজুন" aria-label="Search modules">
      <button type="submit">Search</button>
    </form>
    <div class="ms-filters">
      <a class="<?php echo $st === '' ? 'on' : ''; ?>" href="<?php echo esc_url(remove_query_arg('status')); ?>">All</a>
      <a class="<?php echo $st === 'ready' ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg('status', 'ready')); ?>">Ready today</a>
      <a class="<?php echo $st === 'soon' ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg('status', 'soon')); ?>">Coming soon</a>
      <?php foreach (get_terms(['taxonomy' => TAX_TYPE, 'hide_empty' => true]) ?: [] as $t) { echo '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>'; } ?>
    </div>
    <p class="count"><?php echo (int) $total; ?> items</p>
  </div></section>
  <div class="wrap">
    <?php if (!$byGroup) { echo '<p class="note">Nothing found. <a href="' . esc_url($base) . '">See all modules</a></p>'; } ?>
    <?php foreach ($byGroup as $gname => $posts) { ?>
      <section class="ms-group"><h2><?php echo esc_html($gname); ?></h2>
        <div class="ms-grid">
          <?php foreach ($posts as $p) { [$label, $cls] = chip(meta($p->ID, 'status')); ?>
            <a class="ms-card" href="<?php echo esc_url(get_permalink($p)); ?>">
              <span class="code"><?php echo esc_html(meta($p->ID, 'code')); ?><?php if ($p->post_status === 'private') { echo ' · Private'; } ?></span>
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
