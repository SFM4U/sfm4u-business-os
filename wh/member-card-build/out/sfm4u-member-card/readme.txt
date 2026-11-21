SFM4U Membership Card
=====================
Member cards for any WordPress site (no WooCommerce needed). Built from the same code as the member-card part of "SFM4U Wholesale & Retail"; if both are active on one site, only one copy loads.

After activation:
1. SFM4U Members -> Member cards: "Every new account gets a regular member card automatically" is on by default. Tick "Also give a regular card now to existing accounts" once to give cards to old accounts.
2. Make a page "My membership card" with the shortcode [sfm4u_member_card].
3. Make a page "Card guide and rules" with the shortcode [sfm4u_card_guide] (only logged-in members can read it).
4. SFM4U Members -> Shops: set the main shop name, phone, address (printed on the card back for lost cards).

Data: card tables wp_sfm_wr_cards, wp_sfm_wr_khata, wp_sfm_wr_points, wp_sfm_wr_wallet; NID stored encrypted (libsodium) with a hash for lookup.
Map data: Bangladesh Bureau of Statistics / OCHA ROAP via geoBoundaries (CC BY 3.0 IGO). Barcode scanner: ZXing JS (Apache-2.0).

Account link (one card in every shop):
- account.sfm4u.com: SFM4U Members -> Account link -> "The SFM4U account site (card master)" -> create a key for each shop site.
- Shop site: Account link -> "A shop that asks the account site" -> paste address, Key ID, Secret -> Test connection.
- After linking, a shop never makes its own member numbers: every new card asks the account site, which finds the person by email or mobile or creates their SFM4U account. Scanning a member of another shop shows name/photo/type and "Add as a customer of this shop".
- Requests are HMAC-SHA256 signed, 5-minute window, replays refused. The account site sends shops only name, photo, card type, status, member number, district.
Account Hub: tiles "My Membership Card", "Card guide & rules" and (staff) "Card scan & khata" are added through the sfm4u_hub_tiles filter when the pages exist.
