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

Always label a settlement "Settlement agreement (unsigned)". Accepting records agreement to the terms. It isn't a digital signature, and the app must not describe it as a court order or as enforceable.

Mediator tools, joining by invitation and evidence upload are web-only for now.

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
| GET, POST | `/time-entries` | Own time |

## Rules for the app

- **Don't store the token in plain storage.** Use the platform's secure storage.
- **Treat money as strings.** Amounts come back as strings such as `"1180.00"`, and shouldn't be converted to floating point.
- **Don't rank advocates.** The directory and forum must not add ratings, sorting by quality, or "recommended" labels.
- **Show the disclaimers** on the directory, forum, consultation and dispute screens. The wording is at `/disclaimers/{code}` on the web.
