# Database

MySQL 8, `utf8mb4_unicode_ci`. Money is integer minor units (rial) — never a
float. Coordinates are `DECIMAL(10,7)`, which is ~1 cm of precision.

## Entity relationships

```
cities ──┬─ zones ──────────────┐
         ├─ bus_stops ──────────┼──── route_stops ──── routes ──── bus_lines
         ├─ bus_lines ──────────┘                         │
         ├─ operators ─┬─ buses ─┬─ bus_devices           │
         │             │         ├─ bus_qr_codes          │
         │             │         └─ bus_assignments       │
         │             └─ drivers ─┬─ driver_documents    │
         │                         └─ driver_shifts       │
         ├─ merchants ─┬─ merchant_staff                  │
         │             ├─ merchant_terminals              │
         │             ├─ merchant_transactions ── settlements
         │             └─ (wallet)                        │
         ├─ trips ◄────────────────────────────────────────┘
         │    ├─ trip_locations
         │    ├─ trip_events
         │    └─ passenger_trips ─┬─ passenger_boardings
         │                        ├─ passenger_alightings
         │                        └─ passenger_location_pings
         ├─ complaints ─┬─ complaint_messages
         │              └─ complaint_attachments
         ├─ fare_rules
         ├─ daily_metrics
         └─ import_batches

users ──┬─ role_user ── roles ── permission_role ── permissions
        ├─ drivers (1:1)
        ├─ wallets ── wallet_ledger_entries ── wallet_transactions
        ├─ payments
        ├─ push_subscriptions
        ├─ stop_arrival_subscriptions
        └─ audit_logs

routes ── segment_travel_stats   (ETA history, one row per segment × bucket)
```

## Tables

### Identity and access

**`users`** — one account per person, regardless of how many roles they hold.
`uuid` (public id) · `first_name` · `last_name` · `display_name` ·
`mobile` (unique, normalised `98XXXXXXXXXX`) · `mobile_verified_at` ·
`email` · `password` (nullable — OTP-only passengers never set one) ·
`national_code` · `avatar_path` · `locale` · `timezone` · `status` ·
`city_id` · `last_login_at` · `last_login_ip` · `preferences` (JSON, holds
the concession `passenger_type`) · timestamps · soft delete.

**`roles`** — `name` · `label` · `level` · `is_system`.
**`permissions`** — `name` (dotted) · `group` · `description`.
**`permission_role`**, **`role_user`** — the latter carries `city_id`: a role is
granted globally (null) or scoped to exactly one city.

**`otp_codes`** — `mobile` · `code_hash` (only the hash is stored) · `purpose` ·
`attempts` · `expires_at` · `consumed_at` · `request_ip`.

**`audit_logs`** — `user_id` · `city_id` · `action` (dotted) · polymorphic
`auditable` · `before` · `after` · `context` · `ip_address` · `user_agent`.
Append-only: `created_at` with no `updated_at`.

### Network

**`cities`** — `slug` · `name` · `timezone` · `currency` · `locale` ·
`center_lat/lng` · `default_zoom` · `bbox_*` (rejects out-of-city pings) ·
`is_active` · `is_launched`.

**`zones`** — `city_id` · `code` · `name` · `boundary` (GeoJSON ring).

**`bus_stops`** — `city_id` · `zone_id` · `code` · `name` · `name_en` ·
`lat` · `lng` · `address` · `image_path` · `geofence_radius` ·
`has_shelter` · `is_accessible` · `is_terminal` · `is_active` ·
**`provenance`** · `source_ref`.

**`bus_lines`** — `city_id` · `code` · `name` · `color` · `origin_label` ·
`destination_label` · `typical_duration_minutes` · `headway_minutes` ·
`service_start/end` · `is_active` · `provenance`.

**`routes`** — `bus_line_id` · `name` · `direction` · `origin_stop_id` ·
`destination_stop_id` · `geometry` (ordered lat/lng) · `distance_meters` ·
`is_default` · `provenance`.

**`route_stops`** — `route_id` · `bus_stop_id` · `sequence` ·
**`distance_from_start`** · `travel_time_from_previous` · `dwell_seconds` ·
`zone_id` · `is_timepoint` · `allows_boarding` · `allows_alighting`.

> `distance_from_start` is the axis the whole operations layer turns on. With
> every stop and every bus expressed as metres along one line, "which stop is
> next", "how far to it" and "did we just pass one" become comparisons instead
> of repeated geometry. It is derived by `transit:routes:recalculate` and must
> be recomputed whenever a stop moves or geometry is imported.

**`import_batches`** — `city_id` · `entity` · `format` · `source_name` ·
`provenance` · `status` · row counters · `errors` (JSON, capped at 100).

### Fleet

**`operators`** — the company or department owning buses and employing drivers.

**`buses`** — `uuid` · `city_id` · `operator_id` · `bus_number` · `plate` ·
`vin` · `model` · `manufacture_year` · `capacity_seated` · `capacity_standing` ·
`has_air_conditioning` · `is_accessible` · `status` · `current_driver_id` ·
`current_trip_id` (denormalised pointer, deliberately not a foreign key — trips
references buses, so a constraint would make the two mutually dependent) ·
`default_line_id` · `last_ping_at` · `last_lat/lng` · `inspection_due_at`.

**`bus_qr_codes`** — `bus_id` · `public_id` (printed, safe to expose) ·
`secret` (encrypted at rest, never leaves the server) · `version` ·
`is_active` · `activated_at` · `revoked_at` · `revoked_by` · `revoke_reason`.

**`drivers`** — `uuid` · `user_id` (unique) · `city_id` · `operator_id` ·
`employee_code` · `national_code` · `license_number` · `license_class` ·
`license_expires_at` · `status` · `hired_at` · `contract_ends_at` ·
`total_trips` · `total_shift_minutes` · `rating` · `approved_by` ·
`approved_at`. Unique on `(city_id, national_code)`.

**`driver_documents`** — `type` · `file_path` (private disk) · `expires_at` ·
`is_verified` · `verified_by`.

**`bus_assignments`** — `bus_id` · `driver_id` · `bus_line_id` · `starts_on` ·
`ends_on` · `is_active`. This is what authorises a shift.

**`driver_shifts`** — `driver_id` · `bus_id` · `started_at` · `ended_at` ·
`status` · `trip_count` · `passenger_count` · `revenue_minor` ·
`distance_meters` · start/end coordinates.

### Operations

**`trips`** — the fare-bearing unit of vehicle movement.
`uuid` · `city_id` · `bus_id` · `driver_id` · `driver_shift_id` ·
`bus_line_id` · `route_id` · `status` · `scheduled_at` · `started_at` ·
`ended_at` · `origin_stop_id` · `destination_stop_id` ·
`current_lat/lng` · `current_speed_kmh` · `current_heading` ·
**`route_offset_meters`** · `current_stop_id` · `next_stop_id` ·
`next_stop_sequence` · `distance_to_next_stop` · `eta_next_stop_seconds` ·
`passenger_count` · `peak_passenger_count` · `boarding_count` ·
`revenue_minor` · `distance_meters` · `average_speed_kmh` ·
`is_off_route` · `is_idle` · `last_ping_at`.

**`trip_locations`** — append-only, the highest-write table.
`trip_id` · `bus_id` · `driver_id` · `lat` · `lng` · `speed_kmh` · `heading` ·
`accuracy_meters` · `altitude` · `route_offset_meters` ·
`route_deviation_meters` · `nearest_stop_id` · `recorded_at` (device clock) ·
`received_at` (server clock). No Eloquent timestamps. Pruned after 30 days.

**`trip_events`** — `trip_id` · `type` · `bus_stop_id` · `lat/lng` ·
`payload` · `occurred_at`. The audit trail of a journey.

**`segment_travel_stats`** — the ETA memory. One row per
`(route, from_stop, to_stop, day_type, hour_bucket)`:
`sample_count` · `mean_seconds` · **`m2`** (Welford accumulator, so variance
updates in constant time and the raw history is never needed) · `p50_seconds` ·
`p85_seconds` · `traffic_factor` · `last_sample_at`.

### Ridership

**`passenger_trips`** — one passenger's ride aboard one bus trip.
`uuid` · `user_id` · `trip_id` · `bus_id` · `city_id` · `bus_line_id` ·
`route_id` · `status` · `boarding_stop_id` · `alighting_stop_id` ·
`boarded_at` · `alighted_at` · `fare_amount` · `fare_rule_id` ·
`wallet_transaction_id` · `distance_meters` · `stops_travelled` ·
`duration_seconds` · `alighting_confidence` · `alighting_source`.

**`passenger_boardings`** — forensics for the scan that started a ride:
`bus_qr_code_id` · `token_nonce` · position · `distance_to_bus_meters` ·
`device_fingerprint` · `client_ip`. Unique on `(token_nonce, user_id)` — a
second defence against replay, at the database level.

**`passenger_alightings`** — every detection, acted on or not:
`source` · `confidence` · **`signals`** (JSON — the full evidence, so a
disputed charge is reconstructable) · position · `detected_at` ·
`confirmed_at`.

**`passenger_location_pings`** — opt-in, used only to close an open ride.
Pruned after 24 hours.

### Money

**`wallets`** — `uuid` · `owner_type` (user | merchant | system) · `owner_id` ·
`account_ref` (system accounts) · `city_id` · `currency` · **`balance`**
(materialised sum) · `pending_balance` · **`version`** (monotonic posting
counter) · `status` · `daily_spend_limit` · `frozen_at` · `frozen_reason`.

**`wallet_transactions`** — the header. `uuid` · `type` · `status` ·
`currency` · `amount` · **`idempotency_key`** (unique — a retry returns the
original instead of posting twice) · `initiated_by` · `city_id` ·
polymorphic `subject` · `reverses_transaction_id` · `description` ·
`metadata` · `posted_at`.

**`wallet_ledger_entries`** — immutable postings. `wallet_transaction_id` ·
`wallet_id` · `direction` · `amount` · `balance_after` · **`sequence`**
(unique per wallet) · `description` · `metadata` · `created_at` only.

> The invariants: every transaction's postings sum to zero; a wallet's cached
> balance equals the sum of its postings; the system-wide sum is zero.
> `transit:ledger:audit` checks all three.

**`payments`** — gateway top-up attempts. `uuid` · `user_id` · `wallet_id` ·
`wallet_transaction_id` · `gateway` · `status` · `amount` ·
`gateway_reference` · `gateway_authority` · `card_mask` · `gateway_payload` ·
`failure_reason` · `paid_at` · `expires_at`.

**`fare_rules`** — `city_id` · `name` · `code` · `context` · `bus_line_id` ·
`from_zone_id` · `to_zone_id` · `passenger_type` · time and date validity ·
`base_fare` · `per_km_fare` · `min_fare` · `max_fare` · `multiplier` ·
**`priority`** · `is_active`. No fare is hard coded anywhere in the codebase.

**`idempotency_keys`** — generic replay store for non-wallet endpoints.

### Merchants

**`merchants`** — `uuid` · `city_id` · `owner_user_id` · `name` · `code` ·
`type` · `status` · `commission_bps` · `settlement_cycle` · `iban` (hidden) ·
`allows_refund` · `max_transaction_amount` · `approved_by`.

**`merchant_staff`** — `role` · `can_refund` · `can_view_reports`. Server-side
permissions; a cashier and a manager run the same build.

**`merchant_terminals`** — `public_id` · `secret` (encrypted) per till, so one
can be revoked without disturbing the others.

**`merchant_transactions`** — `amount` · `commission_amount` · `net_amount` ·
`reference` · `settlement_id` · `refunded_by` · `refund_transaction_id`.

**`settlements`** — `reference` · `status` · period · `transaction_count` ·
`gross_amount` · `commission_amount` · `refund_amount` · `net_amount` ·
`wallet_transaction_id` · approval chain. Claiming rows by stamping
`settlement_id` is what makes the payout job safe to re-run.

### Support and platform

**`complaints`** — `uuid` · `reference` · `user_id` · `city_id` · `category` ·
`priority` · `status` · `subject` · `body` · full context (`trip_id`,
`bus_id`, `driver_id`, `bus_line_id`, `bus_stop_id`,
`wallet_transaction_id`) · position · SLA timestamps · `resolution_note` ·
`satisfaction_rating`.

**`complaint_messages`** — `author_type` · `body` · **`is_internal`** (never
returned to passenger endpoints).
**`complaint_attachments`** — private disk, served by signed URL only.

**`notifications`** · **`push_subscriptions`** · **`stop_arrival_subscriptions`**
· **`daily_metrics`** (pre-aggregated dashboard facts).

## Indexes

Every index exists for a query that runs in production:

| Index | Serves |
|---|---|
| `bus_stops (city_id, is_active, lat, lng)` | "stops near me" bounding box |
| `trips (city_id, status)` | live fleet for a city |
| `trips (status, last_ping_at)` | the stale-trip reaper |
| `trip_locations (trip_id, recorded_at)` | trip playback |
| `trip_locations (recorded_at)` | retention pruning |
| `segment_stats_bucket_unique` | ETA lookup, one row |
| `wallet_ledger_entries (wallet_id, sequence)` unique | statement order, gap detection |
| `wallet_transactions.idempotency_key` unique | double-post prevention |
| `boardings_nonce_user_unique` | QR replay, at the database |
| `route_stops (route_id, sequence)` unique | ordered stop list |
| `complaints (assigned_to, status)` | an agent's queue |
| `merchant_transactions (merchant_id, settlement_id)` | settlement claim |
| `daily_metrics (city_id, date)` unique | dashboard series |
