# SFM4U account and card rules — v3 (Owner decisions 2026-11-21, written by Claude)

Status: DECIDED by the Owner (MD SHALA UDDIN), not yet built. v2 added §10. v3 (2026-11-21) settles the card numbers (§11) and the legal guidance (§12). v1/v2 are in coordination/previous-versions. Applies to account.sfm4u.com (master), every SFM4U shop site, AlgiBazar.com and future SFM4U services. A later change = a new version of this file (v2 …); old versions move to previous-versions. Any AI: read this with Skill 1 before account/card work.

## 1. One person = one SFM4U account for life
- The 7-digit **member number is the person's account number** (like an SSN). One per person, for life. It never moves to another person.
- The **card number** (12 digits) is only the plastic card's serial. A lost card or a tier change gives a new card; the member number stays.
- A person can have many emails; the account is still one. Customer, AlgiBazar store owner, real-estate partner, family member, member services: all on the same account.
- Number changes: only an administrator, only to correct a mistake or to fix a number when the administrator opens an account (own stores, admins); always with a written reason and a log. The old number is retired (never given to anyone else). (Replaces "admin can change any number freely" from L-16.)

## 2. NID or birth registration is required for members
- Opening an SFM4U member account / getting a card / linking a card needs an NID; if no NID, a birth registration number. Without one, the card does not work (no member price, no khata/credit, no service access).
- Walk-in customers without an account can still buy with cash or a payment card, without member benefits.
- Khata (credit) is only for members with an account (the shop can help them open one).
- One NID or birth registration number = one account. If someone tries a second account with the same NID/BRN, the system says: "This NID already has an SFM4U account. Log in with your earlier email, or contact the admin / helpline." It never shows the other account's email or name (protects against lookups by someone who knows another person's NID).
- No government verification for now (format check + uniqueness + staff look at the physical card at the counter). Later, when the company grows, connect an official verification service with permission.
- Administrator can correct a wrong/duplicate NID and update a corrected birth registration, with a reason and a log.

## 3. Admin-created accounts
- Administrators (e.g. the 5 company owners) can open an account themselves: email, NID/BRN, name, and choose the number (family 1-100 or a special number for our own stores). The chosen number is fixed to that account; the person gets no other number.

## 4. Who sees what (roles decide, administrator configures)
- A shop's staff see only what that shop needs and only that shop's data (its khata, its sales). A shop never sees company-wide data.
- Full NID/BRN: visible only to roles the administrator allows (e.g. shop registrar/manager/shop owner for members registered at that shop, SFM4U admin, CEO). Everyone else sees the last 4 digits only (Claude's recommendation; the Owner mentioned "last 7" — waiting for a final answer, see §8).
- The administrator decides per role which customer details are visible.

## 5. Account hold
- An administrator can put an account on hold for breaking SFM4U rules or privacy policy. On hold: no member price, no discount, no new khata credit, no Business Black benefits, no service access; old due can still be paid. Every shop and AlgiBazar shows "on hold" automatically. The person must buy without member benefits (or with another person's card, with that person's consent).

## 6. Card back side: service badges
- Above the barcode on the back of the card, the logos of the services this member belongs to: AlgiBazar, real estate, Golden Club (gym), etc. A badge appears when the member is enrolled in that service and disappears when it ends.

## 7. Separate organisations stay separate (e.g. BD Golden Club)
- Golden Club has its own account system. A person may choose to connect their SFM4U account to their Golden Club account; then the same card works (e.g. scan at the Golden Club gym door). Without that choice nothing is shared. (Owner rule: keep SFM4U company and Golden Club community separate unless the member connects them.)

## 8. Open questions for the Owner
1. SETTLED in v3 §11 (the "7" was the 7-digit SFM4U member number; the NID is never printed).
2. Existing accounts without NID/BRN: block the card at the next use, or give a grace period (e.g. 30 days)?

## 10. v2 — verification, search and duplicates (Owner 2026-11-21, later message)
- **Main rule, locked:** one person = one SFM4U member account. Every check below exists to enforce it.
- **Date of birth is required** at sign-up (with NID or birth registration).
- **Account manager role:** can open accounts and search, but cannot see the full NID/BRN or other private details stored in the account. Searching by an NID/BRN the customer shows works by matching (the stored number is never displayed).
- **Search order** (any registrar / account manager): 1) account (member) number → 2) date of birth (+ name) → 3) NID → 4) birth registration. If a person says they have no account but a match appears, they already have one (log in with the earlier email or contact the helpline). If nothing matches where it should, or two accounts point to one person, the system sends a **duplicate alert to the administrator**, who can take control of the account.
- **Government check (manual for now):** the account manager checks the NID/BRN on the Bangladesh government's official online service (no automatic link yet). Result recorded in the account: verified / problem.
- **Problem found → warning → hold → block:** the member gets a warning: "Your account has an NID problem. If you have a corrected NID/BRN, submit it, otherwise the account will be blocked." The account goes on **hold** (rules of §5) for a deadline set by the administrator; with no correction it becomes **blocked**. Corrections are recorded with a reason and a log.
- Verification states: unverified → verified, or problem (hold, deadline) → blocked; administrator can always correct.

## 11. v3 — numbers on the card (Owner 2026-11-21)
| Number | Digits | On the card? |
|---|---|---|
| SFM4U member (account) number | 7 (e.g. 0000003) | YES — the card's main number |
| Bangladesh NID | 10 (older NIDs 13 or 17) | NO — stored encrypted in the system only |
| Birth registration | 17 | NO — stored encrypted in the system only |
- Card shows: 7-digit member number, name, photo, QR/barcode, service logos, and the small text "Not a government ID".
- A scan shows "NID verified ✓" (or the problem state). Full NID/BRN only for roles the administrator allows; everyone else sees nothing or the last 4 digits.

## 12. v3 — legal guidance (research 2026-11-21; confirm with a Bangladeshi lawyer before launch)
- Personal Data Protection Ordinance 2025 (gazetted): collect NID/BRN only with explicit, specific consent for the stated purpose (one person = one account), keep it secure, allow correction/withdrawal. Keep the signed consent record.
- Do NOT present the SFM4U card as a substitute for the NID: banks use NID + biometrics (Bangladesh Bank e-KYC 2026), and making/carrying a fake NID is an offence under the NID Act (up to 7 years / Tk 1 lakh). Printing the NID/DOB on the card also creates identity-theft risk if the card is lost.

## 9. Build plan (after the Owner's "go")
- Claude (src/**, account site): date of birth + NID/BRN required at sign-up and card issue; account manager role (no private details); search by number → DOB → NID → BRN; duplicate alert to admin; verification states + warning → hold → block with deadline; one-NID-one-account check with the safe message; admin "open account with chosen number"; number change only with reason + log; account hold; role-based visibility; card back badges; Golden Club link (opt-in).
- Codex (modules/pos): counter shows "on hold" / "NID missing" clearly; khata refused for accounts without NID.
- Tests first locally; deploy to account.sfm4u.com only with a fresh backup + "go".
