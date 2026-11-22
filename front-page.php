<?php defined('ABSPATH') || exit; get_header();
// Module groups from the SFM4U MODULE CATALOG (Factory). built = already inside the software today.
$groups = [
    ['Platform', 'প্ল্যাটফর্ম', ['Main dashboard' => 1, 'Module dashboards' => 1, 'Module Manager' => 0, 'Licence & updates' => 0, 'Roles & permissions' => 0, 'Notifications (SMS / WhatsApp / email)' => 0, 'One-click Bangla / English' => 0]],
    ['Marketplace & shops', 'মার্কেটপ্লেস ও দোকান', ['Stores & approval' => 0, 'Vendor panel (web + app)' => 0, 'Seller verification' => 0, 'Commission & earnings' => 0, 'Withdraw (bKash / Nagad / bank)' => 0, 'Store reviews & support' => 0, 'Brands' => 0]],
    ['Products', 'পণ্য', ['Product sheet A–Z (photos, 15 s video)' => 1, 'Barcode / QR labels' => 1, 'Expiry & batch' => 0, 'Bulk edit' => 0, 'Bundles & combos' => 0, 'AI product intake' => 0]],
    ['Prices & offers', 'দাম ও অফার', ['Retail price sets (who sees, who buys)' => 1, 'Wholesale / B2B' => 1, 'Member price by card' => 1, 'Buy X get Y (7+1)' => 1, 'Coupons' => 0, 'Friday offers' => 0, 'Bangladesh VAT' => 0]],
    ['Orders & payments', 'অর্ডার ও পেমেন্ট', ['Receipt auto-print' => 1, 'Khata (due)' => 1, 'Points & wallet' => 1, 'Orders per shop' => 0, 'COD control' => 0, 'bKash / Nagad / Rocket' => 0, 'Returns & warranty' => 0]],
    ['POS (shop counter)', 'POS (দোকানের কাউন্টার)', ['POS counter' => 1, 'Registers & cashiers' => 1, 'Cash drawer & daily close' => 1, 'Tablet shop kit (printer, scanner)' => 0, 'Weight barcode scale' => 0, 'Offline POS' => 0]],
    ['Stock & buying', 'স্টক ও কেনা', ['Purchase & real cost' => 1, 'Suppliers & payables' => 1, 'Stock counts' => 1, 'Low stock & reorder' => 1, 'Stock link to member stores' => 1, 'ERPNext link' => 1, 'Partner site mirror' => 1]],
    ['Customers & members', 'কাস্টমার ও মেম্বার', ['Member cards (Regular, Premium, Business, Black)' => 1, 'Customer panel' => 0, 'Referral' => 0, 'Premium plans' => 0]],
    ['Delivery', 'ডেলিভারি', ['Steadfast courier + WhatsApp' => 1, 'Delivery zones & charges' => 0, 'Pickup points' => 0, 'Delivery man app' => 0]],
    ['Reports & apps', 'রিপোর্ট ও অ্যাপ', ['Sales & profit reports' => 0, 'Shop analytics' => 0, 'REST API & AlgiBazar app' => 0, 'AI assistant (any AI provider)' => 0]],
];
$built = 0; $all = 0; foreach ($groups as $g) { foreach ($g[2] as $b) { $all++; $built += $b; } }
?>
<main>
  <section class="hero">
    <div class="wrap">
      <img class="big" src="<?php echo esc_url(sfm4u_mp_logo('512')); ?>" alt="SFM4U logo" width="150" height="150">
      <p><span class="badge">SFM4U Software Marketplace</span></p>
      <h1><span class="brk">‹</span> SFM4U Business Software <span class="brk">›</span></h1>
      <p class="lead">One software for a whole marketplace and every shop in it: shops, products, wholesale and retail prices, POS counter, member cards, khata, stock, buying cost, delivery and reports, in Bangla and English.<br><span lang="bn">একটি সফটওয়্যার — পুরো মার্কেটপ্লেস ও প্রতিটি দোকানের সব কাজ।</span></p>
      <div class="btns"><a class="btn solid" href="#modules">See all modules</a><a class="btn" href="#plans">Plans</a></div>
    </div>
  </section>

  <section class="s" id="software"><div class="wrap">
    <h2>Why SFM4U Business Software</h2>
    <p class="sub">Built in Noakhali for Bangladesh, ready for any marketplace.</p>
    <div class="grid">
      <div class="card"><h3>One software, many modules</h3><p class="bn">একটি সফটওয়্যার, অনেক মডিউল</p>Turn each module on or off. Each module has its own dashboard, settings and help.</div>
      <div class="card"><h3>Shop counter + online</h3><p class="bn">দোকান + অনলাইন একসাথে</p>POS counter, receipts and stock stay correct with online orders.</div>
      <div class="card"><h3>Wholesale and retail</h3><p class="bn">পাইকারি ও খুচরা</p>Business members get wholesale with their card; Premium members get quantity savings.</div>
      <div class="card"><h3>Real cost, private</h3><p class="bn">আসল খরচ গোপন</p>Fares, truck and labour spread into the real cost per piece. Staff never see it.</div>
      <div class="card"><h3>Bangla & local payments</h3><p class="bn">বাংলা ও লোকাল পেমেন্ট</p>Bangla/English, bKash and Nagad, local couriers, khata (due) for known customers.</div>
      <div class="card"><h3>Proven on AlgiBazar</h3><p class="bn">AlgiBazar-এ চলছে</p>Running today on <a href="https://algibazar.com/">AlgiBazar.com</a>, SFM4U's own marketplace.</div>
    </div>
  </div></section>

  <section class="s" id="modules"><div class="wrap">
    <h2>Modules <span class="badge"><?php echo (int) $built; ?> ready today · <?php echo (int) ($all - $built); ?> being built</span></h2>
    <p class="sub">মডিউল — সবুজ চিহ্ন = আজ চালু, ধূসর = তৈরি হচ্ছে।</p>
    <div class="grid">
      <?php foreach ($groups as [$en, $bn, $mods]) { ?>
        <div class="card"><h3><?php echo esc_html($en); ?></h3><p class="bn"><?php echo esc_html($bn); ?></p>
          <?php foreach ($mods as $m => $b) { echo '<div style="margin:4px 0"><span class="chip ' . ($b ? 'built">Ready' : 'soon">Soon') . '</span>' . esc_html($m) . '</div>'; } ?>
        </div>
      <?php } ?>
    </div>
  </div></section>

  <section class="s plans" id="plans"><div class="wrap">
    <h2>Plans</h2>
    <p class="sub">Prices will be announced soon. Sales are not open yet.</p>
    <div class="grid">
      <div class="card"><h3>Free</h3><div class="price">Coming soon</div>Core: dashboard, product sheet, basic prices.</div>
      <div class="card"><h3>Pro</h3><div class="price">Coming soon</div>POS, member cards, wholesale, purchase cost.</div>
      <div class="card"><h3>Business</h3><div class="price">Coming soon</div>Full marketplace: shops, commission, withdraw, delivery.</div>
      <div class="card"><h3>Enterprise</h3><div class="price">Coming soon</div>Many sites, setup help, custom modules.</div>
    </div>
    <p class="note" style="margin-top:16px">This page shows the software and its modules. Buying, licence keys and downloads are not open yet. Questions: <a href="mailto:support@sfm4u.com">support@sfm4u.com</a></p>
  </div></section>
</main>
<?php get_footer();
