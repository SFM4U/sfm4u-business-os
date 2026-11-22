# SPEC: Noakhali Builder = everything Elementor Pro has, under the SFM4U name, plus more (N01-PRO)

Owner: MD SHALA UDDIN, 2026-11-22. Status: READY TO BUILD (Codex, task N01-EDITOR + N01-PRO). Check: Claude.
Feature list below is from Claude's knowledge of Elementor Pro (not re-checked on elementor.com today). Build our OWN code; do not copy Elementor code, names or icons.

## 0. The Owner's rule (most important)
The Owner does not code. With Noakhali Builder alone he must be able to:
- change any word, picture, colour, font, size, spacing, link and menu on any page, header or footer;
- move, copy, hide or delete any section;
- undo any mistake and go back to any older version ("Restore" = his "fix it if broken" button);
- see Desktop / Tablet / Mobile and fix each one;
- never need FTP, PHP, CSS or an AI to fix a page. Custom CSS exists only as an "advanced" option.
- A "Safe mode" button: if a page breaks, open it with the last good version.

## 1. Editor (Elementor-style layout)
- Left: Elements panel with search, categories (Basic, Layout, Pro, Shop, Site, SFM4U), favourites.
- Centre: live page, click any text to type, drag blocks, drop zones, right-click menu (copy, paste, paste style, duplicate, delete, save as template).
- Right: Structure (navigator) tree: rename, reorder, hide, lock.
- Top bar: Undo/Redo, History + Revisions, responsive switch, Preview, Publish / Save draft / Schedule.
- Every block: Content / Style / Advanced tabs. Advanced = margin, padding, z-index, position, motion, hide on device, CSS ID/class, custom attributes.
- Site settings: global colours, global fonts, typography scale, buttons, form fields, images, layout width, breakpoints, logo, favicon.
- Bangla + English editor labels; SFM4U look; no upgrade banners; no outside CDN.

## 2. Layout blocks
Container (Flexbox), Grid, Div, Section/Column (for imports), Inner container, Spacer, Divider, Tabs, Accordion, Toggle.

## 3. Basic widgets
Heading, Text editor, Image, Video, Button, Icon, Icon box, Image box, Icon list, Counter, Progress bar, Testimonial, Star rating, Alert, Social icons, Google Maps, HTML, Shortcode, Audio/SoundCloud, Image gallery, Image carousel, SVG, Menu anchor, Read more, Sidebar, Text path.

## 4. Pro widgets (what Elementor Pro adds)
- Posts, Portfolio, Loop Grid, Loop Carousel (design one card, repeat for all items).
- Form (connects to our SFM4U Forms A13: steps, conditions, file upload, actions).
- Login, Register, Nav Menu, Mega Menu, Search, Breadcrumbs, Sitemap, Table of contents.
- Slides, Media carousel, Testimonial carousel, Reviews, Call to action, Flip box, Animated headline, Price list, Price table, Countdown, Share buttons, Blockquote, Lottie, Hotspot, Code highlight, Video playlist, Facebook/X embeds, PayPal/Stripe button (through SFM4U Pay).
- Global widgets (edit once, change everywhere).

## 5. Theme Builder (the whole site, not only pages)
- Header, Footer, Single post, Single page, Archive, Search results, 404, Single product, Product archive, Loop item templates.
- Display conditions: whole site / page / category / post type / user role / logged in or out / device / language.
- Sticky header, transparent header, shrink on scroll.

## 6. Dynamic content
- Any field can show live data: site name, post title, date, author, featured image, custom fields, WooCommerce price/stock, user name, request values, SFM4U module data (order status, shop name, membership level).
- Fallback text when empty.

## 7. Popup builder
- Popups, slide-ins, bars, full screen.
- Triggers: page load, scroll, click, exit intent, inactivity. Conditions and frequency limits.

## 8. Shop builder (WooCommerce)
Product title, images, price, add to cart, stock, meta, tabs, related, upsells, rating, breadcrumbs, cart, checkout, my account, thank-you page, mini cart, menu cart, products grid, categories. Works with SFM4U Business Software modules (shops/vendors, delivery, COD guard, order tracking).

## 9. Motion and effects
Entrance animations, scroll effects (move, fade, blur, rotate, scale), mouse track, sticky elements, hover effects, transitions, shape dividers, background slideshow, video background, gradient, overlay.

## 10. Templates and kits
- Template library: blocks, pages, popups, full website kits; save my own; import/export as file.
- SFM4U kits: company site, shop, wholesale, club/community, clinic, school, portfolio.

## 11. Admin and safety
Role manager (who can edit what), maintenance / coming-soon mode, custom fonts and icons upload, custom code (head/body) for admins only, performance (load only used CSS/JS, lazy images), version history per page, export/import.

## 12. Import
- Elementor import: pages with text, images, layout. Old Elementor data is kept until the Owner says "OK".
- Also import Gutenberg pages.

## 13. NEW systems (beyond Elementor, SFM4U only)
- AI helper inside the editor ("write this section in Bangla", "make it mobile-friendly"), provider changeable (Claude / OpenAI / Grok / local), never required.
- One-click translation fields for Bangla/English per block (works with GTranslate or our own later).
- Business blocks: SFM4U module widgets (shop list, wholesale price table, order tracking, membership card, blood bank search, event list, booking).
- "Owner mode": a simple view with only text/picture editing, so mistakes cannot break the layout.
- Health check: broken links, missing images, mobile overflow, before Publish.
- Updates from marketplace.sfm4u.com (no outside servers).

## Delivery and tests
- ZIPs (core, Pro, Template Library, WooCommerce) into Factory "02 Ready modules"; version bump; changelog.
- Tests: build a page with every widget, edit text inline, undo/redo, restore a revision, mobile view, Theme Builder header on all pages, popup trigger, loop grid, form submit, Elementor page import, no PHP warnings, no outside requests.
- Then Claude installs on marketplace.sfm4u.com, rebuilds the marketplace pages with it, and publishes to Showroom + marketplace.
