<?php defined('ABSPATH') || exit; get_header(); ?>
<main class="content"><div class="wrap entry">
<?php if (have_posts()) { while (have_posts()) { the_post(); ?>
  <article <?php post_class(); ?>><h1><?php the_title(); ?></h1><?php the_content(); ?></article>
<?php } } else { echo '<h1>Not found</h1><p><a href="' . esc_url(home_url('/')) . '">Go home</a></p>'; } ?>
</div></main>
<?php get_footer();
