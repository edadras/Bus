# Roadmap

## Version 1 — shipped

Everything in this repository:

- Multi-city network model with provenance tracking and CSV/GeoJSON import
- Rotating, signed, single-use QR credentials for buses and merchant tills
- Double-entry wallet ledger with idempotency, immutability and a nightly audit
- Rule-driven fare engine with concessions and time windows
- GPS ingest with plausibility filtering, route matching and adaptive cadence
- Deterministic ETA with confidence scoring and rolling per-segment history
- Multi-signal alighting detection biased to fail late
- Merchant payments with in-posting commission split, and claim-based settlement
- Complaints with photos, threaded replies and internal triage notes
- Taxis in three products — a fixed line, a charter priced by the driver and
  confirmed exactly by the passenger, and a meter run from the car's own
  position reports — with a public "near me" feed and a separate operations
  feed, driver settlement, and colour-coded modes everywhere they appear
- School transport end to end: administrator-approved companies, per-child
  contracts from request through fee, seat and invoice, a driver's manifest with
  positional check-in, and live tracking bounded to the families a van is
  currently carrying
- Five native Flutter apps on a shared package
- Admin panel with live operations map, aggregated occupancy, four report
  families, taxi and school transport sections, and RBAC scoped per city
- In-app notification inbox plus Web Push delivery with VAPID
- Journey planning with transfers, walking interchanges and headway-aware cost
- Persian and English throughout — server, web and apps — guarded by parity tests
- Persian-first, RTL, installable PWA, Docker environment

## Version 1.1 — near term

| Item | Why |
|---|---|
| Firebase project for native push | The FCM sender, the channel and the client seam all ship; a deployment supplies its own service-account key and `google-services.json` |
| SMS provider contract | Kavenegar and SMS.ir drivers ship; a deployment supplies an API key and a pattern id |
| Real payment gateway | `PaymentGateway` interface is implemented and tested against the sandbox |
| Bandar Abbas official data | Replace the sample import with `--provenance=official` |
| Scheduled timetables | `bus_lines` already carries headway and service hours |
| Driver document expiry alerts | Data is captured; the notification is not wired |
| iOS TestFlight builds | Manifests and permissions are in place |
| School company self-service portal | The roles, scoping and endpoints ship; a company manager currently uses the same panel an administrator does |
| Taxi dispatch (hail from the app) | The fleet, the modes and the live feed are in place; matching a rider to a car is the missing half |

## Version 2 — learned ETA

The v1 engine was built so this is a contained change. `EtaEngine::segmentSeconds()`
is the only function that needs replacing; accumulation, dwell handling,
confidence and every caller stay as they are.

**Inputs already being collected**: per-segment travel times bucketed by day
type and hour with mean and variance, dwell durations, boarding counts by stop
and hour, off-route and idle events, and speed profiles.

**Approach.** Gradient-boosted regression per segment, features: hour, day type,
recent segment speed, upstream congestion, occupancy, weather. Trained offline,
served behind the same interface with the deterministic engine as the fallback
whenever the model is unavailable or its confidence is low.

**Measurable target.** Predicted-versus-actual error is already recorded in
`daily_metrics.avg_eta_error_seconds`, so the improvement is falsifiable rather
than assumed.

Also in v2:

- **Crowding prediction** — telling a passenger the next bus is full and the one
  after is not.
- **Zone-based fares** — the schema supports them; the UI does not yet.
- **Metered fare estimates** — the same segment history that improves an ETA
  can tell a passenger roughly what a metered ride will cost before they take
  it, which is the one thing the meter cannot promise today.
- **School route optimisation** — the collection order is currently distance
  from the school, which is honest but not optimal; the pickup positions and
  the realised run times to learn from are already stored.

## Version 3 — predictive operations

Turning the same data outward, toward the people running the network:

- **Demand forecasting** by line, stop and hour, from ridership history
- **Headway optimisation** — recommending frequency changes with the evidence
- **Fleet dispatch** — flagging where an extra bus is needed before the queue forms
- **Anomaly detection** on routes, dwell times and revenue patterns
- **Passenger-facing predictions** — "leave five minutes later, the 22:10 is quieter"

## Platform directions

- **More cities.** Adding one is data, not code: a city row, a network import,
  buses, drivers, fare rules.
- **More merchant categories** — the wallet is already generic.
- **Card issuance** for riders without smartphones, using the same ledger.
- **Open data** — a GTFS and GTFS-Realtime feed, once the network data is
  official rather than sample.
