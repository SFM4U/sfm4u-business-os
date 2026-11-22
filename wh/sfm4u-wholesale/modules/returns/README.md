# Returns and warranty (M-H)

Load bootstrap.php after ModuleHome.php from the Business OS module loader. Its filter registers the dashboard. Systems controls the REST registration. Requests and immutable outcomes use non-autoloaded per-order-line options prefixed sfm_returns_. Disabling preserves records.

The REST endpoint is sfm4u/v1/returns/{order}/{line}. GET reads the request and any outcome. POST requires a WordPress REST nonce and accepts a buyer request with kind, reason and up to three optional base64-encoded images (JPEG, PNG or WebP, max 128 KiB and 2048 px per side each). Reviewers may resolve with action=resolve and an outcome. Only the order's assigned Dokan vendor with the module grant, or a WooCommerce manager, may resolve. Use Dokan vendor suborders for mixed-vendor baskets.

The module records an already-issued WooCommerce/POS refund only after checking the parent order and refunded line. Refund execution, gateway and stock changes stay in the established workflow. Photos are stored with the private request, never in the public media library. Authorized REST responses are marked private and non-cacheable. Render each returned data URI only as an image.
