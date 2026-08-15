# Testing

```bash
php artisan test          # 205 PHP tests
make apps-test            # 36 Dart tests
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

### Localisation — `tests/Feature/Web/LocalizationTest.php` (8)

Written after `?lang=en` was found rendering raw dotted keys at the user:

- every key present in one locale is present in the other, and **no key resolves
  to itself** in either
- every `DomainException` code has a message in both locales
- an explicit `?lang=` wins; a phone set to English does not flip a Persian
  rider's interface; a saved user preference is honoured
- **two requests in a row do not contaminate each other's locale** — the guard
  against `App::setLocale()` rewriting the very default the next request reads

### Geometry — `tests/Unit/GeoTest.php` (11)

Tested against known distances rather than against itself: haversine against a
real 11.5 km city pair, one degree of latitude, bearings, segment projection and
clamping, polyline length and snapping, and that the bounding box never
under-covers its radius.

### Flutter (36)

`packages/hamsafar_core` — formatters (rial→toman, Persian digits and
separator, mobile normalisation across six input forms, ETA never showing zero
minutes), defensive model parsing, and `ApiException` classification including
the distinction between a QR that needs re-scanning and one that is revoked.

App smoke tests build the real widget tree against an in-memory token store and
assert the product rules: the passenger map is reachable signed-out, the wallet
tab is not, and the driver and merchant apps are closed by default.

## Conventions

- Test names are sentences describing the guarantee, not the method.
- Failure cases assert the **absence of side effects**, not just the exception.
- Time is manipulated with `travel()`, never by rewriting timestamps — a lesson
  from a test that silently passed because its attribute write was not dirty.
