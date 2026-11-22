# SFM4U Business Software — MODULE CATALOG (all modules, one software)

Owner: MD SHALA UDDIN. Written by Claude on 2026-11-22.

- Every module below lives inside ONE plugin: **SFM4U Business Software**.
- ChatGPT (Codex) builds one module at a time and tests it. The finished module goes in the "02 Ready modules" folder.
- Claude then joins it into the software.
- The list was put together from features that marketplace, shop, POS and ERP software usually offers:
  - Dokan Lite/Pro modules
  - WooCommerce extensions
  - Shopify App Store categories
  - Odoo and ERPNext modules
  - Square and Loyverse POS
- The names describe the features only. We build our own code; we never copy code or branding.
- Feature lists of other products were not checked one by one today.

**Status values:**
- BUILT = already inside the software (version 0.9.18)
- READY = ChatGPT can build it now
- LATER = after launch

**Priority:** P1 = needed for the AlgiBazar launch; P2 = soon after; P3 = later.

## A. Platform (the base every module uses)
| ID | Module (EN / বাংলা) | What it does | P | Status |
|---|---|---|---|---|
| A01 | Brand design kit / ডিজাইন কিট | SFM4U logo, colours, fonts and components used on every page | P1 | BUILT |
| A02 | Module Manager / মডিউল ম্যানেজার | add a module by ZIP, update, roll back, on/off, versions | P1 | BUILT |
| A03 | Licence & updates / লাইসেন্স ও আপডেট | licence key per domain, update check, plans | P1 | READY |
| A04 | Main Dashboard / মূল ড্যাশবোর্ড | numbers and every module in one place | P1 | BUILT |
| A05 | Module dashboards / মডিউল ড্যাশবোর্ড | each module's own home page: numbers, pages, settings, access, help | P1 | BUILT |
| A06 | Roles & permissions / রোল ও অনুমতি | owner, admin, manager, cashier, vendor, vendor staff, customer, wholesaler | P1 | READY |
| A07 | Notifications centre / নোটিফিকেশন | SMS, WhatsApp, email and app push from one place, with templates in Bangla and English | P1 | READY |
| A08 | Language BN/EN / ভাষা | one-click Bangla/English across the whole software | P1 | READY |
| A09 | Activity log / কার্যকলাপ লগ | who changed what and when | P2 | READY |
| A10 | Import / export / ইমপোর্ট-এক্সপোর্ট | CSV/Excel for products, customers and orders | P2 | READY |
| A11 | About SFM4U & policies / কোম্পানি ও নীতি | company details, policies (5% policy), support | P1 | READY |
| A12 | One SFM4U login / এক অ্যাকাউন্ট | "Login with SFM4U" through accounts.sfm4u.com | P2 | LATER |

## B. Marketplace (shops) — replaces Dokan Lite/Pro
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| B01 | Stores / দোকান | shop list, approval, store and owner profile, store page | P1 | READY |
| B02 | Vendor registration / দোকান খোলা | sign-up form, documents, plan choice, onboarding steps | P1 | READY |
| B03 | Vendor panel / দোকানদারের ড্যাশবোর্ড | the seller's own dashboard (web and app) | P1 | READY |
| B04 | Seller verification / যাচাই | NID, trade licence, phone OTP, address; Verified badge | P1 | READY |
| B05 | Vendor staff / দোকানের স্টাফ | staff per shop; the cost price stays hidden from them | P2 | READY |
| B06 | Vendor plans / দোকানের প্ল্যান | subscription, product limit, commission per plan | P2 | READY |
| B07 | Commission / কমিশন | per shop, category or plan | P1 | READY |
| B08 | Earnings & statements / আয় ও হিসাব | admin earnings, shop balance, statement | P1 | READY |
| B09 | Withdraw / উইথড্র | bKash, Nagad or bank; approval; schedule; never pays twice | P1 | READY |
| B10 | Reverse withdrawal / উল্টো উইথড্র | the shop pays commission due on cash sales | P2 | READY |
| B11 | Store reviews / দোকানের রিভিউ | only verified buyers can review; the shop can reply | P2 | READY |
| B12 | Store follow / দোকান ফলো | customers follow a shop and get its offers | P3 | LATER |
| B13 | Store support tickets / সাপোর্ট টিকিট | customer ↔ shop ↔ admin | P2 | READY |
| B14 | Announcements / ঘোষণা | admin → all shops or chosen shops | P2 | READY |
| B15 | Report abuse / ভুয়া পণ্য রিপোর্ট | report a product, admin review, hold | P2 | READY |
| B16 | One product, many sellers / এক পণ্য অনেক দোকান | compare price, delivery and rating | P2 | READY |
| B17 | Vacation mode / ছুটি মোড | close the shop for a few days | P3 | LATER |
| B18 | Store search by location / কাছের দোকান | find nearby shops on a map | P2 | READY |
| B19 | Brands / ব্র্যান্ড | brand pages and the brand dashboard | P2 | READY |

## C. Products
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| C01 | Product sheet A–Z / প্রোডাক্ট শিট | photos, 15-second video, prices, stock, label, delivery | P1 | BUILT |
| C02 | Categories & attributes / ক্যাটাগরি | sizes, colours and variations | P1 | READY |
| C03 | Bulk edit / একসাথে এডিট | change price and stock of many products at once | P2 | READY |
| C04 | Bundles & combos / কম্বো | packs sold together | P2 | READY |
| C05 | Product add-ons / অতিরিক্ত অপশন | gift wrap, engraving and similar extras | P3 | LATER |
| C06 | Digital products / ডিজিটাল পণ্য | files, e-books, software licences | P3 | LATER |
| C07 | Booking & rental / বুকিং | services, rooms, rentals | P3 | LATER |
| C08 | Auction / নিলাম | bids | P3 | LATER |
| C09 | Product subscription / নিয়মিত অর্ডার | monthly delivery (milk, groceries) | P3 | LATER |
| C10 | Product Q&A / প্রশ্নোত্তর | questions and answers on the product page | P2 | READY |
| C11 | Wishlist & compare / পছন্দ ও তুলনা | save products and compare them | P2 | READY |
| C12 | Product page experience / প্রোডাক্ট পেজ | video, seller box, delivery info | P1 | READY |
| C13 | Barcode/QR labels / লেবেল | print labels | P1 | BUILT |
| C14 | Expiry & batch / মেয়াদ ও ব্যাচ | expiry warnings, sell oldest stock first (FEFO) | P2 | READY |
| C15 | AI product intake / AI দিয়ে পণ্য | read the label from a photo, fill the sheet (any AI provider) | P3 | LATER |

## D. Prices & offers
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| D01 | Retail price sets / খুচরা দাম | who sees and who buys; quantity steps | P1 | BUILT |
| D02 | Wholesale / B2B | wholesale price, approved buyers, credit | P1 | BUILT |
| D03 | Member price / মেম্বার দাম | Business/Black wholesale with the card | P1 | BUILT |
| D04 | Offers (buy X get Y) / অফার | 7+1 and similar offers | P1 | BUILT |
| D05 | Coupons / কুপন | shop coupons and member coupons | P1 | READY |
| D06 | Gift cards / গিফট কার্ড | sell and redeem gift cards | P3 | LATER |
| D07 | Friday / flash offers / শুক্রবারের অফার | timed special offers | P2 | READY |
| D08 | Dynamic price rules / দামের নিয়ম | rules by category, member or time | P2 | READY |
| D09 | VAT & tax (Bangladesh) / ভ্যাট | VAT per product, VAT invoice | P2 | READY |
| D10 | Multi-currency / মুদ্রা | BDT and USD view | P3 | LATER |

## E. Orders & checkout
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| E01 | Marketplace orders / অর্ডার | sub-order per shop, status, packing | P1 | READY |
| E02 | Invoices & packing slips / ইনভয়েস | per shop, A4 and 80 mm | P1 | READY |
| E03 | Receipt auto-print / রিসিট | print new orders | P1 | BUILT |
| E04 | Cash on delivery control / COD | COD limits, verification, fake-order guard | P1 | READY |
| E05 | Advance / partial payment / অগ্রিম | pay part now, the rest on delivery | P2 | READY |
| E06 | Pre-order & backorder / প্রি-অর্ডার | sell before stock arrives | P3 | LATER |
| E07 | Order tracking page / অর্ডার ট্র্যাকিং | the customer sees where the order is | P1 | BUILT |
| E08 | Returns & warranty / রিটার্ন ও ওয়ারেন্টি | requests with photos, exchange, warranty | P2 | READY |
| E09 | Refunds / রিফান্ড | never refunds twice | P2 | READY |
| E10 | Abandoned cart / ফেলে রাখা কার্ট | reminder by SMS/WhatsApp | P3 | LATER |

## F. Payments
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| F01 | bKash / Nagad / Rocket | mobile wallet payment | P1 | READY |
| F02 | SSLCommerz / card | card and online banking | P2 | READY |
| F03 | Wallet & store credit / ওয়ালেট | customer balance | P2 | BUILT (points & wallet) |
| F04 | Khata (due) / খাতা | credit for known customers | P1 | BUILT |
| F05 | Payout split / টাকা ভাগ | split each order between shop and admin | P2 | READY |

## G. Delivery
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| G01 | Delivery zones & charges / ডেলিভারি চার্জ | areas, weight, distance, flat rate | P1 | READY |
| G02 | Pickup points / পিকআপ | store location, pickup, map | P1 | READY |
| G03 | Couriers / কুরিয়ার | Steadfast (built), Pathao, RedX | P1 | PARTLY BUILT |
| G04 | Delivery man app / ডেলিভারি ম্যান | assign, collect COD, proof photo | P2 | READY |
| G05 | Delivery time slots / সময় বাছাই | choose a delivery time | P3 | LATER |
| G06 | Live tracking / লাইভ লোকেশন | rider on a map (app) | P3 | LATER |

## H. POS (shop counter)
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| H01 | POS counter / কাউন্টার | sell at the counter | P1 | BUILT |
| H02 | Registers & cashiers / রেজিস্টার | several counters and cashiers | P1 | BUILT |
| H03 | Cash drawer & daily close / দিনের হিসাব | open, close, variance | P1 | BUILT |
| H04 | Offline POS / অফলাইন | sell without internet, sync later | P2 | READY |
| H05 | Weight barcode scale / ওজন স্কেল | read barcodes from the label scale | P2 | READY |
| H06 | Bluetooth printer & scanner / প্রিন্টার | tablet shop kit | P1 | READY |
| H07 | Supported devices list / ডিভাইস তালিকা | which devices work | P1 | READY |

## I. Stock & buying
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| I01 | Stock & warehouses / স্টক | stock per shop and per store room | P1 | PARTLY BUILT |
| I02 | Stock counts / স্টক গণনা | count and approve differences | P1 | BUILT |
| I03 | Stock transfer / স্টক ট্রান্সফার | move stock between shops | P2 | READY |
| I04 | Purchase & cost / কেনা ও খরচ | buying trip, real cost | P1 | BUILT |
| I05 | Suppliers & payables / সাপ্লায়ার | purchase orders, what we owe | P1 | BUILT |
| I06 | Reorder & low stock / কম স্টক | alerts and suggested reorder | P1 | BUILT |
| I07 | Stock link to member stores / স্টক লিংক | Black purchases go into the member's store | P1 | BUILT |
| I08 | ERP link / ERPNext | ledger sync | P2 | BUILT |
| I09 | Partner site mirror / পার্টনার সাইট | show stock on a partner website | P1 | BUILT |

## J. Customers & members
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| J01 | Customer panel / কাস্টমার ড্যাশবোর্ড | orders, card, khata, points, addresses | P1 | READY |
| J02 | Member cards / মেম্বার কার্ড | Regular, Premium, Business, Black | P1 | BUILT |
| J03 | Points & rewards / পয়েন্ট | earn and redeem | P1 | BUILT |
| J04 | Referral / রেফারেল | invite and earn | P2 | READY |
| J05 | Premium plans / প্রিমিয়াম | paid membership | P2 | READY |
| J06 | Customer groups / কাস্টমার গ্রুপ | segments for offers | P2 | READY |
| J07 | Live chat / লাইভ চ্যাট | customer ↔ shop chat | P3 | LATER |

## K. Marketing
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| K01 | SMS / WhatsApp / email campaigns | send offers | P2 | READY |
| K02 | App push / পুশ নোটিফিকেশন | push to the AlgiBazar app | P2 | READY |
| K03 | Affiliates / অ্যাফিলিয়েট | commission for promoters | P3 | LATER |
| K04 | SEO tools / SEO | store and product SEO | P2 | READY |
| K05 | Banners & popups / ব্যানার | home banners, offer popups | P2 | READY |
| K06 | Facebook / Google catalog | product feed | P3 | LATER |

## L. Reports & money
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| L01 | Sales reports / বিক্রির রিপোর্ট | by day, shop and product | P1 | READY |
| L02 | Profit reports / লাভের রিপোর্ট | cost vs price (cost hidden from staff) | P1 | READY |
| L03 | Shop analytics / দোকানের রিপোর্ট | for each vendor | P2 | READY |
| L04 | Expenses / খরচ | shop expenses | P2 | READY |
| L05 | VAT report / ভ্যাট রিপোর্ট | for filing | P3 | LATER |
| L06 | Staff performance / স্টাফের কাজ | sales per cashier | P3 | LATER |

## M. Apps & connections
| ID | Module | What it does | P | Status |
|---|---|---|---|---|
| M01 | REST API & app / অ্যাপ | AlgiBazar app screens for every module | P1 | READY |
| M02 | Webhooks / ওয়েবহুক | send events to other systems | P2 | READY |
| M03 | AI assistant (any provider) / AI সহকারী | OpenAI, Claude, Grok, Hermes or a local model, swappable | P3 | LATER |
| M04 | WhatsApp bot / হোয়াটসঅ্যাপ বট | order status, catalog | P3 | LATER |

## N. Future SFM4U products (separate products, same account, same design)
| ID | Product | Notes |
|---|---|---|
| N01 | Noakhali Builder | website builder |
| N02 | SFM4U theme | our own theme |
| N03 | Delivery platform | riders, zones, tracking (app + server) |
| N04 | Ride share (Noakhali) | Uber-like (mobile apps + server, not WordPress) |
| N05 | Booking services | doctors, salons, rooms |
