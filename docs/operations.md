# Operations

## Development

```bash
cp .env.example .env
docker compose up -d
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
npm install && npm run dev
```

Six services, all of them load-bearing:

| Service | Role | Without it |
|---|---|---|
| nginx | TLS, static files, WebSocket upgrade | — |
| php | FPM, the API and web surfaces | — |
| queue | notifications, rollups, deferred work | notifications never send |
| scheduler | reaps rides and trips, rollups, pruning, ledger audit | rides never close, tables grow |
| reverb | WebSocket | the live map falls back to polling |
| mysql, redis | durable and hot state | — |

Skipping the queue and scheduler looks fine until a bus stops moving on screen
or a ride never closes.

## Deployment

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan queue:restart          # workers pick up the new code
sudo systemctl reload php8.3-fpm
```

The scheduler needs one cron entry:

```
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

### Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set
- [ ] **`OTP_TESTING_CODE` empty** — otherwise a fixed code is accepted
- [ ] `PAYMENT_GATEWAY` is a real gateway, not `sandbox`
- [ ] **Redis is the cache store** — QR replay protection depends on it
- [ ] Reverb keys generated; `REVERB_SCHEME=https`
- [ ] VAPID pair generated once (`php artisan webpush:vapid`) and **never
      rotated afterwards** — a new pair invalidates every stored subscription.
      Leaving it empty disables push cleanly; nothing else breaks
- [ ] TLS terminated, HSTS on
- [ ] **Every seeded password rotated**; demo seeder does not run in production
- [ ] Storage symlink created, private disk not web-reachable
- [ ] Queue worker and scheduler supervised
- [ ] Backups scheduled and a restore tested

### Supervisor

```ini
[program:hamsafar-queue]
command=php /var/www/html/artisan queue:work redis --tries=2 --backoff=5 --max-time=3600
numprocs=4
autorestart=true
stopwaitsecs=3600      ; let an in-flight job finish

[program:hamsafar-reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
numprocs=1
autorestart=true
```

## Monitoring

**Health.** `GET /up` for liveness. A deeper check should confirm MySQL, Redis
and Reverb.

**Signals that matter, and what they mean:**

| Signal | Watch for | Meaning |
|---|---|---|
| Live bus count vs. open shifts | a growing gap | drivers reporting but pings rejected |
| GPS ingest p95 latency | > 300 ms | route matcher or database under strain |
| `gps_*` rejection rate | a spike | a bad app build or a spoofing attempt |
| Failed boardings by code | `qr_replayed` rising | clock drift, or an attack |
| Ledger audit exit code | non-zero | **page immediately** |
| Queue depth | sustained growth | add workers |
| Reverb connections | a cliff | clients silently fell back to polling |
| `pending_alighting` count | growth | alighting thresholds need tuning |
| Complaint first-response time | rising | support under-staffed |
| Discarded taxi meter samples | a rising share | bad fixes, a bad build, or spoofing |
| Rides ended `by system` | any | meters running past their ceiling |
| Force-closed taxi shifts | a rising count | drivers not closing shifts; cars linger on the map |
| Unpaid taxi fares | growth | the meter's start-balance guard is set too low |
| School runs scheduled each night | zero | `school:runs:schedule` failed; drivers wake to no manifest |
| School runs still open past the cutoff | any | a van is sharing its position with nobody watching |

**Logs.** `stderr` from every container. OTP delivery has its own channel and
**never logs the code body in production**.

**Ledger integrity** runs nightly at 04:00 and exits non-zero on any drift.
Treat that as a page, not a warning.

**The school scheduler is the one job whose failure is silent until morning.**
`school:runs:schedule` runs at 02:00 and builds two days ahead, so a single
missed night is absorbed — but two are not, and the symptom is a driver opening
the app to an empty day. Alert on a night that produced zero runs.

## Backup

| Data | Method | Frequency | Retention |
|---|---|---|---|
| MySQL | `mysqldump --single-transaction` | hourly incremental, daily full | 30 days |
| MySQL binlogs | continuous | — | 7 days (point-in-time recovery) |
| Uploads (`storage/app`) | object storage sync | daily | 90 days |
| Redis | not backed up | — | rebuilt from MySQL |

Redis is deliberately excluded: live bus state is disposable, and the queue is
rebuilt by re-running the schedule. Backing it up would imply a durability
guarantee that is not intended.

**Restore drill** — run it quarterly, not just when it is needed:

```bash
mysql < backup.sql
php artisan transit:ledger:audit     # must pass before serving traffic
php artisan transit:routes:recalculate
php artisan cache:clear
```

## Scheduled work

| When | Command | Why it matters |
|---|---|---|
| every 5 min | `transit:rides:close-abandoned` | a stuck ride blocks the next boarding |
| every 10 min | `transit:trips:close-stale` | dead trips linger on the live map |
| every minute | `transit:notify:arrivals` | a late arrival alert is a useless one |
| every 30 min | `school:runs:close-stale` | an open run is a van still sharing its position |
| hourly | `taxi:shifts:close-stale` | runaway meters keep billing; open shifts keep fare codes live |
| hourly | payments:expire-stale | an abandoned gateway payment must not complete later |
| 00:20 | `transit:metrics:rollup --days=2` | dashboard facts, with a self-healing backfill |
| 02:00 | `school:runs:schedule` | builds the school day before it starts |
| 03:30 | `transit:prune:locations` | retention |
| 04:00 | `transit:ledger:audit` | financial integrity — **page on non-zero** |
| 05:00 | `school:contracts:bill` | the period's invoices, and ageing unpaid ones |

Every one of them is `withoutOverlapping()` and `onOneServer()`, so running
several app servers does not run them several times. The two that create things
— run scheduling and invoicing — are idempotent besides, because "it ran twice"
must never mean a duplicated run or a double-billed family.

## Runbook

**Taxis missing from the passenger's map.** The public feed needs a position
from the rider's phone and returns nothing without one, so check that first.
Then check `TAXI_LIVE_TTL` against the drivers' actual reporting cadence: a car
whose last report is older than the TTL drops off the map by design.

**A parent says they cannot see the van.** Three conditions have to hold, and
each has a different remedy: the run must have been *started* by the driver
(not merely scheduled), the child must not already be dropped off, and the
child must be on that run's manifest. All three refuse with distinct error
codes — `trip_not_live`, `child_journey_finished`, `not_your_student` — so the
one that fired says which.

**Buses missing from the live map.** Check the driver app is reporting
(`trip_locations` recent rows), then Redis (`live:city:*:trips`), then Reverb.
Clients poll as a fallback, so the map keeps working with a dead socket — the
symptom is staleness, not absence.

**A driver cannot start a shift.** The refusal code says why:
`bus_not_assigned`, `license_expired`, `driver_not_active`,
`bus_already_in_service`, `city_mismatch`. Each is a data fix in the admin
panel, not a code problem.

**A passenger was charged twice.** They almost certainly were not — the
idempotency key prevents it. Check `wallet_transactions` for the token's key.
If a genuine duplicate exists, reverse it (`finance.manage`), which writes a
mirror posting and leaves the history intact.

**Ledger audit failed.** Do not run `--fix` first. Identify whether the drift is
in a cached balance (repairable from the postings) or in an unbalanced
transaction (a bug — capture it before touching anything).

**Rides not closing.** Check the scheduler is running, then
`transit:rides:close-abandoned` manually, then whether
`transit.ridership.alighting_confidence_threshold` is too high for the local
GPS environment.

**Database growing fast.** `trip_locations` dominates. Confirm
`transit:prune:locations` is running and consider lowering
`TRANSIT_GPS_RETENTION_DAYS`.
