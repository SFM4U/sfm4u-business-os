# Vendor Panel (r7 C2, in progress)

Native Business Software module. It adds a seller page and an authenticated read-only store directory endpoint at /wp-json/sfm4u/v1/vendorpanel. Sellers need both Vendor Panel and Stores grants. Shop settings use C1. The product form link needs WooCommerce's edit_products permission and the SFM4U Retail module. No Dokan links or data.

Disabling the module closes the page and endpoint. Store assignment is read from C1 and no data is duplicated. Store-scoped product/stock views, order and earnings sections and app screens are pending.
