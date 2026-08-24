# Architecture

## 1. System

```
  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐
  │ Passenger │ │Bus driver │ │ Merchant  │ │Taxi driver│ │  School   │
  │  Flutter  │ │  Flutter  │ │  Flutter  │ │  Flutter  │ │  driver   │
  └─────┬─────┘ └─────┬─────┘ └─────┬─────┘ └─────┬─────┘ └─────┬─────┘
        │             │             │             │             │
  ┌────────────┐      └─────────────┴──────┬──────┴─────────────┘
  │ Landing /  │                           │
  │ Web viewer ├───────────────────────────┤
  └────────────┘                           │
  ┌────────────┐                           │
  │ Admin (SPA)├───────────────────────────┤
  └────────────┘                           │
                     ┌─────────────────────▼────────────┐
                     │  nginx — TLS, static, WS upgrade │
                     └───────┬──────────────────┬───────┘
                             │                  │
                   ┌─────────▼────────┐   ┌─────▼─────────┐
                   │  Laravel (FPM)   │   │ Reverb (WS)   │
                   │  REST /api/v1    │   │ live channels │
                   └────┬────────┬────┘   └─────▲─────────┘
                        │        │              │
             ┌──────────▼──┐  ┌──▼──────────────┴──┐
             │   MySQL 8   │  │      Redis 7       │
             │  durable    │  │ cache · live state │
             │  history    │  │ queue · rate limit │
             └─────────────┘  └──┬─────────────────┘
                                 │
                       ┌─────────▼──────────┐
                       │ queue · scheduler  │
                       └────────────────────┘
```

Five native apps, one web viewer and one admin panel — every one of them
speaks the same `/api/v1`. No surface has a privileged path, so an
authorisation bug cannot hide behind "the admin panel does it differently".

## 2. Backend

A modular monolith. Deliberately not microservices: the domains here share
transactional boundaries (a boarding writes a passenger trip, a ledger posting
and a trip counter in one transaction), and splitting them would trade a real
guarantee for an imagined one.

```
app/
├── Domain/                 business logic, framework-light
│   ├── Identity/           users, RBAC, OTP, audit
│   ├── Network/            cities, zones, stops, lines, routes, import
│   ├── Fleet/              buses, QR credentials, drivers, shifts
│   ├── Operations/         trips, GPS ingest, route matching, ETA, live state
│   ├── Ridership/          boarding, alighting confidence
│   ├── Taxi/               three products, meter, taxi shifts, settlement
│   ├── SchoolTransport/    companies, contracts, routes, runs, attendance
│   ├── Wallet/             double-entry ledger, fare engine
│   ├── Payment/            gateway abstraction, top-ups
│   ├── Merchant/           terminals, payments, settlement
│   ├── Support/            complaints
│   ├── Analytics/          dashboard aggregation, rollups, reports
│   ├── Mapping/            provider abstraction
│   └── Notifications/      arrival subscriptions, Web Push channel
├── Http/                   thin: controllers, requests, resources, middleware
├── Console/Commands/       operational + scheduled work
└── Support/                geometry, money, API envelope, exceptions
```

Each module owns `Models`, `Services`, `Enums`, `Events`, `DTO`. Controllers
validate, delegate to one service, and shape a response — they hold no rules.

### Request lifecycle

```
request
  → EnsureJsonResponse      forces JSON negotiation on /api
  → ResolveLocale           Persian-first; explicit ?lang wins
  → ResolveTenantCity       binds City into the container
  → throttle:<bucket>       public / auth / otp / financial / telemetry
  → auth:sanctum            token
  → abilities:<surface>     passenger | driver | taxi_driver
                            | school_driver | merchant | admin
  → permission:<name>       RBAC + city scope (admin routes)
  → controller → service → ApiResponse envelope
```

## 3. Mobile apps

One shared package, five thin apps:

```
packages/hamsafar_core/   apps/{passenger, driver, merchant, taxi_driver, school_driver}
├── api/       ApiClient, TransitApi, ApiException
├── models/    parsed defensively; a stray null never crashes a screen
├── theme/     the design system, mirroring the web tokens
├── storage/   TokenStore — platform keychain, not preferences
├── realtime/  Pusher-protocol client for Reverb, degrades to polling
├── providers/ Riverpod: config, api, auth, map config
└── widgets/   GlassCard, StatusBadge, StatTile, OtpLoginView, AppScaffold
```

State is Riverpod. Every live screen has two sources — a socket subscription
and a timer — because a live map that silently freezes is worse than one that
updates slowly.

The two driver apps are separate products rather than modes of one app,
because their jobs share almost nothing: a taxi driver watches a fare code and
a meter, a school driver works a manifest. They share the package, the design
system and the API client, and nothing else.

## 4. Admin panel

A single Alpine component (`resources/js/admin.js`) with client-side routing
and lazy per-screen loading. The sidebar is filtered by what the API actually
authorises for the signed-in user; it mirrors server-side authorisation and
never enforces it.

## 5. Real-time

```
driver app ──POST /driver/location──► LocationIngestService
                                        ├─ plausibility filter
                                        ├─ route match → offset, next stop
                                        ├─ stop / off-route / idle events
                                        ├─ ETA observation → segment stats
                                        ├─ Redis live state (TTL 180s)
                                        └─ BusLocationUpdated ──► Reverb ──► clients
```

Broadcast events are `ShouldBroadcastNow`: a position that arrives ten seconds
late is worse than useless, and the payload is already materialised.

Channels:

| Channel | Visibility | Carries |
|---|---|---|
| `transit.city.{id}.buses` | public | position, line, occupancy ratio |
| `transit.trip.{id}` | public | one trip's position and status |
| `private-transit.trip.{id}.crew` | driver + ops | passenger count changes |
| `private-wallet.user.{id}` | that user only | balance changes |
| `private-transit.control.{cityId}` | `operations.live_map` | full fleet detail |

Nothing identifying a person is ever published on a public channel.

## 6. Redis

| Use | Key | TTL |
|---|---|---|
| Live bus state | `live:trip:{id}` | 180s |
| City live index | `live:city:{id}:trips` | 720s |
| QR replay window | `qr:nonce:{scope}:{code}:{nonce}` | 180s |
| ETA board cache | `eta:stop:{id}:…` | 20s |
| Segment statistics | `eta:stat:…` | 120s |
| Off-route streak | `trip:{id}:off_route_streak` | 600s |
| Stop arrival marker | `trip:{id}:arrived:{stop}` | 900s |
| Rate limiters | Laravel-managed | per window |
| Queue, sessions | Laravel-managed | — |

MySQL holds everything durable. Redis holds what is hot and disposable — with
one exception worth stating plainly: **QR replay protection depends on a shared
cache**. With the array or file driver it degrades to per-process, so Redis is
not optional in production.

## 7. Multi-city

`city_id` is on every tenant-owned table, `ResolveTenantCity` binds the city per
request from the `X-City` header (or the user's home city), and `BelongsToCity`
gives every model a `forCity()` scope. Roles are granted globally or scoped to
one city via `role_user.city_id`.

Adding a city is data, not code: create the city, import its network, create
buses and drivers, define its fare rules.

## 8. Scaling

The load is asymmetric — writes are dominated by GPS ingest, reads by map
polling — so the two are separated:

- **Ingest** is a single indexed insert plus one cached-geometry route match.
  Route stops and polylines are cached, so the per-ping cost does not grow with
  network size.
- **Map reads** never touch the fact tables. They read the Redis live state, so
  the cost of a thousand viewers is a thousand cache reads.
- **Dashboards** read `daily_metrics`, filled nightly; only the current day is
  computed live.
- **Retention** prunes `trip_locations` after 30 days and passenger pings after
  24 hours, which keeps the largest tables bounded.

Horizontal growth: FPM, queue and Reverb are all stateless and scale by
replica count. MySQL grows read replicas first; `trip_locations` is the
partitioning candidate if a single city ever outgrows one node.
