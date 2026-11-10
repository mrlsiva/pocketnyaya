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
| 410 | The file has been permanently erased |
| 422 | Validation failed, or the record's status doesn't allow this |
| 429 | Too many attempts |
| 503 | An outside service (payments, video, eSign and so on) isn't set up yet. The body names it in `integration` |

## Endpoints

### Public (no token)

| Method | Path | Purpose |
|---|---|---|
| GET | `/ping` | Health check |
| GET | `/directory/advocates` | Search verified, listed advocates |
| GET | `/directory/advocates/{id}` | One advocate's public details |
| GET | `/forum/questions` | Published questions |
| GET | `/forum/questions/{id}` | A question with its answers, oldest first |
| POST | `/auth/register`, `/auth/login`, `/auth/forgot-password`, `/auth/reset-password` | Accounts |

### Account

| Method | Path | Purpose |
|---|---|---|
| GET, PATCH | `/me` | Own profile |
| GET | `/me/workspaces` | Personal account and practices |
| POST, DELETE | `/me/devices`, `/me/devices/{id}` | Register or remove a push token. **Push sending isn't built yet** |
| GET | `/me/notifications` | Latest 50 |
| POST | `/me/notifications/{id}/read` | Mark as read |
| POST | `/auth/logout`, `/auth/email/resend` | Session and verification |

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
| GET | `/practice/documents` **T** | Practice documents you can see |
| POST | `/matters/{id}/documents` **T** | Upload to a matter |

### Consultations and urgent help

| Method | Path | Purpose |
|---|---|---|
| GET, POST | `/consultations` | Own requests |
| GET | `/consultations/{id}` | One request |
| POST | `/consultations/{id}/messages` | Message the advocate |
| POST | `/emergency-requests` | Urgent help |

### Forum

| Method | Path | Purpose |
|---|---|---|
| POST | `/forum/questions` | Ask. Screening may block (422) or hold it for review (`status: flagged`) |
| POST | `/forum/questions/{id}/answers` | Verified advocates only |
| POST | `/forum/reports` | Report a post |

### Privacy

| Method | Path | Purpose |
|---|---|---|
| GET | `/privacy/consents` | Purposes and current status |
| POST | `/privacy/consents/{id}` | `action: grant` or `withdraw` |
| GET, POST | `/privacy/requests` | Access, correction, erasure, grievance or nomination |

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
| POST | `/odr/invitations/{token}/accept`, `/odr/invitations/{token}/decline` | Join or decline. The signed-in email must match the invitation (403 if not) |

**Private messages.** In `/odr/disputes/{id}/messages`, a party sends `caucus: true` to write to the mediator only. This works only after the mediator has opened a private conversation with that party (403 before then). In `GET /odr/disputes/{id}`, `my_participant_id` and `is_mediator` tell the app which view to show.

Always label a settlement "Settlement agreement (unsigned)". Accepting records agreement to the terms. It isn't a digital signature, and the app must not describe it as a court order or as enforceable.

### Online dispute resolution (mediators)

| Method | Path | Purpose |
|---|---|---|
| GET | `/odr/assignments` | Disputes proposed to me |
| POST | `/odr/assignments/{id}` | `decision: accept` or `decline`, with a required `conflict_disclosure` |
| POST | `/odr/disputes/{id}/messages` | With `private_with: <participant id>` to open or continue a private conversation |
| POST | `/odr/disputes/{id}/sessions` | Schedule: `scheduled_at`, `duration_minutes`, `mode` (`video`, `audio`, `in_person`). No meeting link yet |
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
| POST | `/hearings/{id}/outcome` | Record an outcome |
| GET | `/tasks`, `/diary`, `/diary/conflicts` | My tasks and diary |
| PATCH | `/tasks/{id}` | Update a task's status |
| GET | `/knowledge` | Knowledge vault |
| GET, POST | `/invoices` | List, or create a draft |
| GET | `/invoices/{id}` | Detail. On a restricted matter, `redacted` is true and descriptions are null |
| POST | `/invoices/{id}/lines`, `/invoices/{id}/issue`, `/invoices/{id}/payments` | Billing steps |
| POST | `/invoices/{id}/time` | Add approved, unbilled time: `entries` (list of IDs) and `hourly_rate` |
| POST | `/invoices/{id}/expenses` | Add reimbursable expenses: `expenses` (list of IDs). Pure-agent expenses are refused for now |
| GET | `/invoices/{id}/pdf` | The issued invoice as a PDF. 422 for drafts, 403 if you only see it redacted |
| GET, POST | `/time-entries` | Own time |
| GET | `/team` | Members with their roles, the roles you may grant, and pending invitations |
| POST | `/team/invitations` | Invite: `email`, `role` |
| DELETE | `/team/invitations/{id}` | Revoke an invitation |
| PUT | `/team/{userId}/role` | Change a member's role: `role` |
| DELETE | `/team/{userId}` | Remove a member from this practice only. Their account and other practices are untouched |

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
