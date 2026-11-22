<?php
use function SFM4U\ModuleStore\{meta, chip};
use const SFM4U\ModuleStore\{CPT, TAX_GROUP, TAX_TYPE};
defined('ABSPATH') || exit;
get_header();
the_post();
$id = get_the_ID();
[$label, $cls] = chip(meta($id, 'status'));
$g = get_the_terms($id, TAX_GROUP); $t = get_the_terms($id, TAX_TYPE);
$sent = isset($_GET['sent']); $err = isset($_GET['err']) ? sanitize_text_field(wp_unslash((string) $_GET['err'])) : '';
?>
<main class="ms">
  <section class="ms-hero single"><div class="wrap">
    <p><a href="<?php echo esc_url(get_post_type_archive_link(CPT)); ?>">← SFM4U Business Software — all modules</a>
      <?php if ($g && !is_wp_error($g)) { echo ' · <a href="' . esc_url(get_term_link($g[0])) . '">' . esc_html($g[0]->name) . '</a>'; } ?></p>
    <p><span class="badge"><?php echo esc_html(($t && !is_wp_error($t) ? $t[0]->name : 'Module') . ' ' . meta($id, 'code')); ?></span> <span class="chip <?php echo esc_attr($cls); ?>"><?php echo esc_html($label); ?></span>
      <?php if (get_post_status() === 'private') { echo ' <span class="chip soon">Private — only admins see this</span>'; } ?></p>
    <h1><?php the_title(); ?></h1>
    <?php if (meta($id, 'name_bn') !== '') { echo '<p class="bn-title">' . esc_html(meta($id, 'name_bn')) . '</p>'; } ?>
    <p class="lead"><?php echo esc_html(get_the_excerpt()); ?></p>
  </div></section>
  <div class="wrap ms-body">
    <article class="ms-main">
      <?php if (has_post_thumbnail()) { the_post_thumbnail('large'); } ?>
      <h2>What it does / কী কাজ করে</h2>
      <div class="entry"><?php the_content(); ?></div>
      <p class="note">This page shows the module only. Files are not downloadable here. A module is installed by SFM4U after a request.</p>
    </article>
    <aside class="ms-side">
      <div class="ms-box">
        <dl>
          <dt>Code</dt><dd><?php echo esc_html(meta($id, 'code') ?: '—'); ?></dd>
          <dt>Status</dt><dd><?php echo esc_html($label); ?></dd>
          <dt>Priority</dt><dd><?php echo esc_html(meta($id, 'priority') ?: '—'); ?></dd>
          <dt>Version</dt><dd><?php echo esc_html(meta($id, 'version') ?: '—'); ?></dd>
          <dt>Price</dt><dd><?php echo esc_html(meta($id, 'price') ?: 'Coming soon'); ?></dd>
        </dl>
      </div>
      <div class="ms-box" id="request">
        <h3>Request this module / মডিউলটি চাই</h3>
        <?php if ($sent) { echo '<p class="ok">Thank you. Your request was sent to SFM4U. We will contact you. / ধন্যবাদ, আমরা যোগাযোগ করবো।</p>'; } ?>
        <?php if ($err !== '') { echo '<p class="err">' . esc_html($err) . '</p>'; } ?>
        <form method="post" action="<?php echo esc_url(get_permalink()); ?>#request">
          <?php wp_nonce_field('sfm_req_' . $id, '_sfm_req_nonce'); ?>
          <input type="hidden" name="sfm_req" value="1">
          <label>Your name<input name="name" required maxlength="80"></label>
          <label>Phone or email<input name="contact" required maxlength="100"></label>
          <label>Shop / company (optional)<input name="shop" maxlength="120"></label>
          <label>Message (optional)<textarea name="message" rows="3" maxlength="2000"></textarea></label>
          <label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
          <button type="submit">Send request</button>
        </form>
      </div>
    </aside>
  </div>
</main>
<?php get_footer();
