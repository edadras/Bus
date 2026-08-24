# API reference

Base URL `/api/v1`. Versioned from the outset so a breaking change can ship as
`/v2` while installed apps keep working against `/v1`.

## Envelope

Every response has the same shape:

```json
{ "success": true,  "data": …, "meta": { "pagination": { … } } }
{ "success": false, "data": null, "error": { "code": "…", "message": "…", "details": { … } } }
```

`error.code` is stable and machine-readable; `error.message` is translated
Persian for display. Clients branch on the code, never on the message.

## Headers

| Header | Purpose |
|---|---|
| `Authorization: Bearer <token>` | Sanctum token |
| `X-City: <slug>` | City scope; defaults to the user's home city |
| `X-Locale: fa\|en` | Overrides the response language |
| `Accept: application/json` | Forced by middleware if absent |

## Authentication

Two-step OTP for passengers and drivers; password for staff. The issued token
carries **abilities** matching the client surface, so a passenger token cannot
reach a driver endpoint even if the request is hand-crafted.

| Client | Ability | Granted when |
|---|---|---|
| `passenger` | `passenger` | always |
| `driver` | `driver`, `passenger` | an approved driver record exists |
| `taxi_driver` | `taxi_driver`, `passenger` | an approved driver record exists |
| `school_driver` | `school_driver`, `passenger` | an approved driver record exists |
| `merchant` | `merchant` | active merchant staff |
| `admin` | `admin` | the user holds a staff role |

Token lifetimes: admin 12 h, merchant 7 d, driver 30 d, passenger 180 d.

A bus driver, a taxi driver and a school driver are separate abilities even
when the same person holds all three: they are different products, and a taxi
token must not open a bus shift.

```
POST /auth/otp/request     { mobile }                      → { expires_in, debug_code* }
POST /auth/otp/verify      { mobile, code, client }        → { token, abilities, user }
POST /auth/login           { mobile, password, client }    → { token, abilities, user }
GET  /auth/me                                              → { user, abilities }
POST /auth/logout          { all_devices? }
```

`debug_code` is populated only outside production, so the apps and the test
suite work without an SMS gateway.

## Public — no account required

A passenger must be able to plan a journey before signing up.

```
GET /map/config                     city, tile provider, viewport, bounds
GET /stops                          ?lat&lng&radius&q&limit
GET /stops/{stop}                   stop plus the lines that call there
GET /stops/{stop}/arrivals          the arrival board  ?line_id&limit
GET /lines                          ?q
GET /lines/{line}                   line with its routes
GET /routes/{route}                 ?with_geometry=1
GET /journey/plan                   ?from_lat&from_lng&to_lat&to_lng
GET /buses/live                     ?line_id&lat&lng&radius
GET /trips/{trip}                   trip plus stop-by-stop progress
GET /trips/{trip}/eta/{stop}
GET /summary                        headline counters
GET /push/key                       VAPID public key + whether push is enabled

GET /taxis/nearby                   ?lat&lng&radius&service_type   — position required
GET /taxi/lines                     ?q
```

`GET /taxis/nearby` is a "near me" feed and nothing more. A position is
mandatory, the radius is capped server-side, and the payload carries no plate,
no driver and no passenger count. A city-wide feed of every taxi with its
driver would be a tracking service for taxi drivers, which is not what a rider
asking "what is near me" is owed. The operations feed is a different endpoint
behind `operations.live_map`.

Every stop, line and route carries `provenance` and `is_verified_data`, so no
client can present sample geometry as the published network.

An arrival:

```json
{
  "trip_uuid": "…", "bus_number": "102", "line_code": "102",
  "destination": "دانشگاه هرمزگان", "passenger_count": 27,
  "eta": { "seconds": 240, "minutes": 4, "confidence": 0.82,
           "source": "blended", "stops_away": 3, "reliable": true }
}
```

`reliable: false` means the UI must present the figure as approximate.

## Any signed-in client — `auth:sanctum`, no ability required

A driver wants shift alerts and a merchant wants settlement alerts just as much
as a passenger wants an arrival alert, so these are not ability-gated.

```
GET    /notifications               inbox   ?unread=1&per_page
GET    /notifications/unread-count
POST   /notifications/read-all
POST   /notifications/{id}/read

POST   /push/subscriptions          { endpoint, keys:{p256dh,auth}, platform?, device_name? }
DELETE /push/subscriptions          ?endpoint=…
```

Every notification is written to the inbox regardless of whether push or SMS
also fired, so the list is complete for a device that never granted push. Both
notification and subscription queries are scoped to the caller: an id is a UUID
and an endpoint is a URL, but ownership is what actually keeps one account out
of another's inbox — or from silencing another's device.

An inbox entry:

```json
{
  "id": "9c1e…", "type": "bus_approaching", "read": false,
  "title": "اتوبوس نزدیک است", "body": "خط ۱۰۲ تا ۴ دقیقه دیگر می‌رسد.",
  "data": { "line_code": "102", "stop_name": "…", "minutes": 4 },
  "created_at": "2026-08-15T08:30:00+00:00"
}
```

`type` is the discriminator; `title` and `body` are the only keys every type
guarantees, and the rest stays in `data` for the screens that understand it.

## Passenger — `abilities:passenger`

```
GET  /wallet                        balance, limits, available gateways
GET  /wallet/transactions           statement  ?type&per_page
POST /wallet/topup                  { amount, gateway?, return_url? }
GET  /wallet/payments/{payment}     poll until final

POST /bus/scan                      { token, lat?, lng?, device_id? }
GET  /rides/active
POST /rides/{ride}/ping             { lat, lng, accuracy? }
POST /rides/{ride}/end              { lat?, lng? }
GET  /rides                         history
GET  /fare/quote                    ?line_id

POST /merchant/charge               { token, amount, description? }

GET  /complaints/categories
GET  /complaints
POST /complaints                    multipart, up to 5 images
GET  /complaints/{complaint}
POST /complaints/{complaint}/reply
POST /complaints/{complaint}/rate   { rating 1–5 }
```

`POST /bus/scan` is the fare path. It verifies the rotating token, finds the
active trip, rejects duplicates and remote boardings, prices the fare, then
debits and seats in one transaction. Returns fare, breakdown and new balance.

### Taxi

```
POST /taxi/scan                     { token, lat?, lng? }        → price, commits nothing
POST /taxi/rides                    { token, accepted_amount?, lat?, lng?, device_id? }
GET  /taxi/rides/active
POST /taxi/rides/{ride}/end         { lat?, lng? }
GET  /taxi/rides                    history, with `meta.outstanding`
POST /taxi/rides/{ride}/settle      pay off an unpaid metered fare
```

Two steps on purpose. `scan` prices the ride and commits nothing; `rides` takes
it, and for the two priced-up-front modes the passenger must send back
`accepted_amount` — the exact figure they were shown. A charter price the
driver changed in the intervening seconds will not match, and the server
refuses it rather than charging the new one.

A metered ride has no figure to confirm. It ends when the passenger says so,
when the driver does, or when the scheduler force-closes a meter that has run
past its ceiling; the fare is computed from the *car's* position reports and
debited then. A wallet that could cover the minimum at the kerb may not cover
forty minutes of traffic, so that outcome is a completed ride carrying an
`outstanding_amount` — a debt that blocks the next taxi until it is settled,
never a fare silently written off.

### School service

Everything is scoped to the caller's own children.

```
GET  /school/companies              approved companies only
GET  /school/schools
GET|POST /school/students
PATCH /school/students/{student}
GET  /school/students/{student}/live         where the van is, if a run is under way
GET  /school/students/{student}/attendance   the recent runs
POST /school/students/{student}/absence      { note?, direction? }
GET|POST /school/contracts
POST /school/contracts/{contract}/end        { reason? }
GET  /school/invoices
POST /school/invoices/{invoice}/pay
```

`/live` answers `null` with `meta.reason = no_run_in_progress` for most of the
day, and the app shows that as an answer rather than an error. When there *is*
a run, three conditions all have to hold: it is this guardian's child, the run
is actually happening, and the child's own journey on it has not finished. The
last is what stops a parent watching a van drive on to other families' houses
after their own child is home.

## Driver — `abilities:driver`

```
GET  /driver/state                  driver, assigned buses, open shift, live trip
POST /driver/shifts/start           { token, lat?, lng? }
POST /driver/shifts/end             { lat?, lng? }
POST /driver/trips/start            { route_id }
POST /driver/trips/pause | resume | complete
POST /driver/location               { lat, lng, speed?, heading?, accuracy?, recorded_at? }
GET  /driver/passengers             counts and recent boardings — never identities
GET  /driver/route                  stop list with a position marker
```

`POST /driver/location` returns the cadence the app should use next:

```json
{ "accepted": true, "next_ping_in": 5, "next_stop": "میدان آزادی",
  "distance_to_next_stop": 480, "eta_next_stop_seconds": 95, "is_off_route": false }
```

Battery cost is therefore controlled centrally rather than guessed by each
installed app version.

## Taxi driver — `abilities:taxi_driver`

```
GET  /taxi/driver/state             driver, assigned cars, open shift, the live code
GET  /taxi/driver/lines
POST /taxi/driver/shifts/start      { taxi_uuid, service_type, taxi_line_id?, lat?, lng? }
POST /taxi/driver/shifts/end        { lat?, lng? }
POST /taxi/driver/shifts/mode       { service_type, taxi_line_id? }
POST /taxi/driver/charter           { amount }
DELETE /taxi/driver/charter
GET  /taxi/driver/qr                the rotating fare code
POST /taxi/driver/location          { lat, lng, speed?, accuracy?, recorded_at? }
GET  /taxi/driver/rides             who is aboard, and what has been taken
POST /taxi/driver/rides/{ride}/end  { lat?, lng? }
GET  /taxi/driver/earnings          ?from&to
GET|POST /taxi/driver/settlements
```

What the car is offering lives on the **shift**, not the vehicle, so
`shifts/mode` changes it without ending anything. The vehicle carries
`allowed_modes` — which of the three it is licensed to run — and a mode outside
that set is refused.

`POST /taxi/driver/location` does three things in one call: moves the dot on
the live map, stores the car's last position, and advances a running meter.
The response carries the fare as it stands, so the driver's screen and the
passenger's agree:

```json
{ "accepted": true, "next_report_in": 8,
  "ride": { "uuid": "…", "service_type": "meter", … },
  "current_fare": { "amount": 82000, "formatted": "…",
                    "breakdown": { "distance_meters": 3200, "waiting_seconds": 90, … } } }
```

`accepted: false` means the sample was stored but not billed — poor accuracy,
an implausible jump, or one sample too soon after the last. Every discarded
sample is kept with its reason, so a disputed fare can be reconstructed.

## School service driver — `abilities:school_driver`

```
GET  /school/driver/state                       today's runs, in the order they happen
GET  /school/driver/trips/{trip}                one run with its manifest
POST /school/driver/trips/{trip}/start          { lat?, lng? }
POST /school/driver/trips/{trip}/complete       { lat?, lng? }
POST /school/driver/students/{row}/pickup       { lat?, lng? }
POST /school/driver/students/{row}/dropoff      { lat?, lng? }
POST /school/driver/students/{row}/absent       { note? }
POST /school/driver/students/{row}/reset
POST /school/driver/location                    { lat, lng, speed? }
```

The manifest is the app: children in collection order, each with an address, a
medical note where there is one, and a guardian's number. Check-in attaches the
van's position, which is what turns "the driver said so" into a record a parent
can check. `reset` exists because a wrong tap at a kerb in the rain happens, and
a driver who cannot correct it stops tapping at all.

`POST /school/driver/location` answers `{ "accepted": false, "reason":
"no_run_in_progress" }` outside a run, and the app stops sending rather than
retrying: outside a run there is no family entitled to the position.

## Merchant — `abilities:merchant`

```
GET  /merchant/state                    merchant, staff permissions, wallet, terminals
GET  /merchant/terminals/{id}/token     the rotating till QR
GET  /merchant/transactions             ?from&to&per_page
POST /merchant/transactions/{tx}/refund { reason }
GET  /merchant/reports/sales            ?from&to
POST /merchant/settlements/request      ?from&to
```

## Admin — `abilities:admin` plus a per-route permission

```
dashboard.view       GET  /admin/dashboard/kpis | /charts
                     GET  /admin/reports/transport | /drivers | /passengers   ?from&to
operations.live_map  GET  /admin/live/map
                     GET  /admin/live/occupancy    aggregated ridership per vehicle

fleet.manage         GET  /admin/fleet/buses
                     POST /admin/fleet/buses
                     PATCH /admin/fleet/buses/{bus}
                     GET  /admin/fleet/buses/{bus}/qr
                     POST /admin/fleet/buses/{bus}/qr/regenerate   { reason }
                     GET|POST /admin/fleet/buses/{bus}/assignments
                     DELETE /admin/fleet/assignments/{assignment}

drivers.manage       GET|POST /admin/drivers
                     GET|PATCH /admin/drivers/{driver}
                     POST /admin/drivers/{driver}/status           { status, reason? }
                     POST /admin/drivers/{driver}/documents            multipart
                     GET  /admin/drivers/{driver}/documents/{id}       signed URL

network.manage       POST|PATCH /admin/network/stops[/{stop}]
                     POST|PATCH /admin/network/lines[/{line}]
                     POST /admin/network/lines/{line}/routes
                     POST /admin/network/routes/{route}/recalculate

finance.manage       GET  /admin/reports/revenue                   ?from&to
                     GET  /admin/finance/summary | /transactions
                     POST /admin/finance/transactions/{tx}/reverse { reason }
                     POST /admin/finance/wallets/adjust            { user_uuid, amount, reason }
                     GET  /admin/finance/wallets/audit             ?user_uuid
                     GET|POST|PATCH /admin/finance/fare-rules
                     GET|POST /admin/finance/settlements
                     POST /admin/finance/settlements/{s}/approve | /pay | /reject
                     GET  /admin/users/lookup                      ?q  also users.manage

merchants.manage     GET|POST /admin/merchants
                     GET  /admin/merchants/{merchant}
                     POST /admin/merchants/{merchant}/status | /terminals | /staff

taxi.manage          GET|POST /admin/taxi/taxis
                     PATCH /admin/taxi/taxis/{taxi}
                     GET  /admin/taxi/taxis/{taxi}/qr
                     POST /admin/taxi/taxis/{taxi}/qr/regenerate   { reason }
                     GET|POST /admin/taxi/taxis/{taxi}/assignments
                     DELETE /admin/taxi/assignments/{assignment}
                     GET|POST /admin/taxi/lines · PATCH /admin/taxi/lines/{line}
                     GET|POST /admin/taxi/tariffs · PATCH /admin/taxi/tariffs/{tariff}
                     GET  /admin/taxi/rides   ?service_type&status&from&to
                     GET  /admin/taxi/report  ?from&to

operations.live_map  GET  /admin/taxi/live    every car in the city, with plate and load

finance.manage       GET  /admin/taxi/settlements                  also taxi.manage
                     POST /admin/taxi/settlements/{s}/approve | /pay | /reject

school.admin         GET  /admin/school/companies
  or school.manage   POST /admin/school/companies/{c}/approve | /reject | /suspend
                     GET|POST /admin/school/schools
                     GET|POST /admin/school/vehicles · PATCH /admin/school/vehicles/{v}
                     GET|POST /admin/school/routes · PATCH /admin/school/routes/{r}
                     POST /admin/school/routes/{r}/crew     { vehicle_uuid?, driver_uuid? }
                     GET  /admin/school/routes/{r}/contracts
                     GET  /admin/school/contracts
                     POST /admin/school/contracts/{c}/accept | /reject | /route | /bill
                     GET  /admin/school/trips  ?date
                     POST /admin/school/trips/schedule
                     GET  /admin/school/live

support.manage       GET  /admin/complaints | /{complaint}
                     GET  /admin/complaints/assignees
                     POST /admin/complaints/{c}/assign | /reply | /status
                     GET  /admin/complaints/{c}/attachments/{id}   signed URL
```

Approving a company is a city administrator's act (`school.admin`); running one
is the company's (`school.manage`). They share these endpoints, and the
controller narrows every query to the companies a `school.manage` holder
belongs to — the markup in the panel is identical, the data is not.

## Real-time channels

`POST /broadcasting/auth` — Sanctum-authenticated, and the only way onto a
private channel. The client posts `{ socket_id, channel_name }` with its bearer
token and gets back a signature; `routes/channels.php` decides whether that
token may listen at all.

| Channel | Who may listen | Carries |
|---|---|---|
| `transit.city.{city}.buses` | anyone | position, line, occupancy ratio — no identity |
| `transit.trip.{trip}` | anyone | one trip's progress |
| `private-transit.trip.{trip}.crew` | the driver, operations staff | driver and per-person detail |
| `private-wallet.user.{user}` | that user | their own balance changes |
| `private-transit.taxi.shift.{shift}` | the driver working it, operations staff | a fare landing, and the shift's running total |

The taxi shift channel is named after the shift rather than the driver, which
is what lets one channel serve two audiences the server tells apart.

`GET /map/config` carries the city id, so a signed-out client can name the
public bus channel from a payload it can already reach.

## Rate limits

| Bucket | Limit | Applies to |
|---|---|---|
| `public` | 60/min per IP | unauthenticated reads |
| `otp` | 3/min per IP, 10/h per mobile | OTP requests (they cost SMS) |
| `auth` | 10/min per IP | verify and login |
| `financial` | 4/min, 40/h per user | scan, top-up, merchant charge, refund |
| `telemetry` | 60/min per user | GPS ingest and ride pings |
| `uploads` | 30/h per user | complaint attachments, driver documents |
| `api` | 120/min | everything else |

## Error codes

| Code | Status | Meaning |
|---|---|---|
| `validation_failed` | 422 | `details` holds per-field messages |
| `unauthenticated` | 401 | missing or dead token |
| `forbidden` | 403 | authenticated, not permitted |
| `city_not_permitted` | 403 | role is scoped to another city |
| `rate_limited` | 429 | back off |
| `qr_expired` · `qr_replayed` · `qr_malformed` | 422 | re-scan; the code rotates |
| `qr_revoked` · `qr_unknown_code` | 422 | re-scanning will not help |
| `insufficient_funds` | 422 | `details.shortfall` |
| `no_active_trip` | 422 | the bus is not in service |
| `already_on_this_bus` · `ride_already_in_progress` | 409 | duplicate boarding |
| `too_far_from_bus` | 422 | `details.distance_meters` |
| `bus_not_assigned` · `license_expired` · `driver_not_active` | 403 | shift refused |
| `bus_already_in_service` · `driver_already_on_shift` | 409 | conflicting shift |
| `gps_jump_detected` · `gps_accuracy_too_low` | 422 | implausible telemetry |
| `nothing_to_settle` · `transaction_already_settled` | 422 | settlement state |
| `taxi_not_in_service` | 422 | the car has no open shift |
| `too_far_from_taxi` | 422 | `details.distance_meters` |
| `amount_confirmation_required` | 422 | a priced mode needs `accepted_amount` |
| `amount_mismatch` | 409 | the price moved between scanning and confirming |
| `no_charter_amount_set` | 422 | the driver has not named a price |
| `outstanding_taxi_fare` | 402 | settle the unpaid ride first |
| `no_taxi_tariff_configured` | 422 | no meter tariff for this city and time |
| `driver_already_on_taxi_shift` | 409 | conflicting taxi shift |
| `not_your_student` | 403 | a child who is not the caller's |
| `trip_not_live` | 409 | there is no run to watch |
| `child_journey_finished` | 409 | the child is home; tracking ends there |
| `contract_not_billable` · `invoice_not_payable` | 422 | contract or invoice state |
| `company_not_approved` | 422 | that company is not visible to families |
| `route_is_full` | 409 | the van has no free seat |

The full list with Persian text is `lang/fa/errors.php`.
