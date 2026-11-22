# SPEC: SFM4U Forms, our own form builder (module A13)

Owner: MD SHALA UDDIN, 2026-11-22. Status: READY TO BUILD. Builder: ChatGPT (Codex), then a check by Claude.

## Why
- Every form on every SFM4U site must be made and edited with ONE builder of our own. This replaces Fluent Forms and Forminator (outside software).
- It must work for every project, today and later:
  - sfm4u.com: project briefs, contact, advertising enquiry. Today these are 17 Fluent Forms with 24 entries, plus 2 Forminator forms.
  - bdgoldenclub.com: club member form, blood bank donor and request forms.
  - algibazar.com: shop (vendor) registration with documents (NID, trade licence, photos), customer forms.
  - Future: jobs (Hiring), services, Smart Serial, Noakhali Builder pages.

## What it must do
1. **Visual builder in wp-admin, no code.**
   - Drag fields; set label, help text, required, Bangla and English labels.
   - Field types: text, email, phone (BD +880 and US), number, date, select, radio, checkbox, textarea, file upload (documents and images, with size and type limits), address (division / district / upazila for Bangladesh), signature (later), hidden, page break (multi-step), and conditional show/hide.
2. **Embedding:** a shortcode `[sfm4u_form id="…"]`, plus a Noakhali Builder block. Mobile-first design in the SFM4U style; the colours follow each site's theme.
3. **Entries:**
   - Saved in our own tables (`sfm_forms_entries`).
   - Admin list with search, filter, status (new / reviewed / approved / rejected), notes, and CSV export.
   - Uploaded files go into a protected folder and are never public. Only admins can open them.
4. **Actions after submit:**
   - Email to the admin and the user, plus WhatsApp/SMS through the notifications module (A07) when it exists.
   - Thank-you message or redirect.
   - Hooks so other modules can react. Examples: vendor registration creates a pending shop (B01/B02); the Golden Club member form creates a member (S23); the blood bank form creates a donor (S25).
5. **Safety:** nonce, honeypot plus rate limit, CAPTCHA-free by default, file type and size checks, escaping of all output, no secrets in code, GDPR/consent checkbox option.
6. **Import from Fluent Forms and Forminator:** a one-time importer that copies the form fields and the existing entries. The old plugins are switched off only after the Owner checks the result.
7. **Templates:** contact, project brief, vendor registration (with documents), club member, blood donor, blood request, job application, service request.
8. **Module contract:** own dashboard (numbers: forms, entries today, new entries), settings and help in Bangla and English. Joins SFM4U Business Software through `sfm4u_bos_modules`.

## Tests (pass N fail 0, synthetic data only)
- Build a form with every field type, submit it valid and invalid, check the entry is saved.
- Upload a document: it lands in the protected folder, and the raw URL returns 403.
- Run the import of a sample Fluent Forms form plus entries.
- Check the rate limit and the honeypot.
- Render on mobile and desktop.

## Delivery
- `sfm4u-module-forms.zip` into Factory "02 Ready modules".
- Claude then tests it on a test copy, migrates sfm4u.com forms with the Owner's OK, and publishes it to marketplace and Showroom.
