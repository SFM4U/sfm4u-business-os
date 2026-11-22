<?php defined('ABSPATH') || exit;
// Header copied from the approved sfm4u.com header (Owner 2026-11-22): same menu, search and ⋮ menu; the logo is the SFM4U wordmark (no rotating logo).
$sfm = 'https://sfm4u.com';
$nav = sfm4u_mp_nav_items(); // editable in Appearance → Menus
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="sfm-site-header" aria-label="SFM4U Marketplace header">
  <div class="sfm-site-top">
    <a class="sfm-site-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="SFM4U Business Software — Marketplace home"><img class="wm" src="<?php echo esc_url(get_template_directory_uri() . '/assets/sfm4u-wordmark.png'); ?>" alt="SFM4U" height="46"></a>
    <form class="sfm-site-search" role="search" method="get" action="<?php echo esc_url(home_url('/modules/')); ?>" aria-label="Search SFM4U Business Software">
      <input type="search" name="q" placeholder="Search SFM4U Business Software" aria-label="Search modules" required>
      <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10" cy="10" r="6.5"></circle><path d="m15 15 6 6"></path></svg></button>
    </form>
    <details class="sfm-site-more"><summary aria-label="Open menu"><span aria-hidden="true">⋮</span></summary>
      <nav class="sfm-site-dropdown" aria-label="More navigation">
        <a href="<?php echo esc_url($sfm . '/'); ?>">Home</a><a href="https://account.sfm4u.com/">My Account</a><a href="<?php echo esc_url($sfm . '/privacy-policy/'); ?>">Privacy Policy</a><a href="<?php echo esc_url($sfm . '/terms/'); ?>">Terms &amp; Conditions</a>
      </nav></details>
  </div>
  <div class="sfm-nav-frame"><nav class="sfm-nav-strip" aria-label="Primary navigation">
    <?php foreach ($nav as [$t, $u]) { echo '<a href="' . esc_url($u) . '"' . (untrailingslashit($u) === untrailingslashit(home_url('/')) ? ' class="on" aria-current="page"' : '') . '>' . esc_html($t) . '</a>'; } ?>
  </nav></div>
</header>
<div class="sfm-header-space" aria-hidden="true"></div>
