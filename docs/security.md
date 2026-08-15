# Security and privacy

## Authentication

Passengers and drivers sign in with a mobile OTP; staff use a password. Three
things make the OTP path safe to expose:

- **Only the hash is stored.** A database leak yields no live credentials.
- **Three independent limits.** A 60-second resend cooldown, a 10-per-day cap
  per mobile, and a 5-attempt counter per code. The first two protect the SMS
  bill; the last protects a 5-digit secret.
- **Issuing invalidates the previous code**, so an intercepted older SMS is
  useless.

Login failures are uniform: an unknown mobile and a wrong password return
byte-identical responses, so the endpoint cannot enumerate accounts. There is a
test asserting exactly that.

### Token abilities

The token names its surface. A passenger token literally cannot call a driver
endpoint — the ability gate rejects it before any controller runs.

```php
'driver' => $user->driver !== null && $user->driver->status->canDrive()
    ? [ABILITY_DRIVER, ABILITY_PASSENGER]
    : [],   // no driver record, no driver token
```

Suspending a driver deletes their tokens, so the credential already on their
phone stops working immediately rather than at expiry.

## Authorisation

Two independent layers, both server-side:

1. **Ability** — which app surface the token is for.
2. **Permission + city scope** — `permission:finance.manage` on the route, and
   a check that the user's role covers the request's city.

A role is granted globally or scoped to one city via `role_user.city_id`, which
is what makes the panel safe to hand to a city operator. The admin sidebar
mirrors this by probing the API; it displays authorisation, it never enforces
it.

Eleven roles ship: super admin, admin, transport manager, fleet manager,
driver manager, finance manager, support agent, merchant manager, merchant
staff, driver, passenger.

## QR credentials

A printed sticker alone is trivially defeated: photograph it once and you could
"board" from anywhere. So the sticker carries only a public id, and a valid
scan must present:

```
v1.<public_id>.<time_step>.<nonce>.<hmac>
```

- **Signed** with a per-bus secret, encrypted at rest and never transmitted.
- **Time-stepped** at 30 seconds, with one step of drift tolerance for phone
  clocks.
- **Single-use.** The nonce is claimed with an atomic compare-and-set, so two
  concurrent requests carrying the same token cannot both win.
- **Constant-time comparison**, and failures report a coarse reason only.

Two further defences at the layers below: a unique index on
`(token_nonce, user_id)` in `passenger_boardings`, and an idempotency key on
the fare posting derived from the token. A retried scan cannot post twice even
if everything above it fails.

A nonce is released if the surrounding transaction rolls back, so a failed
boarding does not force the passenger to wait for a fresh code.

> **Operational note.** Replay protection uses the shared cache. With the array
> or file driver it degrades to per-process, so Redis is required in production.

Merchant terminals reuse the same primitive — one audited implementation of
signing, drift and replay defence rather than two.

## Financial integrity

The ledger is the part of the system that must never be wrong, so it is
defended structurally rather than by care:

| Property | How |
|---|---|
| Balanced | Postings are checked to sum to zero before any write |
| Atomic | Header, legs and cached balances in one DB transaction |
| Serialised | Wallets locked `FOR UPDATE` in a stable id order — no deadlock |
| Idempotent | Unique index on `idempotency_key`; a retry returns the original |
| Immutable | Ledger rows reject updates and deletes at the model layer |
| Auditable | Corrections are reversing transactions; history is never edited |
| Verified | `transit:ledger:audit` checks three invariants, nightly |

Balances are a materialised sum, never the source of truth. `verify()` recomputes
from the postings and reports drift.

Anti-fraud on the fare path, in the order it runs — cheapest and most decisive
first: rate limit → signature and freshness → active trip → duplicate/cooldown
→ proximity → price → claim nonce → debit → seat. Money moves last, so any
earlier failure leaves no charge and no phantom passenger.

## Privacy

Location is the most sensitive data here, and it is treated that way.

**Passenger position** is opt-in, collected only during an active ride, used
only to close that ride, and pruned after 24 hours. It is never exposed to
other passengers, and never to staff without `operations.live_map`.

**There is no per-person live map.** `GET /admin/live/occupancy` — the screen
the specification called a live passenger map — publishes counts per vehicle and
nothing else, tagged `privacy_note: aggregated_per_vehicle`. The passenger
reports are the same: rides, active users and frequency buckets, never a row per
person. A test asserts that with exactly one rider aboard, neither their
identifier nor their number appears in the occupancy response.

**Driver position** is the vehicle's position, reported only while a shift is
open. Reporting stops the moment the shift ends.

**Public channels carry no identities.** The city bus channel publishes
position, line and an occupancy ratio. Driver names and passenger counts by
person live on private channels behind an authorisation callback.

**Masking.** `User::maskedMobile()` is used wherever a number is shown outside
the account that owns it — a merchant cashier sees `0912***567`, enough to
recognise a payment, not enough to collect customers.

**Files are private.** Driver licences, ID cards and complaint photos go to the
private disk and are served only through short-lived signed URLs.

**Audit logging** records every sensitive action — driver approval and
suspension, QR regeneration, fare changes, wallet adjustments, reversals,
settlement approval, complaint replies — with actor, IP, before/after, and
sensitive fields redacted.

**Retention**: passenger pings 24 h, bus traces 30 days (configurable), audit
logs indefinite.

## Transport and application hardening

- HTTPS forced in production; HSTS and security headers set at nginx.
- CSRF on session routes; the API is token-authenticated and stateless.
- Every write validated by a form request; every query parameterised by Eloquent.
- No secret is ever returned by an API: QR and terminal secrets are `encrypted`
  casts and `$hidden`; IBANs, national codes and gateway payloads are hidden.
- `preventSilentlyDiscardingAttributes` and `preventLazyLoading` are on outside
  production, so a typo in a mass assignment fails loudly in development.
- The sandbox payment gateway refuses to operate in production.

## Known limitations

Stated plainly rather than left to be discovered:

- **Proximity is advisory.** If a client sends no position, boarding still
  succeeds — indoor GPS is unreliable and refusing fares over it would be worse
  than the residual risk. The signed rotating token remains the proof of
  presence.
- **Alighting is inferred, not measured.** There is no sensor. The engine is
  tuned to fail late rather than early, and anything left open is force-closed
  by the scheduler.
- **The journey planner covers direct lines only.** It reports
  `supports_transfers: false` rather than silently returning nothing.
- **Push is Web Push only.** Browsers and the installable PWA receive pushes;
  native APNs/FCM delivery needs per-store credentials that cannot live in this
  repository. The Flutter apps use the in-app inbox and the live socket instead,
  so no notification is lost — only its out-of-app banner.
- **SMS has no provider bound.** `SmsSender` is a one-method seam; OTP codes are
  logged in non-production rather than sent.
