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

**Logs.** `stderr` from every container. OTP delivery has its own channel and
**never logs the code body in production**.

**Ledger integrity** runs nightly at 04:00 and exits non-zero on any drift.
Treat that as a page, not a warning.

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

## Runbook

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
