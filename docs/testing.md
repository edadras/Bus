# Testing

```bash
php artisan test          # 300 PHP tests
make apps-test            # 57 Dart tests
make apps-analyze         # static analysis across all four Dart packages
```

PHP tests run against in-memory SQLite. Dart tests run on the VM.

## What is tested, and why

Coverage is concentrated where a defect would be expensive rather than spread
evenly. The money and credential paths are tested exhaustively; presentation is
tested only enough to catch a template that renders an empty body.

### Ledger — `tests/Feature/Wallet/LedgerTest.php` (12)

Each test pins one guarantee the ledger claims:

- a posting moves money and its legs sum to zero
- postings record a running balance and a gap-free sequence
- an unbalanced posting is rejected **before anything is written**
- an idempotency key makes a retry return the original transaction
- a wallet cannot be debited below zero, and the balance is untouched on failure
- ledger rows reject updates and deletes
- reversal writes a mirror posting and leaves the original intact
- a transaction cannot be reversed twice
- a frozen wallet cannot be debited
- `verify()` detects a tampered cached balance
- a merchant payment splits commission inside one balanced posting

### QR security — `tests/Unit/QrTokenServiceTest.php` (16)

A copied photograph of a sticker must not be worth a free ride:

- a fresh token verifies; a different secret does not
- tampering with any field invalidates the signature
- a token expires after its window; one step of clock drift is tolerated
- a nonce can be consumed exactly once — the replay case
- releasing a nonce lets a failed boarding retry
- nonces are scoped, so bus and merchant codes cannot collide
- two tokens issued in the same window differ
- malformed payloads are rejected (5 cases)
- a hostile public id resolves to `unknown_code` with the schema intact

### Boarding — `tests/Feature/Ridership/BoardingTest.php` (12)

- a valid scan seats and charges exactly once
- the same token cannot pay for two people
- a passenger cannot board the same bus twice, or hold two open rides
- a parked bus, a revoked code and a remote position are all refused
- insufficient balance leaves no charge and no passenger trip
- a concession fare rule is applied
- rate limiting stops a burst
- the driver-facing counters track boardings

### Alighting — `tests/Feature/Ridership/AlightingTest.php` (13)

The asymmetry is the point — ending a ride early is worse than ending it late:

- a passenger sitting on the bus stays aboard
- ordinary GPS noise (80 m reported at 60 m accuracy) does not end a ride
- one distant reading only marks the ride pending; the seat stays occupied
- it takes two threshold-clearing observations to close, and a lone first
  sample can never be one of them
- returning close to the bus clears a pending state
- manual end is authoritative; closing twice does not double-count
- abandoned rides are force-closed, recent ones are not
- every detection stores the signals that produced it

### Driver authorisation — `tests/Feature/Driver/DriverShiftTest.php` (14)

- an assigned driver can start a shift; an unassigned one cannot
- pending approval, an expired licence and a cross-city bus each block it
- two drivers cannot hold one bus; one driver cannot hold two
- a bus cannot run two trips at once
- ending a shift completes the trip and frees the bus
- completing a trip closes every passenger still aboard
- **a new trip accepts the driver's very first location report** — a regression
  test for a bug that left buses off the live map at the start of service
- a passenger token cannot reach driver endpoints

### ETA — `tests/Feature/Operations/EtaEngineTest.php` (12)

The cases that break naive distance-over-speed maths:

- a stopped bus still produces a finite estimate
- an idle bus is penalised relative to a moving one
- confidence falls with distance and with stale telemetry
- historical segment times are used once enough samples exist
- **sparse history is ignored** — one freak journey must not dominate forever
- Welford's mean and standard deviation are correct for a known series
- implausible observations are discarded
- a passed stop and an off-route stop return no estimate
- the arrival board sorts soonest first

### GPS ingest — `tests/Feature/Operations/LocationIngestTest.php` (13)

- a ping is stored and matched; next stop and distance advance
- arriving at a stop raises exactly one event even while idling there
- poor accuracy, impossible speed, a teleport and a future timestamp are rejected
- rapid pings are throttled without being treated as errors
- **one deviating ping does not raise off-route**; repeated deviation does, and
  recovery clears it
- the reporting cadence adapts to what the bus is doing

### Merchant — `tests/Feature/Merchant/MerchantPaymentTest.php` (12)

- a payment moves money and withholds commission in one balanced posting
- a terminal token cannot be replayed
- suspended merchants, insufficient balance and over-ceiling amounts are refused
- a refund returns the full amount including commission
- a settled transaction cannot be refunded
- **a settlement claims its transactions exactly once**, which is what makes
  the payout job safe to re-run
- approval debits the merchant wallet; rejection releases the claim

### Auth and RBAC — `tests/Feature/Auth/` (26)

- the full OTP flow, including Persian digit normalisation
- only the hash is stored; a code cannot be reused
- a passenger cannot obtain a driver token; a suspended driver is refused
- **login failures do not reveal whether an account exists**
- role permissions, wildcard matching, city scoping
- an admin token is required even for a super admin
- a city-scoped admin is blocked from another city's data

### Public API and web — `tests/Feature/Api/`, `tests/Feature/Web/` (21)

- guests can browse stops, lines, routes and arrival boards
- **provenance is surfaced**, so sample data is never presented as official
- nearby search orders by true distance and honours the radius
- the journey planner finds a direct line and reports honestly when it cannot
- another city's stop is not reachable
- every page renders its body, not just its head; RTL and Persian are declared

### Notifications and push — `tests/Feature/Api/NotificationApiTest.php` (15)

- the inbox lists only the caller's own notifications and reports an unread count
- another account's notification cannot be marked read, and its device cannot be
  unsubscribed by guessing the endpoint
- the VAPID endpoint publishes the public half and never the private one, and
  reports `enabled: false` on a deployment with no keys, so no client raises a
  permission prompt it cannot honour
- re-registering the same endpoint keeps exactly one row and rotates its keys
- a plain-`http` endpoint is refused
- every notification goes out over both the inbox and push, and carries a
  collapse tag so repeats replace rather than stack

### Reports and occupancy — `tests/Feature/Api/AdminReportsTest.php` (11)

- each report counts only its period, and an inverted range is refused
- driver figures roll up from shifts, which is what a duty roster is built from
- **the passenger report contains no identifier of any single rider**
- **occupancy leaks neither identity nor position with exactly one rider aboard**
- revenue needs `finance.manage`; occupancy needs `operations.live_map`; a
  transport manager gets one and not the other

### Network import — `tests/Feature/Network/NetworkImportTest.php` (10)

- stops and lines import from CSV and from an Excel workbook alike
- **provenance travels with the data**: an import without `--provenance=official`
  can never produce official rows
- one malformed row is recorded with its line number and the other rows still land
- re-importing updates rather than duplicating
- a clean import reports 0 skips rather than null — the counters are read back
  from the in-memory batch, so an untouched one must not be blank

### Localisation — `tests/Feature/Web/LocalizationTest.php` (11)

Written after `?lang=en` was found rendering raw dotted keys at the user:

- every key present in one locale is present in the other, and **no key resolves
  to itself** in either
- every `DomainException` code has a message in both locales
- an explicit `?lang=` wins; a phone set to English does not flip a Persian
  rider's interface; a saved user preference is honoured
- **two requests in a row do not contaminate each other's locale** — the guard
  against `App::setLocale()` rewriting the very default the next request reads

### Journey planning — `tests/Feature/Network/JourneyPlannerTest.php` (13)

Tested against a network shaped like a real one — two lines that cross, and a
third sharing no stop but with one across the road:

- a direct journey needs no transfer, and one that does is found
- **a transfer can be a short walk between two stops**, which is how an
  interchange actually works
- capping transfers at zero reports no route rather than inventing one
- the wait for the second bus is part of the estimate
- options are ordered by total time and deduplicated per line combination
- walking is offered when it beats waiting for a bus
- an inactive line, and a stop that forbids boarding, both remove the journey

### SMS delivery — `tests/Feature/Auth/SmsDeliveryTest.php` (11)

The property that matters is not that it delivers — it is that a provider being
down never becomes a failed sign-in:

- a provider error, a network failure and a missing API key are all reported,
  never thrown, and the OTP endpoint still answers 200
- verification codes go through the provider's approved pattern, not free text
- each provider's own success code is treated as authoritative over the HTTP one
- an unknown driver name falls back to the log driver rather than breaking auth

### Fleet assignment — `tests/Feature/Api/FleetAssignmentTest.php` (8)

Without an assignment `Driver::mayOperate()` refuses and no shift can start, so
what the panel shows as in force must be exactly what the server will accept:

- a future assignment is listed but is **not** in force, and an expired one is not
- revoking one stops the driver operating
- a driver is addressed by UUID and the numeric id is never published
- a driver from another city cannot be assigned

### Audit logging — `tests/Feature/Api/AuditLogTest.php` (6)

Written after the logger was found mass-assigning `created_at`, which threw in
every environment except production and took **every** audited admin write down
with it — driver approval, QR regeneration, fare changes, wallet adjustments.
Each test drives an audited path through the API rather than calling the logger,
and one checks that passwords and national codes are redacted while ordinary
fields survive: a redacted-everything log is not an audit.

### Finance administration — `tests/Feature/Api/FinanceAdminTest.php` (14)

- reversal writes a mirror and leaves the original row intact and readable
- an adjustment moves the balance **and** leaves `verify()` reporting balanced —
  the guarantee that no path writes a balance without a ledger entry
- a zero adjustment and an unexplained reversal are both refused
- user lookup matches a mobile in any of the four forms people type it
- **the mobile is masked** unless the caller holds `users.pii.view`
- a two-character search is refused rather than matching half the city
- a support agent reaches neither route

### Driver dossier — `tests/Feature/Api/DriverDossierTest.php` (9)

- the dossier carries documents, assignments and shifts together
- an expired licence is reported as an answer, not two dates to compare
- **a document is served only through a signed URL**: stripping the signature
  returns 403, which is the regression that matters, because the download route
  had been shadowed by the panel's `/admin/{any}` catch-all and was silently
  returning HTML with the signature never checked
- viewing a document is audited; a `.php` upload is refused
- registering a driver works outside production — it did not, because
  `mobile_verified_at` is guarded and was being mass-assigned

### Merchant dossier — `tests/Feature/Api/MerchantDossierTest.php` (9)

- a new merchant arrives usable: a default till and the owner as manager
- **a till's signing secret appears nowhere in the response body**, checked by
  searching the whole payload rather than one key
- the IBAN is reduced to its last four digits, and its absence is reported
  because a settlement cannot be paid without one
- adding staff reuses an existing account rather than duplicating a person
- **no staff mobile appears in full anywhere in the payload** — the check that
  caught `staff.user` being serialised whole beside the masked list

### Complaint workbench — `tests/Feature/Support/ComplaintWorkbenchTest.php` (9)

- attachments are listed, and are reachable **only** through a signed link
- the assignee roster lists colleagues by permission, not by a hardcoded role
  list, and publishes no mobile numbers
- assigning moves a complaint out of `new` and names its owner on the queue
- **an internal note appears in the staff thread and never in the passenger's**

### Network administration — `tests/Feature/Network/NetworkAdminTest.php` (8)

Every arrival estimate is computed from a stop's distance along its route, so
these test that those offsets stay honest:

- creating a route numbers its stops, measures them, and takes its ends from the
  first and last
- **moving a stop rebuilds the offsets of every route it appears on** — an
  unchanged offset there means every ETA past that stop is quietly wrong
- recalculating reports how many stops it touched
- a one-stop route is refused; another city's stop and line are out of reach

### Geometry — `tests/Unit/GeoTest.php` (11)

Tested against known distances rather than against itself: haversine against a
real 11.5 km city pair, one degree of latitude, bearings, segment projection and
clamping, polyline length and snapping, and that the bounding box never
under-covers its radius.

### Localisation, Dart side — `packages/hamsafar_core/test/` (9)


Mirrors the server's parity checks, plus the one that keeps it that way:

- both string tables carry the same keys, and no key resolves to itself
- a gap in English degrades to Persian rather than to a dotted key
- money, distance and numerals follow the active locale — English keeps Latin
  digits, because Persian numerals inside an English sentence are unreadable to
  whoever asked for English
- **no widget in any of the three apps holds an inline Persian string**

### Flutter (57)

`packages/hamsafar_core` (44) — formatters (rial→toman, Persian digits and
separator, mobile normalisation across six input forms, ETA never showing zero
minutes), defensive model parsing including a journey plan with a malformed
option and a walk-only answer and a line's two directions being separate
routes rather than one reversed, and `ApiException` classification including the
distinction between a QR that needs re-scanning and one that is revoked.

App smoke tests build the real widget tree against an in-memory token store and
assert the product rules: the passenger map is reachable signed-out, the wallet
tab is not, and the driver and merchant apps are closed by default.

The journey planner screen (`apps/passenger/test/plan_screen_test.dart`) checks
that an itinerary renders every leg in order with where to board and get off,
that the walk at each end is placed rather than only totalled, that every figure
is labelled an estimate, and that "nothing searched yet", "no stop nearby" and
"no route found" are three distinct states rather than one blank list.

## Conventions

- Test names are sentences describing the guarantee, not the method.
- Failure cases assert the **absence of side effects**, not just the exception.
- Time is manipulated with `travel()`, never by rewriting timestamps — a lesson
  from a test that silently passed because its attribute write was not dirty.
