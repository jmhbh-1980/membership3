# BalleJaune API — Reference for Claude Code

Club: **Club de Squash de La Garenne-Colombes** (ballejaune.com admin panel)

This file documents the BalleJaune REST API (currently in BETA) so that an agent can call it programmatically on behalf of this club. It was transcribed from the in-app API documentation at `https://ballejaune.com/admin` → Réglages → API.

## Where to find the API key

The API key is **not included in this file** (it must stay secret). In the admin panel it lives at:
`Réglages → API → Clé API`. It must be enabled first ("Activer le module API").

Store it outside of source control (e.g. a `.env` file with `BALLEJAUNE_API_KEY=...`) and never commit it or print it in logs.

## Base URL & authentication

- Base URL: `https://ballejaune.com/api/v1`
- All requests must be HTTPS (plain HTTP is rejected).
- Every request must include the header:
  ```
  X-API-Key: YOUR_API_KEY
  ```
- Responses are JSON, UTF-8.

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/users"
```

## Response envelope

Every response has a `success` flag.

Success:
```json
{
  "success": true,
  "data": { "...": "..." }
}
```

Error:
```json
{
  "success": false,
  "error": { "code": 404, "message": "User not found" }
}
```

## HTTP status codes

| Code | Meaning |
|---|---|
| 200 | Success |
| 400 | Invalid parameter |
| 401 | Missing or invalid API key |
| 403 | Access denied (IP not whitelisted, or club user-limit reached) |
| 404 | Resource not found |
| 405 | HTTP method not allowed for this resource |
| 409 | Conflict (e.g. login already taken, or incompatible billing setup — see Payments) |
| 413 | Request body too large (max 64 KB) |
| 429 | Rate limit exceeded (per-minute anti-flood or daily quota). `Retry-After` header gives the wait time in seconds. |
| 500 | Server error |

## Rate limits

- 100 requests / minute
- 5000 requests / day
- Optional IP allowlisting can be configured in the admin panel (max 10 IPv4 addresses) — if enabled, only listed IPs are accepted.

## Data protection note

Data exposed by this API can include personal information (identity, address, phone, birth date, medical certificate, etc.). The club is responsible for GDPR-compliant handling of this data — only use the API in contexts where that responsibility has been established, and do not export/store more personal data than necessary.

## Prohibited/sensitive actions to keep in mind

- The API key itself grants full administrative access (read/write users, ACL profiles, access-control codes, etc.). Treat it like an admin password.
- `DELETE /api/v1/users/{user_id}` moves a user to trash (reversible from the admin UI, not a hard delete). The built-in walk-in ("Client de passage") technical user cannot be deleted (returns 409).
- Refunds/payment corrections are not created through this API — payments are read-only (`GET /api/v1/payments`, `GET /api/v1/payments/{payment_id}`). There is no endpoint here to charge cards, issue refunds, or move money.

---

# Club

## `GET /api/v1/club`
Info about the authenticated club: identity, contact, geolocation, and regional settings (timezone, language, currency, default profiles). Useful to bootstrap a client app in one call.

**Parameters:** none.

**Returns** (`data.club`):

| Field | Type | Description |
|---|---|---|
| club_id | int | Unique club ID |
| name | string | Club name |
| address | string | Postal address |
| city | string | City |
| postalcode | string | Postal code |
| country | string | ISO 3166-1 alpha-2 country code (e.g. FR, BE) |
| phone1 / phone2 | string | Phone numbers |
| email | string | Contact email |
| website | string | Website |
| comment | string | Free text set by the admin (hours, practical info, …) |
| activities | string[] | Club activities. Possible values: tennis, padel, squash, badminton, golf, tabletennis, beachvolley, volleyball, basketball, handball, football, petanque, billard, fitness, other |
| geolocation_lat / geolocation_lng | float\|null | Coordinates |
| timezone | string | IANA timezone (e.g. Europe/Paris) |
| timesystem | string | `auto`, `12h`, or `24h` |
| default_lang | string | Default locale (e.g. fr_FR) |
| default_acl_id | int | Default ACL profile for new users (see `GET /api/v1/roles`) |
| default_subscription_id | int | Default subscription for new users (see `GET /api/v1/subscriptions`) |
| currency | string | ISO 4217 currency (e.g. EUR) |

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/club"
```

---

# Users (`Utilisateurs`)

## `GET /api/v1/users`
List club users.

**Query parameters:**

| Name | Type | Required | Default | Description |
|---|---|---|---|---|
| filters | object (JSON-encoded) | No | — | Keys: `roles`, `subscriptions`, `groups`, `age_categories`, `ranking`, `keywords`. See filters below. |
| user_id | int or int[] | No | — | Single id (`user_id=1234`) or list (`user_id[]=1234&user_id[]=6789`, max 500). |
| search | string (max 100 chars) | No | — | Searches login, first/last name, postal code, city, license number, ranking, emails, phones. |
| limit | int | No | 50 | Between 1 and 200. |
| offset | int | No | 0 | Pagination offset. |

**Filters** (all accept arrays; multiple filters are combined with AND):

- `roles`: array of `acl_id` (see `GET /api/v1/roles`)
- `subscriptions`: array of `subscription_id` (see `GET /api/v1/subscriptions`)
- `groups`: array of `group_id` (see `GET /api/v1/groups`)
- `age_categories`: array of slugs — only values from `GET /api/v1/age-categories` are accepted, anything else returns 400. For this club: `0_4 5_6 7_8 9_10 11_12 13_14 15_16 17_18 19_20 21_22 23_24 25_29 30_39 40_49 50_59 60_69 70_79 80plus`
- `ranking`: array of free-text values (tennis ranking, e.g. `30/2`)
- `keywords`: array of keyword strings, e.g. `{"keywords":["male","active-users","license-valid"]}`. Full list of 52 keywords:

  `flag`, `added-recently`, `added3minus`, `added6minus`, `added12minus`, `added3plus`, `added6plus`, `added12plus`, `updated-recently`, `male`, `female`, `suspended`, `not-suspended`, `with-picture`, `without-picture`, `subscription-paid`, `subscription-unpaid`, `subscription-expired`, `subscription-unexpired`, `active-users`, `active-users3`, `active-users6`, `active-users12`, `active-users24`, `active-users36`, `inactive-users`, `inactive-users3`, `inactive-users6`, `inactive-users12`, `inactive-users24`, `inactive-users36`, `with-card-tickets`, `without-card-tickets`, `with-guest-tickets`, `without-guest-tickets`, `privacy-listing-allow`, `privacy-listing-deny`, `push-notify-enabled`, `phone-sms-verified`, `phone-sms-notverified`, `email-defined`, `email-undefined`, `license-defined`, `license-undefined`, `license-valid`, `license-expired`, `medical-certificate-defined`, `medical-certificate-undefined`, `medical-certificate-less1y`, `medical-certificate-less2y`, `medical-certificate-more1y`, `medical-certificate-more2y`, `prison`

**Returns** — root: `users` (array, current page), `total` (int, matches across all pages), `limit`, `offset`.

**User object fields:**

| Field | Type | Description |
|---|---|---|
| user_id | int | Unique user id |
| acl_id | int | ACL profile (see `GET /api/v1/roles`) |
| subscription_id | int | Subscription (see `GET /api/v1/subscriptions`), 0 if none |
| lastname / firstname | string | Name |
| login | string | Login (unique within club) |
| email / email2 | string | Emails |
| sex | string | `M`, `F`, or empty |
| birthday | date YYYY-MM-DD | Birth date, empty if unset |
| ranking | string | Free-text ranking (e.g. 30/2) |
| ranking_fft_level / ranking_fft_step | string | Split FFT ranking (e.g. "30" / "2") |
| license_number | string | Sport license number |
| license_practice | string | Practiced discipline (tennis, padel, …) |
| license_year | string | License season year |
| license_registration_date / license_expiration_date | date | License validity dates |
| address / postalcode / city / country | string | Postal address (country = ISO alpha-2) |
| lang | string | Preferred locale |
| phone1 / phone2 | string | Phones |
| flag | int | Manual admin flag (0/1) |
| digicode | string | 6-char personal access code (used on some kiosks) |
| book_card_tickets | float | Remaining booking-credit balance |
| book_guest_tickets | float | Remaining guest-credit balance |
| subscription_date_start / subscription_date_end | date | Membership period |
| subscription_paid | int | 1 = paid, 0 = not paid |
| subscription_paid_date | date | Payment date |
| subscription_paid_amount | float | Amount paid (club currency) |
| subscription_notes | string | Free admin notes |
| suspend_date | datetime | `0000-00-00 00:00:00` if not suspended |
| suspend_reason | string | Suspension reason |
| suspend_by | int | Admin user_id who suspended (0 if not suspended) |
| medical_certificate | date | Current medical certificate date |
| medical_certificate_comment | string | Free comment |
| custom1 … custom10 | string | Club-defined custom fields |
| date_create / date_update | datetime | Record creation/update timestamps |

```bash
# Search by name
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/users?search=dupont&limit=10"

# Filter by role + subscription
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  --data-urlencode 'filters={"roles":[5],"subscriptions":[12]}' \
  -G "https://ballejaune.com/api/v1/users"

# Active men with a valid license
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  --data-urlencode 'filters={"keywords":["male","active-users","license-valid"]}' \
  -G "https://ballejaune.com/api/v1/users"

# Multiple users in one call
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/users?user_id[]=1234&user_id[]=6789&user_id[]=1011"
```

## `GET /api/v1/users/{user_id}`
Get a single user by id. Same fields as `GET /api/v1/users`. Returns `data.user`.

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/users/1234"
```

## `POST /api/v1/users`
Create a new user. Body is JSON; `firstname` and `lastname` are required, everything else optional.

If `login` is omitted it's auto-generated from first/last name (random suffix on collision). A random password is always generated server-side — it can never be supplied in the request. If `email` is set, login credentials are emailed to the user.

**Body fields:**

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| firstname | string ≤ 50 | Yes | — | First name |
| lastname | string ≤ 50 | Yes | — | Last name |
| login | string ≤ 50 | No | auto | Allowed chars: letters, digits, `.`, `-`, `_`, space. Auto-generated if absent. |
| email / email2 | email, string ≤ 150 | No | — | Valid email. If `email` is set, credentials are emailed. |
| sex | string | No | — | `M`, `F`, or empty |
| birthday | date YYYY-MM-DD | No | — | Birth date |
| lang | string ≤ 20 | No | club language | Locale code (e.g. fr_FR) |
| acl_id | int | No | — | ACL profile belonging to the club (see `GET /api/v1/roles`) |
| subscription_id | int | No | — | Subscription belonging to the club (see `GET /api/v1/subscriptions`) |
| ranking, ranking_fft_level, ranking_fft_step | string | No | — | Ranking + FFT indicators |
| license_number | string ≤ 15 | No | — | License number |
| license_practice, license_year | string | No | — | Practice type + 4-digit year |
| license_registration_date, license_expiration_date | date YYYY-MM-DD | No | — | License dates |
| address, postalcode, city | string | No | — | Postal address |
| country | string 2-5 | No | — | ISO country code |
| phone1, phone2 | string ≤ 40 | No | — | Phone numbers |
| flag | bool | No | — | Star/flag the user |
| digicode | string ≤ 15 | No | — | Unique access code |
| subscription_date_start, subscription_date_end | date YYYY-MM-DD | No | — | Custom membership dates |
| subscription_paid | bool | No | — | Mark membership as paid |
| subscription_paid_date | date YYYY-MM-DD | No | — | Payment date |
| subscription_paid_amount | decimal ≥ 0 | No | — | Amount paid |
| subscription_notes | string ≤ 1000 | No | — | Notes |
| medical_certificate | date YYYY-MM-DD | No | — | Medical certificate date |
| medical_certificate_comment | string ≤ 500 | No | — | Comment |
| custom1 … custom10 | string ≤ 255 | No | — | Custom fields |
| suspend_date | datetime YYYY-MM-DD HH:MM:SS | No | `0000-00-00 00:00:00` | Empty/null/default = not suspended |
| suspend_reason | string ≤ 255 | No | — | Suspension reason |
| suspend_by | int ≥ 0 | No | — | Admin user_id who suspended (0 = system/unset) |
| prison_date_crime | datetime YYYY-MM-DD HH:MM:SS | No | — | Start of kiosk no-show lockout |
| prison_date_release | datetime YYYY-MM-DD HH:MM:SS | No | — | End of lockout, must be ≥ prison_date_crime |
| prison_reason | string ≤ 255 | No | — | Lockout reason |
| book_card_tickets | decimal | No | — | Booking credits, between -9999 and 9999 |
| book_guest_tickets | decimal | No | — | Guest credits, between -9999 and 9999 |

```bash
curl -X POST -H "X-API-Key: YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{
    "firstname": "Jean",
    "lastname": "Dupont",
    "email": "jean.dupont@example.com",
    "phone1": "0612345678",
    "birthday": "1985-03-12",
    "sex": "M"
  }' \
  "https://ballejaune.com/api/v1/users"
```

Response includes `data.user` and `data.credentials` (`login`, `email_sent`).

## `PATCH /api/v1/users/{user_id}`
Partial update (RFC 7396 merge-patch): only fields present in the body are changed. Same field list as `POST /api/v1/users`, all optional. Unknown keys → 400. Don't repeat `user_id` in the body.

```bash
curl -X PATCH -H "X-API-Key: YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"phone1": "0698765432", "subscription_paid": 1, "subscription_paid_amount": 180.00}' \
  "https://ballejaune.com/api/v1/users/1234"
```

## `DELETE /api/v1/users/{user_id}`
Moves the user to the club's trash (reversible from the admin UI). No body needed. The technical "Client de passage" (walk-in) user cannot be deleted (409).

```bash
curl -X DELETE -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/users/1234"
```
Response: `data = { "user_id": 1234, "trashed": true }`.

## `GET /api/v1/subscriptions`
Valid values for the `subscriptions` filter / `subscription_id` field. No params. Returns `data.subscriptions[]` of `{ subscription_id, name }`.

## `GET /api/v1/roles`
Valid values for the `roles` filter / `acl_id` field. No params. Returns `data.roles[]` of `{ acl_id, name }`.

## `GET /api/v1/groups`
Valid values for the `groups` filter. No params. Returns `data.groups[]` of `{ group_id, name }`.

## `GET /api/v1/age-categories`
Source of truth for the `age_categories` filter (`value` = filter value, `name` = display label). No params. Returns `data.age_categories[]` of `{ value, name }`. Current values for this club:

`0_4, 5_6, 7_8, 9_10, 11_12, 13_14, 15_16, 17_18, 19_20, 21_22, 23_24, 25_29, 30_39, 40_49, 50_59, 60_69, 70_79, 80plus`

---

# Bookings (`Réservations`)

## `GET /api/v1/calendars`
List of the club's calendars (courts). Feeds `calendar_id` used by `/slots` and `/bookings`. No params.

**Fields:** `calendar_id` (int), `calendar_name` (string), `calendar_color` (hex string), `surface` (string\|null), `inoutdoor` (`indoor`\|`outdoor`), `message` (string\|null, may contain HTML), `message_type` (`none`\|`box`\|`modal`\|`all`), `closure_date_start`/`closure_date_end` (date\|null), `closure_label` (string\|null).

## `GET /api/v1/slots`
All slots for every calendar on a given date, with their state. Does not return booking details — only occupancy status.

| Param | Type | Required | Description |
|---|---|---|---|
| date | date YYYY-MM-DD | Yes | Club timezone is used |
| calendar_id | int or int[] | No | Single id or list (max 500). Omit for all calendars. |

**Statuses:** `available` (free), `booked` (≥1 active booking), `closed` (whole calendar closed that date — see closure_* on the parent calendar), `closed_slot` (recurring weekly closure, e.g. Mondays 9am always closed).

Each slot: `calendar_id`, `date_start`, `date_end`, `duration` (min), `status`, `is_available`, `is_booked`, `is_closed`, `bookings_count`, `booking_ids[]`.

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/slots?date=2026-05-15"
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/slots?date=2026-05-15&calendar_id=3"
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/slots?date=2026-05-15&calendar_id[]=1&calendar_id[]=2&calendar_id[]=3"
```

## `GET /api/v1/bookings`
Club bookings, filterable by calendar, period, type, creator, label, user, or cancellation status. For member-type bookings (`member`, `member_alone`, `for_member`, `for_member_alone`, `member_libelle`) the `users[]` array lists participant `user_id`s (guests not included).

Consecutive slots booked by the same user on the same calendar are merged into a single booking (`date_start`/`date_end` span the full run, `duration` in minutes covers it).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| calendar_id | int or int[] | No | all | Max 500 |
| date | date YYYY-MM-DD | Yes | — | Only bookings whose date_start falls that day (club timezone). One call per day. |
| canceled | bool | No | 0 | 0 = active only, 1 = canceled only |
| type | string or string[] | No | — | `club`, `member`, `member_alone`, `for_member`, `for_member_alone`, `member_libelle`, `picture` |
| creator_id | int | No | — | Booking creator's user_id |
| label_id | int | No | — | See `GET /api/v1/labels` |
| user_id | int or int[] | No | — | Only bookings where this user participates |
| order_by | string | No | date_start_asc | `date_start_asc`, `date_start_desc`, `date_create_asc`, `date_create_desc` |
| limit | int | No | 50 | 1–200 |
| offset | int | No | 0 | 0–100000 |

**Returns:** `bookings[]`, `total`, `limit`, `offset`.

**Booking fields:** `booking_id`, `calendar_id`, `creator_id`, `type`, `date_start`, `date_end`, `duration` (min), `label_id` (0 if none), `label_text`, `users[]` (member-type only), `accesscontrol_codes[]` (each: `provider` [`spartime`\|`neop`\|`igloohome`], `code`, and for spartime/igloohome: `date_start`/`date_end`), `guests` (int), `color_bg`, `color_text`, `checking` (-1 not checked, 0 default, 1 checked in), `checking_by`, `checking_date` (datetime\|null), `canceled` (0/1), `canceled_by` (-1 = auto on kiosk check-in, -2 = auto system, -3 = auto — min participants not reached, >0 = user), `date_cancel` (datetime\|null), `date_create`.

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/bookings?date=2026-05-15"
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/bookings?date=2026-05-15&canceled=1&calendar_id[]=1&calendar_id[]=2&order_by=date_create_desc"
curl -X GET -H "X-API-Key: YOUR_API_KEY" "https://ballejaune.com/api/v1/bookings?date=2026-05-15&user_id=1234"
```

## `GET /api/v1/bookings/{booking_id}`
Single booking by id, same fields as above. Returns `data.booking`.

## `GET /api/v1/labels`
Booking tags (group lessons, tournaments, club events, …), for resolving `label_id`. No params. Returns `data.labels[]` of `{ label_id, name, color_bg, color_text }`.

---

# Payments (`Paiements`)

All payment endpoints are **read-only** — there's no way to create charges, capture payments, or issue refunds through this API.

## `GET /api/v1/billers`
Billing accounts (a club can have several: main club, bar, school, …). Resolves `biller_id` from `/payments`. No params.

**Fields:** `biller_id`, `name`, `color`, `is_default` (bool), `gateway` (`none`\|`paypal`\|`verifone`\|`stripe`\|`helloasso`\|`payzen` — name only, no config exposed), `payment_modes[]` (`online`\|`onsite`\|`check`\|`transfer`), `fullname` (legal name), `address1`, `address2`, `postalcode`, `city`, `country` (ISO alpha-2), `phone`, `email`, `vat_id`.

## `GET /api/v1/orders`
Club orders (quotes, orders, invoices — open carts are not exposed), between `date_from` and `date_to` (inclusive, on creation date). Each order embeds its line items and its payments (an order paid in installments lists each collection). Amounts are net of discount: `total_amount` is gross, `vat_amount` is the VAT portion within it.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| date_from | date YYYY-MM-DD | Yes | — | Inclusive lower bound on order creation date |
| date_to | date YYYY-MM-DD | Yes | — | Inclusive upper bound, ≥ date_from |
| user_id | int or int[] | No | — | Max 500 |
| biller_id | int or int[] | No | — | See `GET /api/v1/billers` |
| subscription_id | int or int[] | No | — | See `GET /api/v1/subscriptions` |
| status | string or string[] | No | all | `pending`, `partly_paid`, `paid`, `canceled`, `refused`, `failed`, `refund` |
| type | string or string[] | No | all | `quote`, `order`, `bill` |
| order_by | string | No | date_desc | `date_asc`, `date_desc`, `amount_asc`, `amount_desc` |
| limit | int | No | 50 | 1–200 |
| offset | int | No | 0 | 0–100000 |

**Order fields:** `order_id`, `order_reference`, `invoice_reference`, `type`, `status`, `biller_id`, `user_id`, `subscription_id`, `total_amount`, `vat_amount`, `discount_amount`, `promo_code`, `paid_amount`, `remaining_amount`, `currency`, `date_created`, `date_paid`, `items[]` (`type`, `name`, `quantity`, `amount`, `vat_amount`), `payments[]` (see payment fields below, embedded).

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/orders?date_from=2026-04-01&date_to=2026-04-30"
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/orders?date_from=2026-01-01&date_to=2026-12-31&status[]=pending&status[]=partly_paid"
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/orders?date_from=2026-01-01&date_to=2026-12-31&user_id=6789&type=bill&order_by=amount_desc"
```

## `GET /api/v1/orders/{order_id}`
Full order object (same fields as above), wrapped in `data.order`. 404 if not found or not owned by the club.

## `GET /api/v1/payments`
Payment transactions between `date_from` and `date_to` (inclusive, on transaction creation date). One row = one money movement; an order paid in installments appears once per paid installment.

A refund (full or partial) adds a dedicated row with `status: "refund"` and a **negative** amount, linked to the original transaction via `parent_payment_id` — the original row is never modified. An admin correction (manually recorded payment later corrected) appears the same way as a pair of `completed` rows, one negative and one positive, also linked via `parent_payment_id` — so **a `completed` row with a negative amount is a correction, not a collection**. On data predating this scheme, a full refund may have flipped the original transaction itself to `status: "refund"` with no `parent_payment_id`.

Each payment embeds its order context (references, status, subscription) and the sold items.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| date_from | date YYYY-MM-DD | Yes | — | Inclusive lower bound on transaction creation date |
| date_to | date YYYY-MM-DD | Yes | — | Inclusive upper bound, ≥ date_from |
| user_id | int or int[] | No | — | Max 500 |
| order_id | int or int[] | No | — | |
| biller_id | int or int[] | No | — | See `GET /api/v1/billers` |
| subscription_id | int or int[] | No | — | See `GET /api/v1/subscriptions` |
| status | string or string[] | No | all | `completed`, `pending`, `refused`, `canceled`, `failed`, `refund` |
| method | string or string[] | No | all | `online`, `onsite`, `onsite_cash`, `onsite_card`, `check`, `transfer` |
| order_by | string | No | date_desc | `date_asc`, `date_desc`, `amount_asc`, `amount_desc` |
| limit | int | No | 50 | 1–200 |
| offset | int | No | 0 | 0–100000 |

**Payment fields:** `payment_id`, `order_id`, `order_reference`, `invoice_reference`, `order_status`, `order_total_amount`, `order_vat_amount`, `biller_id`, `user_id`, `subscription_id`, `gateway`, `method`, `status`, `amount`, `currency`, `external_transaction_id`, `date_created`, `date_completed`, `parent_payment_id` (int\|null), `items[]` (`type`, `name`, `quantity`, `amount`, `vat_amount`).

```bash
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/payments?date_from=2026-04-01&date_to=2026-04-30"
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/payments?date_from=2026-04-01&date_to=2026-04-30&user_id=1234&status=completed&order_by=amount_desc"
curl -X GET -H "X-API-Key: YOUR_API_KEY" \
  "https://ballejaune.com/api/v1/payments?date_from=2026-01-01&date_to=2026-12-31&status=pending&method[]=check&method[]=transfer"
```

## `GET /api/v1/payments/{payment_id}`
Full payment object (same fields as above), wrapped in `data.payment`. 404 if not found or not owned by the club.

---

# Practical tips for building integrations

- **Pagination**: `/users`, `/bookings`, `/orders`, `/payments` all use `limit`/`offset` plus a `total` in the response — loop by incrementing `offset` by `limit` until `offset >= total`.
- **Date-scoped endpoints**: `/slots` and `/bookings` take a single `date` (one day at a time — loop over days for a range). `/orders` and `/payments` take a `date_from`/`date_to` range instead.
- **Resolving IDs to names**: use `GET /api/v1/roles`, `/subscriptions`, `/groups`, `/age-categories`, `/calendars`, `/labels`, `/billers` to map the various `*_id` fields returned elsewhere to human-readable names — cache these, they change rarely.
- **Rate limiting**: back off on 429 using the `Retry-After` header; stay well under 100 req/min and 5000 req/day.
- **Never log or print the API key** in full; if it may have leaked, regenerate it from the admin panel (this immediately invalidates the old one).
