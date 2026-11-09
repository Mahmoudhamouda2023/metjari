# Order reference and origin — 1.5.4

- Canonical shared order reference remains `ZAM-04112026-000014` across all roles and links. It is generated once and is not altered by assignment or status changes.
- UI details page shows `ZAM-04112026-000014-M` (merchant), `-C` (captain), or `-A` (admin), based on the **creator**, not the current viewer.
- Separate badges show creator and current order status; status never forms part of the immutable reference.
- Currently the application only exposes merchant order creation. `created_by_role` is set to merchant in that workflow; future authorized admin/captain creation endpoints must explicitly set their actual role, and must NOT infer it from the viewer.
- Historical orders default to merchant origin because historical creator identity was not stored. Verify or backfill from trustworthy audit events before asserting historical provenance.
- Merchant codes are allocated in StoreReference and protected by a unique DB constraint. Existing order references are preserved.
- Financial transaction IDs and bank transfer references remain separate from the order reference. Do not auto-mark receivables as collected.

Deployment: back up the DB, run `php artisan migrate --force` in staging, verify order detail pages for all roles and historical orders, then run full test suite. No independent end-to-end verification or production readiness certification has been performed.
