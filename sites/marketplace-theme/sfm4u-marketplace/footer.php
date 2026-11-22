<?php defined('ABSPATH') || exit; $sfm = 'https://sfm4u.com'; ?>
<footer class="sfm-footer-shell">
  <div class="sfm-footer-pattern">
    <div class="sfm-footer-brand-row">
      <div class="sfm-footer-brand"><img class="wm" src="<?php echo esc_url(get_template_directory_uri() . '/assets/sfm4u-wordmark.png'); ?>" alt="SFM4U" height="44">
        <div><p class="sfm-footer-description">A connected platform for business, technology and community.</p></div></div>
      <div class="sfm-footer-entities">
        <div><p><strong>SFM4U</strong></p><p class="loc">New York, United States</p></div>
        <div><p><strong>SFM4U Bangladesh</strong></p><p class="loc">Noakhali, Bangladesh</p><p class="loc">RJSC application pending</p></div>
      </div>
    </div>
    <div class="sfm-footer-columns">
      <div><h3>Company</h3><a href="<?php echo esc_url($sfm . '/about/'); ?>">About SFM4U</a><a href="<?php echo esc_url($sfm . '/about/logo/'); ?>">Our Logo</a><a href="<?php echo esc_url($sfm . '/what-we-do/'); ?>">What We Do</a><a href="<?php echo esc_url($sfm . '/projects/'); ?>">Our Projects</a><a href="<?php echo esc_url($sfm . '/about/#community-giving'); ?>">Community &amp; Giving</a></div>
      <div><h3>Services</h3><a href="<?php echo esc_url($sfm . '/services/#service-website'); ?>">Website Design</a><a href="<?php echo esc_url($sfm . '/services/#service-wordpress'); ?>">WordPress &amp; Plugins</a><a href="<?php echo esc_url($sfm . '/services/#service-software'); ?>">Software &amp; Systems</a><a href="<?php echo esc_url($sfm . '/services/ai/'); ?>">AI Solutions</a><a href="<?php echo esc_url($sfm . '/services/healthcare/'); ?>">Healthcare &amp; Doctors</a><a href="<?php echo esc_url($sfm . '/services/advertising/'); ?>">Advertising Services</a></div>
      <div><h3>Business Software</h3><a href="<?php echo esc_url(home_url('/modules/')); ?>">All modules</a><a href="<?php echo esc_url(home_url('/modules/?status=ready')); ?>">Complete modules</a><a href="<?php echo esc_url(home_url('/modules/?status=soon')); ?>">Upcoming modules</a><a href="<?php echo esc_url(home_url('/modules/type/business-plan/')); ?>">Business plans</a></div>
      <div><h3>Contact</h3><a href="mailto:support@sfm4u.com">support@sfm4u.com</a><a href="<?php echo esc_url($sfm . '/services/#project-brief'); ?>">Send a Project Brief</a></div>
    </div>
    <div class="sfm-footer-legal"><p>© <?php echo esc_html(gmdate('Y')); ?> SFM4U. All rights reserved.</p><p><a href="<?php echo esc_url($sfm . '/privacy-policy/'); ?>">Privacy Policy</a> · <a href="<?php echo esc_url($sfm . '/terms/'); ?>">Terms &amp; Conditions</a></p></div>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
