# SFM4U Pay: our own payment system (Owner idea, 2026-11-22)

Status: PLAN. Nothing is built yet. Exact current rules and fees must be checked on official sites before building; Claude did not check them today.

## The Owner's question
"Can we build our own payment gateway for free? If not, how do we take payments?"

## Short answer
- **A gateway of our own that moves card or bank money by itself:** not free and not quick.
  - It needs a bank partner and card-network rules (PCI DSS security audits).
  - It needs a licence: a PSP licence from Bangladesh Bank in Bangladesh, and money-transmitter licences in the USA.
  - It is also a large legal and security responsibility.
- **What we can build for free:** our own **SFM4U Pay module**.
  - It is one checkout and one dashboard, under the SFM4U name.
  - Behind it, it connects to licensed providers. Each provider charges its own per-sale fee.

## Plan: SFM4U Pay module (to build)
| Market | Providers to connect (merchant account needed) | Notes |
|---|---|---|
| International / USA (SFM4U LLC) | PayPal Business, Stripe | Card and PayPal. Fees per sale. Payouts go to the SFM4U business account. |
| Bangladesh | bKash Merchant, Nagad Merchant, SSLCommerz (cards and banks) | Each needs a merchant application with trade licence and bank details. |
| Shops on our marketplaces | the same providers, plus a split to each shop's balance | Uses the commission/earnings/withdraw modules (B07–B09). |

**What the module does:**
- One payment page in Bangla and English.
- Picks the right provider for each country.
- One report of all payments.
- Refunds that can never be paid twice.
- Webhooks from each provider are verified.
- No card data is stored on our server, because the provider hosts the card form.

**Owner actions needed (paperwork):**
- Business PayPal and Stripe accounts in the SFM4U LLC name.
- bKash, Nagad and SSLCommerz merchant applications for SFM4U Bangladesh.
- Never paste keys in chat. Keys go into the site settings through a secure login.

**Where it will be listed:** marketplace catalog F01 (bKash/Nagad/Rocket), F02 (SSLCommerz/card) and F05 (payout split). Add a new item "SFM4U Pay" when building starts.
