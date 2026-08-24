<div dir="rtl">

# همسفر — سامانه هوشمند حمل‌ونقل شهری و کیف پول یکپارچه

پلتفرم جامع حمل‌ونقل شهری: ردیابی زنده اتوبوس، تخمین هوشمند زمان رسیدن،
پرداخت کرایه با QR، تاکسی (خطی، دربست و تاکسی‌متر)، سرویس مدارس، و کیف پول
شهری قابل استفاده در استخر، باشگاه، پارکینگ و سایر پذیرندگان طرف قرارداد.
شهر نخست: **بندرعباس**؛ معماری از ابتدا چندشهری است.

</div>

## What this is

| Surface | Stack | Path |
|---|---|---|
| Backend & API | Laravel 12, PHP 8.3, MySQL 8, Redis 7, Reverb | `app/`, `routes/` |
| Passenger app | Flutter (Android + iOS) | `apps/passenger` |
| Bus driver app | Flutter (Android + iOS) | `apps/driver` |
| Merchant app | Flutter (Android + iOS) | `apps/merchant` |
| Taxi driver app | Flutter (Android + iOS) | `apps/taxi_driver` |
| School service driver app | Flutter (Android + iOS) | `apps/school_driver` |
| Shared app package | Dart — API client, models, design system, realtime | `packages/hamsafar_core` |
| Admin panel | Blade + Alpine + Tailwind v4, RTL | `resources/views/admin` |
| Landing page & public viewer | Blade, installable PWA | `resources/views` |

Persian-first throughout: RTL layout, Persian digits and thousands separator.
Nothing user-facing is written inline anywhere — the server, the admin panel,
the landing page and every app read from `lang/fa` / `lang/en` and their Dart
counterparts. Three tests fail the build if a Persian string is typed into a
view, a script or a widget, or if the two locales drift apart.

## Quick start

```bash
cp .env.example .env
docker compose up -d
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
npm install && npm run build
```

Then:

- Landing page — <http://localhost:8000>
- Public map viewer — <http://localhost:8000/app/passenger>
- Admin panel — <http://localhost:8000/admin> (`989120000001` / `password`)

Development sign-in returns the OTP in the API response, so no SMS gateway is
needed locally. Seeded accounts:

| Role | Mobile |
|---|---|
| Super admin | `989120000001` |
| Finance manager | `989120000003` |
| Support agent | `989120000004` |
| Driver (approved) | `989130000001` |
| Driver (pending approval) | `989130000008` |
| Passenger (with balance) | `989140000001` |
| Merchant manager | `989150000001` |
| Taxi driver | `989131000001` |
| School service driver | `989132000001` |
| School company manager | `989160000001` |

The seed also leaves one driver awaiting approval and one school company
awaiting it, so both gates are visible on a fresh install rather than only in a
test. The passenger on `989140000001` has two children on a school service, at
two different schools, because a family with one child hides the fact that
contracts are per child.

### Mobile apps

```bash
cd packages/hamsafar_core && flutter pub get
cd ../../apps/passenger && flutter pub get

# 10.0.2.2 is how the Android emulator reaches the host
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

Release builds: `make apk API_URL=https://api.example.com`

## The parts worth knowing about

**The wallet is a double-entry ledger.** Balances are a materialised sum, never
the source of truth. Every posting balances to zero, ledger rows reject updates
and deletes at the model layer, and a correction is a new reversing transaction
rather than an edit. `php artisan transit:ledger:audit` verifies all three
invariants and runs nightly.

**QR codes rotate and are single-use.** The sticker in a bus carries only a
public id; a valid scan must present an HMAC-signed token bound to a 30-second
time step and a nonce that is claimed atomically. A photograph of the code is
worthless seconds later, and cannot pay for two people.

**ETA is deterministic, and says how sure it is.** Each remaining segment
blends live speed, the historical mean for that segment in this (day, hour)
bucket, and a route baseline; dwell time is added per intervening stop. Every
estimate carries a confidence, and the clients visibly soften an unreliable one
instead of presenting it as fact.

**Alighting detection is deliberately conservative.** Ending a ride early is
worse than ending it late, so a single distant GPS fix only marks a ride
pending; it takes two corroborating observations to close one, and the
scheduler force-closes anything left open.

**A taxi's mode belongs to the shift, not the car.** The same vehicle runs a
fixed line in the morning and takes charters in the afternoon, so what it is
offering lives on the driver's open shift and can change without ending
anything. A line fare is flat, a charter is a price the driver names and the
passenger confirms *exactly*, and a metered fare is computed from the car's own
position reports — never the passenger's phone, which can be off, in a bag, or
spoofed. Riders see taxis near them, coloured by mode; the control room sees
every car in the city with the plate and who is aboard. Those are two different
endpoints on purpose.

**A school van's position belongs to the families it is carrying.** Reporting
starts when the run starts and stops when it ends, and within a run a family
sees it only until their own child's journey is over — three conditions,
enforced in one method. The driver checks each child on and off at the door
with the van's position attached, which is what turns "the driver said so" into
a record a parent can check.

**Sample data is labelled everywhere.** The bundled Bandar Abbas network is
illustrative, not official, and carries `provenance = sample` all the way to
the passenger's screen. See [`database/data/bandar-abbas/README.md`](database/data/bandar-abbas/README.md).

## Documentation

| Document | Contents |
|---|---|
| [Architecture](docs/architecture.md) | System, backend, apps, admin, real-time, Redis, scaling |
| [Database](docs/database.md) | ERD, every table and column, relationships, indexes |
| [API reference](docs/api.md) | All 200 endpoints, envelope, error codes, auth |
| [Security](docs/security.md) | Auth, RBAC, QR, financial integrity, privacy |
| [Domain design](docs/domain.md) | Wallet, fare, GPS, ETA, ridership, merchant, complaints |
| [Frontend](docs/frontend.md) | Design system, screens, PWA, admin |
| [Operations](docs/operations.md) | Docker, deployment, monitoring, backup, runbook |
| [Testing](docs/testing.md) | Strategy, coverage, how to run |
| [Roadmap](docs/roadmap.md) | v1 → v3, including the ETA evolution |

## Commands

```bash
php artisan transit:import <entity> <file> --provenance=official  # CSV/XLSX/GeoJSON
php artisan transit:routes:recalculate                            # re-snap stops
php artisan transit:ledger:audit                                  # financial integrity
php artisan transit:metrics:rollup                                # dashboard facts
php artisan transit:rides:close-abandoned                         # reap open rides
php artisan transit:trips:close-stale                             # reap dead trips
php artisan transit:prune:locations                               # retention
php artisan webpush:vapid                                         # push key pair
```

## Tests

```bash
php artisan test                       # 409 PHP tests, 3553 assertions
make apps-test                         # 84 Dart tests
make apps-analyze                      # static analysis, all six packages
```
