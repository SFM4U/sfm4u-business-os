# 00 — CHATGPT (CODEX) START HERE: how to build one SFM4U module

Owner: MD SHALA UDDIN. Written by Claude on 2026-11-22. Read this whole page before you start.


## 0. MOST IMPORTANT (Owner, 2026-11-22): do NOT rebuild what already exists

**SFM4U Business Software already contains many systems.** Your first job is to **separate them into modules** inside the same plugin, then give each one the SFM4U design. Build new code only for modules that do not exist yet (status READY in the catalog).

- **The source code is the ZIP in this folder:** `SFM4U-BusinessOS-sfm4u-wholesale-0.9.19-dev.zip`. Unzip it; the plugin folder is `sfm4u-wholesale/`.
- **Where the existing systems live today:**

| System | Files |
|---|---|
| Wholesale/B2B rules | `src/Rule.php`, `src/Commerce.php`, `src/B2BRuntime.php`, `src/BulkOrder.php`, `src/BuyerApplication.php`, `src/SellerRules.php`, `src/MemberPrice.php` |
| Wholesale/B2B extras | `modules/b2b-pro/**` (credit accounts, spend tiers, hold review) |
| Retail prices (B2C) | `src/PriceAudience.php`, `src/PriceBox.php`, `src/ProfitCard.php` |
| Product sheet A–Z | `src/ProductSheet.php` |
| Purchase & cost | `src/Purchase.php`, `src/PurchaseHistory.php` |
| Member cards, khata, points, accounts | `src/Members/**` |
| POS, receipts, daily close | `modules/pos/**` |
| Stock, counts, suppliers, labels | `modules/inventory-bridge/**` |
| Low stock | `src/LowStock.php` |
| ERPNext link | `src/Erp/**` |
| Delivery (Steadfast), WhatsApp, auto print, stock link | `src/Ops/**` |
| Marketplace link, vendor profit | `src/Market/**` |
| Systems on/off, Dashboard, module dashboards | `src/Modules.php`, `src/ControlCenter.php`, `src/ModuleHome.php` |

- **For each existing system:**
  1. Keep it working exactly as it does now, with the same data keys and options. The live shop must not break.
  2. Give it the module contract and the SFM4U design.
  3. Write its test, `pass N fail 0`.
- **Theme and site builder:** use ONLY our own:
  - **SFM4U Theme** (Showroom: `sfm4u-theme-0.1.10.zip`);
  - **Noakhali Builder** (Showroom: `noakhali-builder-*` ZIPs).

  No other company's plugins, themes or page builders. The only outside software allowed is WordPress + WooCommerce. Reason: our AI runs here, and outside code is a security risk.
- If you cannot find something, look in this order: Showroom → Factory → GitHub repo `SFM4U/sfm4u-business-os`.

## 1. What you are building

**SFM4U Business Software** is ONE WordPress/WooCommerce plugin (slug `sfm4u-wholesale`) made of separate modules.

- It will be sold like Dokan, but with more features and better ones.
- AlgiBazar.com is the first marketplace that uses it.
- Your job: build **one module at a time** from "01 MODULE CATALOG". Take P1 modules first, starting with A01, A02, A03, then B01, B03, E01, B07, B08, B09, J01, G01, G02, C12.
- Write our own code. Never copy Dokan or other code, names or branding. Never link to Dokan pages: every page must be our own.

## 2. Where things go

| Item | Location |
|---|---|
| Plans | Google Drive → "5. SFM4U Factory — AI Work Floor" → "SFM4U Business Software — Module Factory" |
| Finished modules (ZIP + test result) | "02 Ready modules" folder |
| Joined into the software (Claude) | "03 Joined into software" folder |
| GitHub repo (if you can open it) | `SFM4U/sfm4u-business-os` |

In the GitHub repo:
- the plugin is in `wh/sfm4u-wholesale/`;
- the full plan is in `coordination/MARKETPLACE-MODULES-PLAN.md`;
- the task list is in `coordination/TASKS.md`.

If you cannot open GitHub, everything you need is on this page.

## 3. Module shape (every module the same)

```
sfm4u-module-<slug>/                 ← one folder, zipped as sfm4u-module-<slug>.zip
  sfm4u-module-<slug>.php            ← small add-on plugin header + loader
  src/<Name>.php                     ← code, namespace SFM4U\Modules\<Name>
  assets/                            ← css/js if needed (use the SFM4U design kit colours)
  tests/<slug>-test.php              ← WordPress test, prints "pass N fail 0"
  README.md                          ← what it does, data it keeps, how to switch on/off
```

### Loader template (copy it, change the names)

```php
<?php
/**
 * Plugin Name: SFM4U Module — Stores
 * Description: SFM4U Business Software module: shops list, approval, store profile.
 * Version: 0.1.0
 * Requires Plugins: sfm4u-wholesale
 * Author: SFM4U
 */
defined('ABSPATH') || exit;

add_filter('sfm4u_bos_modules', static function (array $list): array {
    require_once __DIR__ . '/src/Stores.php';
    // [English name, Bangla name, what it does, main admin page slug, class that implements ModuleContract]
    $list['stores'] = ['SFM4U Stores', 'SFM4U দোকান', 'Shops list, approval, store and owner profile.', 'sfm-stores', \SFM4U\Modules\Stores\Stores::class];
    return $list;
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists(\SFM4U\Wholesale\Modules::class) || !\SFM4U\Wholesale\Modules::on('stores')) { return; } // the owner can switch it off
    require_once __DIR__ . '/src/Stores.php';
    \SFM4U\Modules\Stores\Stores::register(); // menus, hooks, REST routes
}, 20);
```

### Class template (the dashboard contract)

```php
<?php
namespace SFM4U\Modules\Stores;
defined('ABSPATH') || exit;

final class Stores implements \SFM4U\Wholesale\ModuleContract {
    public static function register(): void {
        add_action('admin_menu', static function (): void {
            add_submenu_page('sfm-bos', 'Stores', 'Stores / দোকান', 'manage_options', 'sfm-stores', [self::class, 'page']);
        }, 30);
    }

    /** The module's own dashboard. SFM4U Business Software draws it (Overview, Settings, Who can use, Help). */
    public static function dashboard(): array {
        return [
            'numbers'  => [['Shops / দোকান', '12', admin_url('admin.php?page=sfm-stores')], ['Waiting / অপেক্ষায়', '3']],
            'pages'    => [['All shops', 'sfm-stores'], ['Approvals', 'sfm-stores-approve']],
            'settings' => [   // [key, label, type: text|number|checkbox|select|textarea, default, options for select]
                ['auto_approve', 'Approve new shops automatically', 'checkbox', false],
                ['max_products', 'Products per shop (0 = no limit)', 'number', 0],
            ],
            'help'     => ['English help text…', 'বাংলা সাহায্য…'],
        ];
    }

    public static function page(): void { /* your own admin page, SFM4U design */ }
}
```

- To read a setting: `\SFM4U\Wholesale\ModuleHome::setting('stores', 'auto_approve')`.
- The module's own dashboard opens at `admin.php?page=sfm-bos-module&m=stores`.

## 4. Rules (all must pass)

1. **Data:**
   - Use the prefix `sfm_<slug>_` for tables, options and meta.
   - Each shop sees only its own data. Customers, vendors, wholesalers, staff and admins each get their own permissions.
2. **Money:** keep amounts in integer minor units (paisa). Never round silently. A retry must never create a second payment, refund, withdraw or stock move.
3. **Security:**
   - Every form needs a nonce and a capability check.
   - Escape all output, prepare all SQL, and put no secrets in code.
4. **Language:** every label in English and Bangla.
5. **Design:** use the SFM4U design kit:
   - background `#020202`, cards `#001A12`;
   - accent teal `#00E0B0` (glow `#00F0C0`, mid `#00B080`);
   - the "4" green is `#00A070`; text is silver `#F1F3F3`;
   - use the logo where a logo is needed.

   The admin pages must look premium and must also work on a phone.
6. **Vendor side:** if shops use the module, add its page to our vendor panel (B03) and a REST route `sfm4u/v1/<slug>/…` for the AlgiBazar app.
7. **Tests:**
   - Write `tests/<slug>-test.php`, run with WP-CLI `wp eval-file`.
   - It must print `pass N fail 0`.
   - Use only made-up (synthetic) data, never real customers.
8. **Works with what exists:** WordPress 7.1, WooCommerce 11.3, PHP 8.2+. Dokan Lite may still be active; do not break it.

## 5. When a module is finished

1. Zip the folder as `sfm4u-module-<slug>.zip` and put it in **"02 Ready modules"**.
2. In "01 MODULE CATALOG", change the module's status to **REVIEW**.
3. Add one line to "04 Module log": module, version, date, test result (`pass N fail 0`), and anything not done.
4. Start the next module. Claude checks each module, joins it into the software, runs all tests and releases it.
