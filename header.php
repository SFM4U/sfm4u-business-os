<?php defined('ABSPATH') || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="mh">
  <div class="wrap row">
    <a class="logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="SFM4U Marketplace home">
      <img class="wm" src="<?php echo esc_url(get_template_directory_uri() . '/assets/sfm4u-wordmark.png'); ?>" alt="SFM4U" height="40">
      <span class="brand-word"><small style="font-weight:600;color:var(--muted)">Marketplace</small></span>
    </a>
    <nav aria-label="Main">
      <?php foreach (sfm4u_mp_links() as [$t, $u]) { echo '<a href="' . esc_url($u) . '">' . esc_html($t) . '</a>'; } ?>
    </nav>
    <a class="cta" href="<?php echo esc_url(home_url('/#plans')); ?>">Get the software</a>
    <button class="burger" type="button" aria-label="Open menu" aria-controls="mp-drawer" aria-expanded="false">☰</button>
  </div>
</header>
<div class="shade" id="mp-shade"></div>
<nav class="drawer" id="mp-drawer" aria-label="Mobile menu">
  <p><img src="<?php echo esc_url(get_template_directory_uri() . '/assets/sfm4u-wordmark.png'); ?>" alt="SFM4U" height="40" style="height:40px;width:auto"></p>
  <?php foreach (sfm4u_mp_links() as [$t, $u]) { echo '<a href="' . esc_url($u) . '">' . esc_html($t) . '</a>'; } ?>
  <a href="<?php echo esc_url(home_url('/#plans')); ?>">Get the software</a>
</nav>
<script>(function(){var b=document.querySelector('.burger'),d=document.getElementById('mp-drawer'),s=document.getElementById('mp-shade');function t(o){d.classList.toggle('open',o);s.classList.toggle('open',o);b.setAttribute('aria-expanded',o?'true':'false');}b.addEventListener('click',function(){t(!d.classList.contains('open'));});s.addEventListener('click',function(){t(false);});d.addEventListener('click',function(e){if(e.target.tagName==='A')t(false);});})();</script>
