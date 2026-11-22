# Skill 209 update — SFM4U Business Software: modules, marketplace, brand (2026-11-22)

Merge this into Skill 209 (SFM4U Business Software, formerly Business OS) as the next version.

## Owner rules learned today
- **One software:** SFM4U Business Software is ONE software made of modules. Every SFM4U plugin, theme and Noakhali Builder part becomes a module or product inside it.
- **Sold like Dokan, but better:** our own code only. Never copy Dokan Pro code or names.
- **No outside code:** only WordPress + WooCommerce are allowed from outside on our marketplace sites. Our AI runs there, so outside code is a security risk.
- **Clicks open our pages:** the Dashboard must open our own module pages, never Dokan pages. Old Dokan links stay hidden behind a temporary button until our module replaces each part.
- **Each module has its own dashboard:** Overview numbers, Pages, Settings, Who can use, Help (BN/EN).
- **Brand:**
  - Wordmark cut from the Owner's logo, with brackets at the logo's own size. No separate round logo next to it.
  - Dark background like sfm4u.com: #070c0b, a soft green glow, and the SFM4U background image at 14%.
  - Colours: teal #00E0B0, green "4" #00A070, silver #F1F3F3.
- **marketplace.sfm4u.com** is the home of SFM4U Business Software:
  - Complete modules and upcoming modules are listed, plus business plans.
  - Visitors only look and request. Admins can see Private items.
  - Later: purchase, licence and auto-update to client sites (algibazar.com first).
- **Who builds:**
  - ChatGPT is slow, so Claude builds modules itself. For each module: build → test → deploy to algibazar.com → mark it BUILT in the catalog → ZIP to Drive "03 Joined into software".
  - Save tokens: deploy only for real releases, keep replies short.

## How modules work (contract)
- An add-on plugin folder `sfm4u-module-<slug>` whose main file header says `Plugin Name: SFM4U Module — …`.
- It joins with `add_filter('sfm4u_bos_modules', fn($l) => $l + ['slug' => [en, bn, what, page, Class::class]])`.
- The class implements `SFM4U\Wholesale\ModuleContract::dashboard()`, which returns numbers, pages, settings, help.
- Settings are saved in option `sfm_mod_<slug>`.
- **Warning:** `dashboard()` must NOT call `ModuleHome::setting()`, because that loops forever. Read `ModuleHome::settings($slug)` plus your own defaults instead.
- Module Manager (in the plugin) accepts only SFM4U module ZIPs. It saves the old version before an update and can roll back.

## Live state (end of 2026-11-22 block)
- **algibazar.com:**
  - SFM4U Business Software 0.9.22-dev.
  - Modules E07 Order Tracking (/track-order/) and E04 COD Control (soft settings).
- **thenoakhali.com (test site):** same version, 0.9.22-dev.
- **marketplace.sfm4u.com:** theme sfm4u-marketplace 0.1.2 and plugin SFM4U Module Store 0.1.1 (122 items).

## Deploy recipe
1. Push a build branch with the plugin tree (`algibazar-bos-build`, `test-site-build`, `module-<slug>-build`, `marketplace-theme-build`, `module-store-build`).
2. Run Hostinger `hosting_git_deploy-website-repository` into `wp-content/plugins/<folder>` (or `wp-content/themes/<folder>`).
3. Activate with WPVibe `plugin activate` on algibazar.com, or with the Hostinger API `wordpress_plugins_activate` / `wordpress_themes_activate` (software id 31691339 for marketplace).

## Open items
- **Deploy key:** the Hostinger deploy key is not yet on the private repo SFM4U/sfm4u-ceo-ai. Until it is added, our SFM4U Theme 0.1.10 and Noakhali Builder cannot reach marketplace.
- **Public repo:** SFM4U/sfm4u-business-os is public. The Owner decides whether to make it private.
- **Passwords:** the Owner must reset the marketplace password.
- **Owner inputs:** 5% policy text, prices and plans.
- **Next:** rename the store to "SFM4U Business Software"; bring the sfm4u.com header/menu/footer to marketplace; list all our plugins, themes and Noakhali Builder; add admin-only ZIP downloads, purchase and update server; continue with modules G01, B04, A06…
