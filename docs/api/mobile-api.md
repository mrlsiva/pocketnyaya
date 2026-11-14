# PocketNyaya mobile API (v1)

For developers building the Flutter app. The base path is `/api/v1`. All requests and responses are JSON, except file uploads and downloads.

## Sign-in

1. Call `POST /auth/login` with `email`, `password` and `device_name`. The response holds a bearer token.
2. Send `Authorization: Bearer <token>` on every later request.
3. Tokens last 14 days. They are revoked on logout, password reset, suspension or erasure.

To register, call `POST /auth/register` with `name`, `email`, `password`, `password_confirmation` and `consent: true`. Show the same consent wording as the web form. The email must be verified before most endpoints work.

## Working in a practice

Personal endpoints need no extra header. For practice endpoints, marked **T** below, send `X-Tenant-ID: <tenant id>`. Get the IDs from `GET /me/workspaces`.

## Status codes

| Code | Meaning |
|---|---|
| 400 | The `X-Tenant-ID` header is missing or invalid |
| 401 | Not signed in |
| 403 | Allowed to see this, but not to do this |
| 404 | Not found, or outside your access. The two look the same on purpose |
| 410 | Gone: the file has been permanently erased, or a practice export has expired |
| 422 | Validation failed, or the record's status doesn't allow this |
| 429 | Too many attempts |
| 502 | An outside service is set up but failed or refused this request. The body names it in `integration` |
| 503 | An outside service (payments, video, eSign and so on) isn't set up yet. The body names it in `integration` |

## Endpoints

### Public (no token)

| Method | Path | Purpose |
|---|---|---|
| GET | `/ping` | Health check |
| GET | `/directory/advocates` | Search verified, listed advocates |
| GET | `/directory/advocates/{id}` | One advocate's public details |
| GET | `/forum/questions` | Published questions |
| GET | `/forum/questions/{id}` | A question with its answers, oldest first, and published comments on each (without names) |
| POST | `/auth/register`, `/auth/login`, `/auth/forgot-password`, `/auth/reset-password` | Accounts |
| POST | `/webhooks/razorpay` | For Razorpay only, not the app. Checked by signature, and 404 unless Razorpay is switched on |

### Account

| Method | Path | Purpose |
|---|---|---|
| GET, PATCH | `/me` | Own profile |
| GET | `/me/workspaces` | Personal account and practices |
| POST, DELETE | `/me/devices`, `/me/devices/{id}` | Register or remove a push token. **Push sending isn't built yet** |
| GET | `/me/notifications` | Latest 50 |
| POST | `/me/notifications/{id}/read` | Mark as read |
| POST | `/auth/logout`, `/auth/email/resend` | Session and verification |
| POST | `/practices` | Open a practice: `type` (`solo` or `firm`), `name`, `state_code`. Without an advocate profile, also `bar_council_id`, `enrollment_number`, `enrollment_date` and `city_id`. Returns the practice id to send as `X-Tenant-ID` |
| GET | `/practice-exports` | Exports of a closing practice where you are the named recipient |
| GET | `/practice-exports/{id}/download` | The export as a ZIP. 410 once it has expired |

### Verification (advocates and mediators)

| Method | Path | Purpose |
|---|---|---|
| GET | `/me/verification` | My advocate and mediator status, requests and uploaded files |
| POST | `/me/verification/advocate` | Submit for verification. The advocate profile must be completed on the web first |
| POST | `/me/verification/mediator` | Apply as a mediator: `accreditation_body`, `accreditation_number`, optional `accreditation_valid_until` and `categories` |
| POST | `/me/verification/{requestId}/documents` | Upload a supporting file as multipart: `file`, `title`, `category: verification` |
| PUT | `/me/directory-listing` | `listed: true` or `false`. Verified advocates only |

### Documents

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/vault/documents` | Personal vault. Upload as multipart with `file`, `title` and `category` |
| GET, DELETE | `/documents/{id}` | Details, or remove |
| GET | `/documents/{id}/download` | The file itself. There are no public links |
| GET, POST | `/vault/folders` | Personal folders, or create one: `name`. Send `folder_id` when uploading |
| POST | `/vault/documents/{id}/versions` | A new version: `file`, optional `change_note` |
| POST | `/vault/documents/{id}/shares` | Share a vault file: `recipient_id`, `permission` (`view` or `download`), optional `expires_at`. Only people in the document detail's `share_recipients` qualify |
| DELETE | `/vault/shares/{id}` | Revoke a share |
| GET, POST | `/practice/documents` **T** | Practice documents you can see, or upload a general one |
| POST | `/matters/{id}/documents`, `/clients/{id}/documents` **T** | Upload to a matter or a client. `confidentiality: privileged` needs the right to see privileged material (403 otherwise) |
| POST | `/practice/documents/{id}/versions` **T** | A new version |
| DELETE | `/practice/document-shares/{id}` **T** | Revoke a share |
| GET, POST | `/practice/folders` **T** | Practice folders, or create one |

### Consultations and urgent help

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/consultations` | Own requests |
| GET | `/consultations/{id}` | One request |
| POST | `/consultations/{id}/messages` | Message the advocate |
| POST | `/consultations/{id}/cancel` | Cancel while it is requested, accepted or scheduled |
| POST | `/emergency-requests` | Urgent help |
| GET | `/emergency/assigned` | Verified advocates: urgent requests assigned to me |
| PUT | `/emergency/responder` | Verified advocates: `contact_phone` and `active`, to join or leave the responder list |

### Forum

| Method | Path | Purpose |
|---|---|---|
| POST | `/forum/questions` | Ask. Screening may block (422) or hold it for review (`status: flagged`) |
| POST | `/forum/questions/{id}/answers` | Verified advocates only |
| POST | `/forum/reports` | Report a post |
| GET | `/forum/mine` | My questions, in any status |
| PUT | `/forum/questions/{id}` | Edit my question: `title`, `body`, optional `practice_area_id`. It is screened again |
| PUT | `/forum/answers/{id}` | Edit my answer: `body`. Verified advocates only, and screened again |
| POST | `/forum/comments` | `type` (`forum_question` or `forum_answer`), `id`, `body`. May be held for review |

### Privacy

| Method | Path | Purpose |
|---|---|---|
| GET | `/privacy/consents` | Purposes and current status |
| POST | `/privacy/consents/{id}` | `action: grant` or `withdraw` |
| GET, POST | `/privacy/requests` | Access, correction, erasure, grievance or nomination |
| POST | `/privacy/requests/{id}/withdraw` | Withdraw a request that hasn't started |
| GET | `/privacy/export` | A copy of my data as JSON |

### Online dispute resolution (parties)

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/odr/disputes` | Own disputes, or start one. Needs `accept_disclaimer: true` and optional `answers` |
| GET | `/odr/disputes/{id}` | Details, messages, offers and the settlement |
| POST | `/odr/disputes/{id}/respondent` | Invite the other party |
| POST | `/odr/disputes/{id}/messages` | Send a message |
| POST | `/odr/disputes/{id}/offers` | Make an offer |
| POST | `/odr/disputes/{id}/offers/{offerId}` | `decision: accept` or `reject` |
| POST | `/odr/disputes/{id}/settlements/{settlementId}` | `decision: accept` or `decline` |
| GET | `/odr/disputes/{id}/documents/{documentId}` | Download evidence or the unsigned PDF |
| POST | `/odr/disputes/{id}/evidence` | Upload evidence as multipart with `file` and `title`. It can't be deleted |
| POST | `/odr/disputes/{id}/counsel` | Invite your own counsel: `name`, `email` |
| DELETE | `/odr/disputes/{id}/counsel/{participantId}` | Remove your counsel |
| POST | `/odr/disputes/{id}/withdraw` | Claimant only: withdraw the dispute |
| POST | `/odr/invitations/{token}/accept`, `/odr/invitations/{token}/decline` | Join or decline. The signed-in email must match the invitation (403 if not) |

**Private messages.** In `/odr/disputes/{id}/messages`, a party sends `caucus: true` to write to the mediator only. This works only after the mediator has opened a private conversation with that party (403 before then). In `GET /odr/disputes/{id}`, `my_participant_id` and `is_mediator` tell the app which view to show.

Always label a settlement "Settlement agreement (unsigned)". Accepting records agreement to the terms. It isn't a digital signature, and the app must not describe it as a court order or as enforceable.

### Online dispute resolution (mediators)

| Method | Path | Purpose |
|---|---|---|
| GET | `/odr/assignments` | Disputes proposed to me |
| POST | `/odr/assignments/{id}` | `decision: accept` or `decline`, with a required `conflict_disclosure` |
| POST | `/odr/disputes/{id}/messages` | With `private_with: <participant id>` to open or continue a private conversation |
| POST | `/odr/disputes/{id}/sessions` | Schedule: `scheduled_at`, `duration_minutes`, `mode` (`video`, `audio`, `in_person`). The mediator creates the video link on the web; it comes back as `meeting_ref`, an `https://` address, once the video service is on |
| POST | `/odr/disputes/{id}/sessions/{sessionId}/meeting` | Create the video link. 503 until the video service is on. Parties see it as `meeting_ref` in the session list |
| PUT | `/odr/disputes/{id}/sessions/{sessionId}` | `status: held`, `cancelled` or `no_show`, with an optional `summary` |
| POST | `/odr/disputes/{id}/settlements` | Generate the unsigned agreement from a reviewed template (`template_id`, `terms`). Templates are listed in the dispute detail |
| POST | `/odr/disputes/{id}/settlements/{settlementId}/void` | Withdraw a settlement no one has fully accepted |
| POST | `/odr/disputes/{id}/close` | Close without settlement, with `closure_reason` |

### Practice (**T**)

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/clients` | List or create |
| GET, PATCH | `/clients/{id}` | One client |
| GET, POST | `/matters` | List or create |
| GET, PATCH | `/matters/{id}` | One matter |
| POST | `/matters/{id}/assignments`, `/matters/{id}/notes`, `/matters/{id}/hearings` | Work on a matter |
| DELETE | `/clients/{id}`, `/matters/{id}` | Remove. 422 under a legal hold |
| POST | `/matters/{id}/close`, `/matters/{id}/reopen`, `/matters/{id}/archive` | Change a matter's status |
| GET | `/matters/{id}/allocation` | Allocation matrix: each member's availability, practice areas, open matters and capacity, best first. Needs the right to allocate |
| GET | `/matters/{id}/assignments` | The current team |
| DELETE | `/matters/{id}/assignments/{assignmentId}` | Take someone off the matter |
| PUT | `/matters/{id}/billing-restriction` | `restricted: true` or `false`. Managing Partner only |
| GET, POST | `/matters/{id}/parties` | Parties: `party_role`, `name`, optional `is_our_client`, `counsel_name`, `contact` |
| POST | `/matters/{id}/tasks` | `title`, `priority`, optional `assigned_to_membership_id` and `due_at` |
| DELETE | `/notes/{id}`, `/hearings/{id}` | Remove a note or a hearing |
| POST | `/hearings/{id}/outcome` | Record an outcome |
| GET | `/tasks`, `/diary`, `/diary/conflicts` | My tasks and diary |
| GET, POST | `/diary/entries` | My meetings, reminders and deadlines for two weeks from `from`, or add one |
| DELETE | `/diary/entries/{id}` | Remove my entry |
| POST | `/diary/conflicts/{id}/resolve` | Optional `resolution_note` |
| GET | `/calendar` | The firm calendar for two weeks. Matters you can't see show as "Engaged" |
| PATCH | `/tasks/{id}` | Update a task's status |
| GET, POST | `/knowledge` | Knowledge vault, or add a draft: `item_type`, `title`, optional `summary`, `tags` (list), `practice_area_id`, `source_matter_id`, `file` |
| POST | `/knowledge/{id}/approve`, `/knowledge/{id}/archive` | Partners only |
| GET | `/knowledge/{id}/download` | The attached file |
| POST | `/ai/draft`, `/ai/summary` | Drafting assistant: `document_type` and `facts`, or `text`. Returns `text` and a `notice` to show with it. 503 until the AI service is on. Nothing is stored |
| GET, POST | `/invoices` | List, or create a draft |
| GET | `/invoices/{id}` | Detail. On a restricted matter, `redacted` is true and descriptions are null |
| POST | `/invoices/{id}/lines`, `/invoices/{id}/issue`, `/invoices/{id}/payments` | Billing steps |
| POST | `/invoices/{id}/time` | Add approved, unbilled time: `entries` (list of IDs) and `hourly_rate` |
| POST | `/invoices/{id}/expenses` | Add reimbursable expenses: `expenses` (list of IDs). Pure-agent expenses are refused for now |
| GET | `/invoices/{id}/pdf` | The issued invoice as a PDF. 422 for drafts, 403 if you only see it redacted |
| DELETE | `/invoices/{id}`, `/invoices/{id}/lines/{lineId}` | Remove a draft, or a line from a draft |
| POST | `/invoices/{id}/schedule-items`, `/invoices/{id}/retainers` | Add a scheduled fee (`fee_schedule_item_id`, `quantity`) or a retainer period (`fee_arrangement_id`, `period`) |
| POST | `/invoices/{id}/credit-notes` | `amount` (tax included) and `reason`. Returns the new credit note |
| POST | `/invoices/{id}/cancel` | `cancel_reason` |
| POST | `/invoices/{id}/payment-link` | Returns `url` and `reference`. 503 until the payment gateway is on |
| GET | `/invoices/{id}/payments` | Payments applied to this invoice |
| POST | `/payments/{id}/reverse` | Reverse a payment, such as a bounced cheque |
| GET | `/reports` | Totals, billable hours by member and fees by lead advocate for a financial year (`fy=2026` means April 2026 to March 2027) |
| GET, POST | `/fees` | Fee arrangements with their scheduled fees, or add one |
| POST | `/fees/{id}/end`, `/fees/{id}/items` | End an arrangement, or add a scheduled fee |
| DELETE | `/fee-items/{id}` | Remove a scheduled fee that isn't on an invoice yet |
| GET | `/retainers` | Retainer plans and work items |
| POST | `/retainers/plans`, `/retainers/plans/{id}/toggle` | Add a plan (`scope_items` is a list), or retire and restore one |
| POST | `/retainers/work` | Add a work item to a retainer |
| PUT | `/retainers/work/{id}` | `status`: `open`, `in_progress` or `done` |
| GET, POST | `/time-entries` | Own time |
| GET | `/time-entries/team` | Time to review: everyone's for finance and partners, supervisees' for senior associates. Optional `status` |
| DELETE | `/time-entries/{id}` | Remove my draft entry |
| POST | `/time-entries/{id}/approve` | Approve a draft entry |
| GET, POST | `/expenses` | Expenses you can see, or log one: `matter_id`, `expense_date`, `category`, `amount`, optional `is_reimbursable` and `is_pure_agent` |
| GET | `/practice/consultations` | Consultation requests to me, or to the whole practice for partners |
| GET | `/practice/consultations/{id}` | Detail, messages, my private notes, and what I may do |
| POST | `/practice/consultations/{id}/respond` | `status`, optional `scheduled_at` |
| POST | `/practice/consultations/{id}/messages` | Message the client |
| PUT | `/practice/consultations/{id}/notes` | My private notes: `lawyer_private_notes` |
| POST | `/practice/consultations/{id}/convert` | Make it a matter |
| GET | `/team` | Members with their roles, the roles you may grant, and pending invitations |
| POST | `/team/invitations` | Invite: `email`, `role` |
| DELETE | `/team/invitations/{id}` | Revoke an invitation |
| PUT | `/team/{userId}/role` | Change a member's role: `role` |
| DELETE | `/team/{userId}` | Remove a member from this practice only. Their account and other practices are untouched |
| PUT | `/team/{userId}/capacity` | `max_active_matters`, or null for no limit |
| GET, PUT | `/practice/settings` | Practice details, branches, groups, my availability and practice areas, the plan and its usage, and what I may change. PUT updates the details |
| POST | `/practice/settings/branches`, `/practice/settings/groups`, `/practice/settings/availability` | Add a branch, a practice group, or my leave |
| PUT | `/practice/settings/practice-areas` | My practice areas: `practice_area_ids` (list). The allocation matrix uses them |
| GET | `/practice/audit` | The practice audit log: optional `from`, `to` and `event`. 50 per page |

## Real-time updates

The app can listen for small signals instead of polling. They use Laravel Reverb, which speaks the Pusher protocol, so any Pusher-compatible Flutter client works.

| Setting | Value |
|---|---|
| Host, port, scheme | From the server's `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` |
| App key | `REVERB_APP_KEY`. It's public, unlike the secret |
| Channel sign-in | `POST /api/v1/broadcasting/auth` with the bearer token, `channel_name` and `socket_id` |
| Event name | `update` |

| Channel | Who may join | Signal `type` values |
|---|---|---|
| `private-users.{yourUserId}` | Only you | `matter.assigned`, `task.assigned`, `consultation.message`, `odr.message` |
| `private-tenants.{tenantId}.diary` | Firm members who may see hearings | `hearing.scheduled`, `hearing.outcome`, `diary.conflict` |

Each signal carries ids only, such as `{"type": "task.assigned", "data": {"task_id": "...", "matter_id": "..."}}`. Fetch the details through the normal endpoints. Signals are best effort, so refresh on app resume too. **Push notifications through Firebase aren't sent yet**, because they need a Firebase account.

## Rules for the app

- **Don't store the token in plain storage.** Use the platform's secure storage.
- **Treat money as strings.** Amounts come back as strings such as `"1180.00"`, and shouldn't be converted to floating point.
- **Don't rank advocates.** The directory and forum must not add ratings, sorting by quality, or "recommended" labels.
- **Show the disclaimers** on the directory, forum, consultation and dispute screens. The wording is at `/disclaimers/{code}` on the web.
