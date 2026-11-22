# Returns and warranty (M-H)

Load bootstrap.php after ModuleHome.php from the Business OS module loader. Its filter registers the dashboard. Systems controls the REST registration. Requests and immutable outcomes use non-autoloaded per-order-line options prefixed sfm_returns_. Disabling preserves records.

The REST endpoint is sfm4u/v1/returns/{order}/{line}. GET reads the request and any outcome. POST requires a WordPress REST nonce and accepts a buyer request with kind, reason and optional existing buyer-owned image attachment IDs. Reviewers may resolve with action=resolve and an outcome. Only the order's assigned Dokan vendor with the module grant, or a WooCommerce manager, may resolve. Use Dokan vendor suborders for mixed-vendor baskets.

The module records an already-issued WooCommerce/POS refund only after checking the parent order and refunded line. Refund execution, gateway and stock changes stay in the established workflow. The module stores attachment IDs and provides no upload or public media endpoint. Keep sensitive evidence out of publicly readable WordPress media.
