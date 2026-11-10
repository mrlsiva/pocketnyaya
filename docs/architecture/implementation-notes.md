# Implementation notes

Details added while building that the approved architecture doesn't state, and what remains unverified. Nothing here changes an approved decision. Each provisional item needs review.

## Environment

**Database: MariaDB everywhere** (owner decision, 2026-11-09). This **replaces C51** (MySQL 8.4 on port 3307). Development uses MariaDB 10.4.32 (database `pocket`) with synthetic data only. Production uses a supported MariaDB LTS (10.11 or 11.4), still managed in phpMyAdmin. No MySQL 8.4 install is planned. The MySQL80 service and port 3307 stay untouched.

The app connects as the local `root` account, not a limited account. This is for local development only. Tests run only against `pocket_test`, and must never run DDL, because DDL commits the test's transaction.

## Decisions by the project owner

| Item | Decision |
|---|---|
| `user_devices.push_token_hash`, a stored generated column carrying the unique rule | **Provisional. Not part of the approved table definition.** Kept unchanged |
| `erased_at` on `advocate_profiles` and `mediator_profiles` | **Kept.** See below |
| `UQ(accreditation_body, accreditation_number)` on `mediator_profiles` | **Accepted provisionally** |
| `mediator_categories.category` limited to the canonical `odr_disputes.category` values | **Accepted** |
| Timestamps on pivot tables, and unstated column lengths | **Provisional** |
| Delete cascade | **Accepted for pure link tables only** |
| Mediator verification documents | **`verification_requests` extended** with `mediator_profile_id`. Exactly one applicant per request |
| Applicants downloading their own verification files | **Allowed** |
| Database (2026-11-09) | **MariaDB everywhere**, replacing C51 |
| Closing a practice (2026-11-09) | **Built in all 7 stages, with final deletion switched off** until retention rules are approved. At retention the practice is marked closed and deleted, and its data is kept. The export holds records and decrypted documents |
| Credit notes (2026-11-09) | **Own CN series, and they reduce the original's balance**, with tax reversed in proportion. A CA may correct this |
| Phase 4 integrations (2026-11-09) | **Switched-off adapters** behind interfaces. Nothing calls out until a driver is approved |
| Providers (2026-11-10) | **Anthropic Claude** for AI drafting, **Razorpay** for payment links, **Jitsi** for video. Built and tested with fakes, switched off until keys are set. SMS and Aadhaar eSign are skipped for now |
| Isolation layer (2026-11-09) | **Two connections.** The main connection uses the `v_` prefix as `pn_app`, so every table resolves to its filtered view. Staff screens, console jobs and the practice export use the `platform` connection as `pn_platform`. It switches on with `DB_ISOLATION=true` once both accounts exist |
| Subscriptions (2026-11-09) | **No plan means no limits.** Plans can limit members (pending invitations count), open matters and document storage. Limits stop new additions only |
| Retention (2026-11-09) | **Nightly dry run, two-person disposal.** Supported categories: `closed_practice_documents` (crypto-shred) and `closed_practice_clients` (anonymise), counted from the new `tenant_closure` trigger |

### `erased_at`: what C44 requires

C44 section 1.4 says professional identifiers are released on erasure: `enrollment_number` becomes nullable, with `CHECK (erased_at IS NOT NULL OR enrollment_number IS NOT NULL)`, and mediators follow "the same pattern". C44 names the column on `advocate_profiles` but gives no type, and implies it for `mediator_profiles`.

The column is `DATETIME(6) NULL` on both tables, following the approved `timestamptz` mapping. `ErasureService` sets it.

## Items added while building: these need review

| Item | What was done | Why |
|---|---|---|
| `document_shares` | Only `user` and `tenant` grantees, no `link` or `token_hash`. Permission is `view` or `download` | **Differs from the first Step 2 draft.** Step 3 defines named recipients, and the owner requires no public URLs |
| Verification document triggers | The applicant alone may attach or replace a file while the request is open. Files can't be deleted | Database enforcement of D8.8 |
| Platform documents can't be shared | Trigger `document_shares_guard_ins` | D8.8 defines sharing for tenant and personal documents only |
| `odr_messages.sender_mediator_profile_id` | Added. `sender_participant_id` became nullable | **Added column.** The approved table has no way to record a mediator as sender, since mediators have assignments rather than participant rows |
| `data_principal_requests.details`, status `withdrawn` | Added | D8.12 allows withdrawal but the approved status list has no value for it |
| `erasure_actions.action` value `shredded` | Added | The approved list has no value for crypto-shredding |
| `retention_rules.drafted_by`, `approval_evidence` | Added | D8.12 needs recorder ≠ drafter and counsel's evidence, but the table lists neither |
| `legal_holds.released_by` | Added | So release is attributable |
| `knowledge_items.created_by` | Added | Who contributed it |
| `content_reports` unique per person and post | Added | Prevents repeat reports |
| Search | `LIKE` on title, summary and tags instead of `tsvector` or `FULLTEXT` | Works with the transaction-based tests. Slow on large tables, so revisit before launch |
| No active screening rules | Content goes to a moderator instead of publishing | Fails closed |
| Privacy request deadline | 30 days, `pocketnyaya.privacy_request_days` | **Placeholder. Counsel must confirm** |
| ODR suitability rules | Violence or crime is unsuitable. An urgent order or an existing case needs review | **Provisional (`provisional-1`). Counsel must review** |
| Screening rules, consent wording, disclaimers | Seeded English text | **Drafts. Counsel must review before launch** |
| Invoice number format | `INV/2627/1`, per tenant and financial year, 16 characters at most | The prefix and layout aren't specified. A CA should confirm |
| Reverse charge | Rate is recorded on the line, and no tax is added to the invoice total | **A CA must confirm** |
| `SimplePdf` | A small text-only PDF writer, with no new package | Western characters only. Invoice PDFs print `Rs.` instead of the rupee sign |
| Super Admin proposals | Stored as the approved audit events `superadmin.proposed`, `superadmin.approved` and a new `superadmin.proposal_withdrawn`, with no new table | D9 names the events but not where a pending proposal lives. **Needs review** |
| Super Admin removal | Allowed with `platform.superadmin.approve`, never your own, and only while more than 2 active Super Admins remain | D9 sets the floor of 2 but doesn't say who removes |
| Compliance Officer grant | Creates or restores the person's platform membership. Revoking leaves the membership, so their Customer and other platform roles are untouched | C24 needs a membership, and ending it would remove their other platform roles |
| Time on invoices | One line per approved, billable, unbilled entry, at an hourly rate entered on the invoice (defaulting to the client's hourly arrangement). Amounts are exact in paise from minutes | The design doesn't say whether the rate comes from the arrangement or the entry |
| Released work | Removing a line, deleting a draft or cancelling an invoice makes its time and expenses billable again. The cancelled invoice keeps its lines | So cancelled work isn't lost. **A CA should confirm** |
| Pure-agent expenses | Refused on invoices | The tax engine would tax them. They need a CA-approved rule |
| Legal hold coverage | Document deletion and erasure check holds on the document, its practice, matter, dispute and (for personal files) owner. Matter and client deletion check matter and practice holds | Follows C60 §4.2. Previously only document and user holds were checked |
| Forum edits | Questions can be edited only while waiting for review. Answers can be edited by their author at any time. Both are screened again, and old screening cases close as `edited` | D8.11 |
| Credit note payments | `payments.method` gains `credit_note`. The credit is applied to the original as an allocation, and can't be reversed | The invoice CHECKs only let a balance fall through allocations. **Schema change, for review** |
| `tenant_offboarding` columns | Stage timestamps and actors, the export recipient and window, a waiver reason, the hold count at each check, `disposal_status`, and one open closure per practice. Rows can't be deleted | The design named the table only. **For review** |
| Export window | 30 days, `pocketnyaya.offboarding_export_days` | **Placeholder. Counsel must confirm** |
| Export recipient | The owner, a Managing Partner or the Independent Lawyer, chosen at request | Who may receive the firm's file is a legal question. **Counsel must confirm** |
| Personal-data masking (step 19) | `PiiMasker`: local patterns for email, phone, Aadhaar, PAN, GSTIN, CNR, UPI, IFSC and account numbers | It doesn't catch names or addresses, so a person must still review |
| Integration drivers | `config/integrations.php`, every service `none`. An unknown driver name also stays off | Phase 4 needs approval per provider |
| `v_tenant_memberships` | Checks the bound context but not the membership again on each statement | MariaDB makes a view read-only when its filter reads the same table. The bind-time check and the expiry of at most 1 hour cover it |
| Isolation procedures | Don't check the caller's account name | EXECUTE is granted to `pn_app` alone |
| `pn_privileged_accounts` | Accounts listed here see all rows through the views: only the migration or administrator account. `pn_app` and `pn_platform` must never be listed | Lets migrations, seeders and tests work through the same views. **Security-critical, for review** |
| Same-named views | Every base table has a `v_<table>` view. Firm tables are filtered to the bound practice. Documents, versions, keys and shares follow scope, owner and active shares. Clients and consultations also show the customer's own rows. Other tables pass through (T4) | Lets Laravel's table prefix switch the whole app without query changes |
| Request binding | Every signed-in request binds the person. Practice requests bind the practice instead, and the binding is cleared when the request ends | Workspaces, profile and customer pages need the person's own rows |
| Retention record | `retention_batches` holds ids, counts and a hash, never content, and can't be deleted. Clients are anonymised (name `Erased client`, contact and tax details cleared) instead of deleted | Invoices and matters still reference them. **Counsel and a CA should confirm** |
| T3 tables | Read through join views. `pn_app` may INSERT but not read the base tables | The T3 write procedures aren't built |
| Audit and access logs | `pn_app` can SELECT and INSERT on the base tables | Staff and firm audit screens read them directly. Reads aren't isolated by the database |
| `document_keys` | `pn_app` can read and write them | Decryption happens in PHP, not in a database procedure |
| Real-time signals | `users.{id}` and `tenants.{id}.diary` private channels, ids only, sent after commit and best effort | PRD 2.3. Nothing depends on a signal arriving |
| Allocation matrix and firm reports | Availability, practice areas and workload limits shown when allocating; hours, utilisation and fees by lead advocate per financial year | PRD 2.4. **Utilisation is billable time divided by recorded time, for review** |
| Security headers | A content policy allowing scripts from this site only, plus frame, sniffing and referrer protection on every response | QA finding F-4 |

## MariaDB substitutions

These follow from the MySQL-era design. Re-check them on the production MariaDB version.

| Approved | Used here |
|---|---|
| `FOR SHARE` | `LOCK IN SHARE MODE` in `pn_assert_assignment` and the tax overlap triggers |
| Consent `DEFINER` procedure (MY-8) | `ConsentLedger` in PHP, under a locked user row. **The append-only triggers are the only database-level protection until separate accounts exist** |

## Approved items not built

### Blocked on a decision or missing definition

- **Final disposal of a closed practice.** It stays switched off until retention rules for a closed practice are approved. `disposal_status` records why.
- **Audit storage (MY-18 / C60).** The design is approved: U4 chose option B, unpartitioned, for the MVP, and U5 approved the retention account and gate row, subject to tests. The built code is stricter than C60 (no deletion at all) and lacks the `pn_retention` account, the hold gate triggers and the archive. Tests RT and PB haven't run.

### Needs credentials, legal checks or later phases

Adapters exist for all of these, switched off (Staff → Integrations). Drivers for the three chosen providers are built (2026-11-10); they need keys and the checks in the production guide, section 13.

- AI drafting and summarisation: the Anthropic driver and the practice Drafting assistant are built. Nothing is stored, so the section O tables aren't migrated. Masking runs locally, by pattern, and misses names and addresses
- NJDG or eCourts sync
- Aadhaar eSign and DSC. Settlements stop at recorded acceptance, unsigned
- Video calls: the Jitsi driver makes random room links. It needs a self-hosted server in India for production
- Payment gateway: the Razorpay driver makes payment links, and its signed webhook records payments. It needs keys and a webhook set up in Razorpay
- Push sending through Firebase, SMS and live email. Real-time updates in the browser and app use Laravel Reverb (built 2026-11-10)
- Malware scanning. Files record `scan_status = skipped`
- KMS and key escrow. `LocalKeyProvider` is development only, and losing its files loses the documents

### Not built yet

- **Isolation accounts:** the switch-over is built and tested (`IsolationModeTest`). Switching it on needs `pn_app` and `pn_platform`, created from `php artisan pocketnyaya:isolation-grants` with passwords set privately. The T3 write procedures aren't built: T3 rows are written through filtered views instead
- **Retention beyond closed practices:** only the two closed-practice categories are enforced. Audit log retention (the C60 `pn_retention` exemption) and the WORM archive aren't built, so audit rows are kept indefinitely
- **P1 emergency staff access:** rejected, so correctly absent
- **Subscription feature gates:** limits are enforced, but features aren't gated by plan, because no features are defined per plan
- **Screens not built:** comment editing, and comment moderation by report only. The advocate profile form is web-only
- **Tests still PENDING from the approved plans:** TX-C1 to TX-C9 concurrency, V1, the remaining VW and IS cases (a subset runs in `IsolationLayerTest`), PV (needs `pn_app`), DS16 to DS21 as named, CT13 to CT16 for a database-side consent hash, RT, PB and PT

## Unverified

- Nothing has been run on the production MariaDB version (10.11 or 11.4).
- Concurrency (two people issuing invoices, recording payments or writing consent at once) relies on row locks that are **not exercised by the test suite**, which runs in one connection.
- Part 2 backup hasn't been run. Migrations ran on `pocket` without a backup, by the owner's instruction.
