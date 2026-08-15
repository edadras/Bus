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
| `merchant` | `merchant` | active merchant staff |
| `admin` | `admin` | the user holds a staff role |

Token lifetimes: admin 12 h, merchant 7 d, driver 30 d, passenger 180 d.

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
GET /map/config                     tile provider, city viewport, bounds
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
```

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
operations.live_map  GET  /admin/live/map

fleet.manage         GET  /admin/fleet/buses
                     POST /admin/fleet/buses
                     PATCH /admin/fleet/buses/{bus}
                     GET  /admin/fleet/buses/{bus}/qr
                     POST /admin/fleet/buses/{bus}/qr/regenerate   { reason }
                     POST /admin/fleet/buses/{bus}/assignments
                     DELETE /admin/assignments/{assignment}

drivers.manage       GET|POST /admin/drivers
                     GET|PATCH /admin/drivers/{driver}
                     POST /admin/drivers/{driver}/status           { status, reason? }
                     POST /admin/drivers/{driver}/documents

network.manage       POST|PATCH /admin/network/stops[/{stop}]
                     POST|PATCH /admin/network/lines[/{line}]
                     POST /admin/network/lines/{line}/routes
                     POST /admin/network/routes/{route}/recalculate

finance.manage       GET  /admin/finance/summary | /transactions
                     POST /admin/finance/transactions/{tx}/reverse { reason }
                     POST /admin/finance/wallets/adjust            { user_uuid, amount, reason }
                     GET  /admin/finance/wallets/audit             ?user_uuid
                     GET|POST|PATCH /admin/finance/fare-rules
                     GET|POST /admin/finance/settlements
                     POST /admin/finance/settlements/{s}/approve | /pay | /reject

merchants.manage     GET|POST /admin/merchants
                     GET  /admin/merchants/{merchant}
                     POST /admin/merchants/{merchant}/status | /terminals | /staff

support.manage       GET  /admin/complaints | /{complaint}
                     POST /admin/complaints/{c}/assign | /reply | /status
                     GET  /admin/complaints/{c}/attachments/{id}   signed URL
```

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

The full list with Persian text is `lang/fa/errors.php`.
