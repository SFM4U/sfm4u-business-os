# Stores (r7 C1)

Business Software → Systems → Stores provides the module home and its own management page. Administrators and WooCommerce managers create pending stores, assign a WordPress user and manage approval/verification. Give the owner a Stores grant on the Access tab. Granted owners can edit their own store only. Put [sfm4u_stores] on an SFM4U page and turn on Public store directory in the module settings to display approved public profiles. Use public images and public business contact details.

Storage: WordPress internal sfm_store posts with owner author ID and pending/publish status, profile metadata sfm_stores_profile and manager verification metadata sfm_stores_verified. Disabling Stores preserves all data. No Dokan dependency or URL. Existing marketplace records require a separately reviewed migration before switching traffic.
