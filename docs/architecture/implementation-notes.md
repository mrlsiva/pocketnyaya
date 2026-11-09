# Implementation notes

Details added while building that the approved architecture doesn't state, and what remains unverified. Nothing here changes an approved decision. Each provisional item needs review.

## Environment

MariaDB 10.4.32 (database `pocket`) is a **temporary development environment**, used with synthetic data only. MySQL 8.4 remains the approved target. **Compatibility with MySQL 8.4 has not been verified.**

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
| `SimplePdf` | A small text-only PDF writer, with no new package | Western characters only |

## MariaDB 10.4 substitutions

These need re-checking on the approved target.

| Approved | Used here |
|---|---|
| `FOR SHARE` | `LOCK IN SHARE MODE` in `pn_assert_assignment` and the tax overlap triggers |
| Consent `DEFINER` procedure (MY-8) | `ConsentLedger` in PHP, under a locked user row. **The append-only triggers are the only database-level protection until separate accounts exist** |

## Approved items not built

### Blocked on a decision or missing definition

- **`tenant_offboarding`**, the 11th tenancy table. **No definition exists anywhere in the approved design.** Closing a practice is disabled until there is one: `platform.tenants.close` has no route, and `tenants.status = closed` can't be set from the screens.
- **Audit storage (MY-18).** Built as option B, unpartitioned. The U4 decision was recorded as a recommendation, so please confirm it.

### Needs credentials, legal checks or later phases

- AI drafting, summarisation and PII masking (the section O tables aren't migrated)
- NJDG or eCourts sync
- Aadhaar eSign and DSC. Settlements stop at recorded acceptance, unsigned
- Video calls. Sessions are scheduled, with no meeting link
- Payment gateway. Payments are recorded by hand
- Real-time updates, push sending, SMS and live email
- Malware scanning. Files record `scan_status = skipped`
- KMS and key escrow. `LocalKeyProvider` is development only, and losing its files loses the documents

### Not built yet

- **MySQL isolation layer:** `pn_access_context`, tenant and personal views, T3 write procedures, separate database accounts
- **Retention enforcement:** rules can be drafted and approved, but no job applies them. The C60 exemption for append-only tables is also not built, and neither is partition or archive maintenance
- **P1 emergency staff access:** rejected, so correctly absent
- **Super Admin propose and approve** (`platform.superadmin.*`) and staff role assignment screens. New staff must be set up by a developer
- **Subscription enforcement:** plans can be managed, but limits and feature gates aren't applied
- **Screens not built:** forum `update_own`, comment moderation by report only, retainer plans and retainer work items (tables exist), fee schedule items (table exists), generating invoice lines from time entries, invoice PDF (browser print only), credit notes
- **Tests still PENDING from the approved plans:** TX-C1 to TX-C9 concurrency, V1, VW, IS, DS16 to DS21 as named, CT13 to CT16 on MySQL, RT, PB and PT

## Unverified

- Nothing has been run on MySQL 8.4.
- Concurrency (two people issuing invoices, recording payments or writing consent at once) relies on row locks that are **not exercised by the test suite**, which runs in one connection.
- Part 2 backup hasn't been run. Migrations ran on `pocket` without a backup, by the owner's instruction.
