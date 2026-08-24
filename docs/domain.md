# Domain design

The reasoning behind the parts that are not obvious.

## Wallet — a double-entry ledger

A balance column with `+=` and `-=` is the obvious design and the wrong one:
there is no way to prove it is right, and no way to find out when it stopped
being right.

Instead: `wallet_transactions` is a header, `wallet_ledger_entries` are its
postings, and the invariant is that every transaction's postings sum to zero.
A passenger's debit always has a matching credit somewhere — the fare revenue
account, the merchant's wallet, the gateway clearing account.

```
top-up            gateway-clearing  −A      passenger  +A
fare              passenger         −A      fare-revenue  +A
merchant payment  passenger         −A      merchant  +(A−c)   commission  +c
settlement        merchant          −N      settlement-payable  +N
reversal          exact mirror of the original
```

The cached `balance` column exists for speed and is a materialised sum, not the
truth. `LedgerService::verify()` recomputes from the postings; the nightly
audit compares them and reports drift.

**Concurrency.** Wallets are locked `FOR UPDATE` in ascending id order. Sorting
the ids is what prevents the classic A-then-B / B-then-A deadlock between two
simultaneous transfers touching the same pair.

**Idempotency.** The key is unique at the database. If a retry races the
original, the loser catches the constraint violation and returns the winner's
transaction — so a flaky mobile connection cannot double-charge.

**Corrections are additions.** Ledger rows reject `update` and `delete` at the
model layer. A mistake is fixed by writing the mirror image, which is why the
history remains a truthful record of what happened.

## Fare engine

No fare is hard coded. Rules live in `fare_rules` and are edited by finance
staff. Selection is deliberate and explainable:

1. Filter candidates by city and context in SQL (cached, 5 minutes).
2. Apply each dimension — line, passenger type, zones — where a rule that names
   a value must not price a different one, and a null means "any".
3. Apply date and time predicates in PHP, including windows that wrap past
   midnight (a 22:00–05:00 night fare).
4. The winner is the highest `(priority, specificity)` pair.

Pricing is `base + per_km × distance`, then the multiplier, then min/max clamps.
The returned quote carries its full breakdown, so a disputed charge can be
reconstructed exactly.

## QR — rotating, signed, single-use

See [security.md](security.md#qr-credentials) for the threat model. The design
point worth repeating here: the sticker in the bus is *not* the credential. It
carries a public id; the credential is a short-lived token derived from a
server-side secret. That is what makes a photograph worthless.

## GPS ingest

The hot path — every driver app, several times a minute, per bus.

**Plausibility first.** A ping is rejected if accuracy is worse than 100 m, if
speed exceeds 140 km/h, if the device clock claims the future or an hour ago, or
if the implied speed since the last fix is physically impossible. A faulty or
spoofed device must not corrupt distance totals or the historical statistics
that ETA depends on.

**Route matching by offset.** The ping is snapped onto the route polyline and
reduced to a single scalar: metres travelled. Every question the product asks
then becomes a comparison. Where a route doubles back, matching can jump
backwards; anchoring to the previous offset keeps progress monotonic for all but
genuinely large corrections.

**Events fire once.** A bus idling inside a stop's geofence would otherwise
raise an arrival per ping, so arrival is guarded by a cache marker cleared on
departure. Off-route requires three consecutive deviating pings — a single bad
fix beside a tall building must not page the control room.

**The server sets the cadence.** Each response carries `next_ping_in`: 3 s
approaching a stop, 5 s moving, 20 s idle. Battery and data cost are tuned
centrally instead of being guessed by every app version in the field.

## ETA — v1, deterministic

Distance ÷ current speed fails exactly where it matters: a bus at a red light
reads as never arriving, and a bus on a clear stretch reads as arriving before
it has served three intervening stops.

Each remaining segment blends three independent estimators:

| Estimator | Strength | Weakness |
|---|---|---|
| Live speed | responsive | useless at a standstill, noisy |
| Historical mean for this segment in this (day, hour) bucket | captures real traffic | needs samples |
| Route baseline speed | always available | ignores conditions |

Weights come from config and are **renormalised over whichever estimators are
actually available**, so a brand-new route with no history still predicts
sensibly rather than silently under-estimating. Dwell time is added per
intervening stop but not at the target — arrival is what is being predicted.

History is stored per `(route, from, to, day type, hour)` and updated with
Welford's online algorithm: a new observation costs O(1) and the raw history is
never needed. History is only trusted after a minimum sample count, so one freak
journey cannot dominate every future prediction.

Every estimate carries a **confidence** that falls with prediction horizon,
missing history, staleness and off-route status. The clients use it: an
unreliable figure is shown softened and labelled «تقریبی» rather than presented
as fact. Publishing uncertain numbers as certain is how riders stop trusting the
board.

Version 2 replaces one function — `segmentSeconds()` — with a learned model.
The accumulation, dwell handling and confidence logic stay exactly as they are.

## Alighting — inference with a deliberate bias

There is no sensor for "the passenger got off". There are two noisy GPS traces.

The failure modes are not symmetric. Closing a ride early frees the seat while
the passenger is still aboard and corrupts the count the driver is watching.
Closing it late costs almost nothing. So the engine is biased to fail late.

Four signals, each wrong alone and rarely wrong together:

| Signal | Weight | Reasoning |
|---|---|---|
| Separation, discounted by reported accuracy | 0.45 | 100 m at 90 m accuracy says almost nothing |
| Divergence over consecutive samples | 0.25 | growing distance is the strongest single indicator |
| Standing near a stop the bus has passed | 0.15 | consistent with having just got off there |
| Bus driving on while far away | 0.15 | corroborates |

Separation alone maxes at 0.45 and cannot reach the 0.75 threshold, so a single
noisy fix can never close a ride. Two threshold-clearing observations are
required — and the first sample can never be one of them, because with nothing
to compare against it scores no divergence.

Between suspicion and decision the ride sits in `pending_alighting`: still
counted aboard, but flagged. Every detection stores its full signal set, so a
disputed charge is reconstructable. Whatever is left open is force-closed by the
scheduler, because a forgotten ride would block the passenger's next boarding.

## Trip lifecycle

`scheduled → starting → active ⇄ paused → completed | cancelled`

The service owns every transition, so the denormalised pointers on `buses`, the
shift counters and the live map cannot drift from the trip's real status.

Two exclusivity rules, both enforced under a row lock: a bus hosts one open
shift, and a driver holds one. Without them two drivers could both claim a
vehicle and passengers would board an ambiguous trip.

Completing a trip closes every passenger still aboard. A passenger left with an
open ride cannot board anything else.

## Taxi — three products on one fleet

A taxi line, a charter and a metered ride are three different agreements, and
the difference is entirely about *when the price is known*.

| Mode | Price known | Paid | Passenger confirms |
|---|---|---|---|
| Line | published in advance | on boarding | the flat fare |
| Charter | the driver names it | on boarding | that exact figure |
| Meter | only at the end | on ending | the tariff, not a total |

**The mode belongs to the shift, not the car.** The same vehicle runs a fixed
line in the morning and takes charters in the afternoon, so what it is offering
lives on the driver's open shift and can change mid-shift without ending
anything. The vehicle carries `allowed_modes` — what it is *licensed* to run —
and a mode outside that set is refused rather than silently accepted.

**A confirmed amount is what makes a price honest.** For the two priced-up-front
modes the passenger sends back the figure they were shown. A charter price the
driver raised in the two seconds since the scan will not match, and the server
refuses the ride rather than charging the new one. There is no path by which a
passenger is charged an amount they never saw.

**The meter runs on the car's position, never the passenger's phone.** A phone
can be switched off, put in a bag, or deliberately spoofed; the fare must not
depend on any of that. Every sample the car reports is stored — including the
ones that were discarded, with the reason (`poor_accuracy`, `too_frequent`,
`gap_too_long`, `implausible_speed`) — so a disputed fare can be reconstructed
sample by sample rather than argued about.

Distance and waiting are billed separately because they are separate things: a
car stopped at a light has covered no ground, and billing that time as distance
is exactly the complaint that destroys trust in a meter. A gap longer than the
configured maximum is not billed as waiting at all, because nobody can prove
the car was waiting rather than out of coverage.

**A metered ride is the one charge that can fail.** A wallet that covered the
minimum fare at the kerb may not cover forty minutes of traffic. That outcome
is recorded as a *completed ride with an outstanding amount* — a debt that
blocks the passenger's next taxi until it is settled — rather than an open ride
nobody can close or a fare quietly written off. A metered ride will not start
at all unless the wallet holds several times the tariff minimum: refusing at
the kerb is far better than stopping the car at the destination to argue.

**Nonce scoping differs by mode.** A charter or a metered car takes one hire at
a time, so its code's nonce is claimed globally. A line taxi takes anyone while
a seat is free, so its nonce is claimed per passenger — otherwise the second
person getting into a shared car would find the code already spent.

**Two live feeds, deliberately unequal.** A rider asking "what is near me" gets
position, mode and distance, from a mandatory position with a server-capped
radius. The operations room gets every car in the city with the plate, the
driver and who is aboard. Publishing the second to the first audience would be
a tracking service for taxi drivers, which is not what a passenger looking for
a car is owed.

Settlement mirrors the merchant path: a driver claims a fixed set of paid,
unsettled rides, and the claim makes the payout safe to run twice. Only rides
where money actually moved are claimable — `fare_amount > 0` — so an unpaid
metered ride is never counted as something to pay a driver for.

## School transport — a contract, a seat, and a van

Four states, and each transition is somebody's decision:

`requested` → `approved` (the company names the fee) → `active` (the company
gives the child a seat on a route) → `ended`.

Contracts are **per child**, not per family: two children at two schools are two
arrangements with two companies and two fees. A company is invisible to families
until a city administrator approves it, so the list a parent chooses from is
already vetted.

**The privacy rule is the feature.** A van's position is visible to the families
it is carrying, while it is carrying them, and to nobody else at any other time.
Three conditions, all necessary, enforced in one method
(`SchoolLiveService::assertGuardianMayWatch`) so the callers cannot drift apart:

1. it is this guardian's child;
2. the run is actually in progress;
3. the child's own journey on it has not finished.

The third is what stops a parent watching a van drive on to other families'
houses after their own child is safely home. It is checked against the *run*
rather than the vehicle, so last week's parent is not still watching this
week's van. Reporting itself starts when the run starts and stops when it ends:
outside a run the server rejects position reports, because there is nobody
entitled to them.

**Attendance is a record, not a claim.** The driver checks each child on and off
at the door, and the van's position is attached to the check-in — which is what
turns "the driver said so" into something a parent can verify. Undo exists,
because a wrong tap at a kerb in the rain happens and a driver who cannot
correct it stops tapping at all. Completing a run settles everyone still
unchecked as a no-show rather than leaving them ambiguous: a child nobody
touched must be visibly unaccounted for, never silently absent.

The collection order defaults to distance from the school — furthest door first
on the way in, nearest first on the way home. A rough but honest default that a
company can override, and far better than an arbitrary order that leaves the
driver to work it out.

**Runs are built ahead of the day** (`school:runs:schedule`), so a driver
opening the app before dawn already has the morning's manifest and a parent
reporting an absence the night before has a run to report against. Creation is
idempotent per (route, date, direction), which is what makes the scheduler and
the operator's button safe on the same day. A route missing its van, its driver
or its paperwork is not scheduled at all — an empty manifest in front of nobody
makes a company's board look staffed when it is not.

Invoicing is idempotent on the billing period, so a family cannot be billed
twice for one month by a scheduler that ran twice. The fee moves through the
same wallet and the same double-entry ledger as every fare: a school fee is not
a special kind of money.

## Merchant payments and settlement

The commission split happens **inside the same posting** as the payment, so the
merchant's balance is always exactly what is owed to them and never needs a
later correction.

Settlement claims a fixed set of completed, unsettled transactions by stamping
them with its id inside a locked transaction. That claim is what makes the
payout safe to run twice: a re-run finds nothing left to claim rather than
paying twice. Rejection releases the claim.

## Complaints

Intake captures context automatically — trip, bus, driver, line, stop, position,
even the disputed transaction — because a complaint with context is actionable
and one without is a guess.

Safety-related categories jump the queue: misconduct is urgent, driver and
payment are high. The passenger thread and the internal triage thread are the
same table separated by `is_internal`, and passenger endpoints only ever return
the public half.

## Telling people what was decided

Every decision one party makes about another reaches that other party. A parent
hears when a company accepts their request and at what fee, when it declines and
why, when their child is given a seat and on which van, and when an invoice is
issued. A taxi driver hears whether their settlement was approved, paid — with
the payment reference — or rejected, and why. A company owner hears the
administrator's verdict on their registration and any suspension.

Two rules shape how these are sent. They go out **after** the transaction
commits, never inside it: a push about a seat that a rollback then takes away is
a promise the system never made. And they follow the idempotency of the decision
itself — re-issuing an invoice that already exists, or approving a company that
is already active, sends nothing, because being told twice reads as a fault
rather than as news.

The message is built by a factory on the notification, which loads the relations
it reads rather than trusting each caller to have done so. The billing job, the
company panel and the acceptance path all construct the same message, and a
relation one of them forgot is a crash in the middle of a night run.

## Mapping

Everything goes through `MapProvider`. Tile configuration is served by the API,
so swapping OpenStreetMap for Mapbox, Google or a domestic provider is a config
change and no client is rebuilt. Routing and geocoding are declared unsupported
rather than faked — callers check `supportsRouting()` and the platform's own
route geometry stays authoritative.

## Network data provenance

Every stop, line and route carries `provenance`. Anything not `official` is
labelled as unofficial all the way to the passenger's screen, and the API
exposes `is_verified_data` so no client can accidentally present demo geometry
as the published network. The bundled Bandar Abbas data is `sample`; replacing
it with the real network is an import with `--provenance=official`.

Stops, lines and route sequences import from **CSV or an Excel/ODS workbook**
(a transit department exports from Excel far more often than it hands over a
CSV, and asking an operator to re-save every file first is how imports get done
wrong or not at all); geometry imports from **GeoJSON**. The format is resolved
from the file extension, and spreadsheets are read in read-only mode so a
5,000-row export does not have to fit in memory twice.

Errors are collected, never thrown: one malformed row in a 2,000-row export is
recorded on the batch with its line number, and the other 1,999 land. The
operator fixes those lines and re-runs — imports are keyed on `code`, so a
re-run updates rather than duplicates.
