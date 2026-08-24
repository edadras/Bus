# Frontend

## Design system

One token set drives the landing page, the admin panel and all three apps, so a
colour changed in `resources/css/app.css` and `AppColors` changes everywhere.

**Palette.** Green-led on a very dark neutral ground. Green carries meaning in
this product — a moving bus, a successful payment — so saturated green is
reserved for live and affirmative states and everything else stays quiet. The
neutrals carry a faint green cast rather than being pure grey.

**Glass surfaces** are three ingredients that must stay together: a translucent
fill, a light top border faking a lit edge, and a blur. Used without the border
they read muddy rather than glassy. On a dark ground heavy shadows read as
dirt, so depth comes from the border highlight, not elevation.

**Typography.** Vazirmatn (SIL OFL), bundled — not fetched from a CDN. Persian
needs more line height than Latin to stay readable, so body text runs at 1.8.

**Numbers.** Persian digits and the Persian thousands separator (U+066C) in
Persian; Latin digits in English, because Persian numerals inside an English
sentence are unreadable to whoever asked for English. Money is stored in rial
and displayed in Toman, converted in exactly one place per platform —
`Money::format` and `Format.money`, both of which follow the active locale.

**RTL** is structural, not a stylesheet flip: logical properties throughout, and
`Directionality` chosen from the locale at the Flutter root.

**Localisation.** Nothing user-facing is written inline. The server has
`lang/fa` and `lang/en`; the browser reads the same keys from a dictionary
rendered into the page; the apps read them from `AppStrings`. Three tests —
one per surface — fail the build if a Persian string is typed into a view, a
script or a widget, or if the two locales drift apart. The passenger app
carries the language switch; the choice is stored and survives a restart.

**Motion** respects `prefers-reduced-motion` / `MediaQuery.disableAnimations`. A
live map is already enough motion.

## Screens

### Passenger app (Flutter)

| Screen | Contents |
|---|---|
| Map | Live buses, stops, user position, arrival board for the nearest stop. **Usable signed-out.** |
| Bus sheet | Line, destination, next stop, ETA, occupancy, speed, staleness |
| Plan | Origin and destination pickers, transfer tolerance, itineraries with per-leg board/alight rows. **Usable signed-out.** |
| Lines | Every line, each direction's stop timeline with offsets; tapping a stop opens its arrival board. **Usable signed-out.** |
| Ride | Active journey — bus or taxi — with next stop and ETA, or the scan entry point |
| Scanner | Camera QR with viewfinder; expired and replayed codes are recoverable states, not errors |
| Fare confirmation | What a scanned taxi ride costs, before it is taken; a meter shows the tariff instead of a total |
| School service | A parent's children, the arrangement for each, and the live van while it is carrying them |
| Wallet | Balance, quick top-up amounts, gateway hand-off with status polling, statement |
| Complaints | List, intake with up to five photos and optional position, threaded replies, and a satisfaction rating once resolved |
| Notifications | In-app inbox with unread badge; tapping a card marks it read |
| Account | Profile, ride history, notification entry point, language switch, plain-language privacy disclosure |

The inbox is the durable record of everything the platform has said. Push is
best-effort — a phone can be off, out of coverage, or have notifications
switched off entirely — so nothing is ever *only* a push.

**One scanner, both subsystems.** A passenger holding a phone at a code does not
know whether a bus or a taxi issued it, and should not have to. The public id
inside the token says which, and a misleading prefix is recovered by trying the
other rather than shown as a failure.

**The taxi layer is off by default.** A rider looking for a bus should not have
to pick their line out of a screen full of cars. Turned on, each car is coloured
by what it is offering — the same three colours as the driver's app and the
control room — and the legend doubles as the filter, because a colour the rider
cannot act on is decoration. The sheet carries no plate, no driver and no
passenger count: the feed behind it does not have them.

**An unpaid metered fare is surfaced on the ride tab**, not buried in a history
screen. It blocks the next taxi, and a passenger turned away at a car door with
no explanation would have no idea why.

The school section lives in the account tab rather than a sixth navigation
slot, because most passengers are not parents; a badge counts the things that
need doing — a company's answer to read, an invoice to pay — so the ones who
are can find it. Nothing there names a price: the company answers with the fee
and the parent sees it before anything is owed.

The map tab is deliberately reachable without an account: a passenger must be
able to look up a stop and see the next bus before signing up. Tabs that move
money explain what signing in unlocks rather than hiding.

Journey planning opens from the map header and is reachable on the same terms —
planning a trip is exactly what someone does before deciding whether to sign up.
Each itinerary is presented as instructions rather than a summary: walk this far
to the stop, take this line from here to there, change, walk to the destination.
Every figure includes the expected wait at the stop and is labelled an estimate,
and when there is nothing to offer the screen says which of the two reasons it
was — no stop within walking distance, or no route between them — because the
passenger's next move differs.

### Bus driver app (Flutter)

| Screen | Contents |
|---|---|
| Shift | Driver header, assigned buses, start by QR scan, live shift counters, trip controls |
| Route | Vertical timeline with a clear next-stop marker; passed stops dimmed |
| Passengers | Large live count, occupancy bar that turns amber then red, recent payments |

The shift card shows GPS reporting state explicitly — reporting, waiting for a
fix, GPS off, server unreachable — because a driver needs to know their bus is
visible to the control room. Scan refusals are explained individually: an
unassigned bus, an expired licence and another driver's open shift are different
problems with different remedies.

### Merchant app (Flutter)

| Screen | Contents |
|---|---|
| Collect | Rotating till QR on a white quiet zone, countdown to the next code, wallet balance, pending settlement |
| Transactions | List with permission-gated refunds; settled rows explain why they cannot be refunded |
| Reports | Totals, a hand-drawn daily bar chart, per-day breakdown |

The screen keeps itself awake — a till sleeping mid-transaction is the main
practical failure of these devices.

### Taxi driver app (Flutter)

| Screen | Contents |
|---|---|
| Shift | Which car, what it is offering, the live counters, and the rotating fare code |
| Passengers | Who is aboard, what has been taken, and boarding and alighting counts |
| Earnings | Gross, commission and net by day and by product, plus settlement requests |

One question dominates the first screen: *what is this car offering right now*.
It is three buttons, because that is a decision a driver makes at a kerb — and
only the modes the car is licensed for are offered, since picking one it may not
run would produce a refusal a moment later. Charter is a number pad with round
presets, because a driver leaning out of a window will not type six digits. The
meter needs no interaction at all: it starts when a passenger scans, and the
running total on the driver's screen is the same one the passenger sees, because
both come from the car's own position reports.

The fare code carries a countdown for the same reason the merchant till does —
so a driver can tell a passenger to wait a second rather than wondering why a
scan failed.

Gross, commission and net are shown separately, and unpaid metered rides sit
apart from all three. A driver comparing their own count against a payout will
otherwise find the two disagree.

### School service driver app (Flutter)

| Screen | Contents |
|---|---|
| Runs | Today's runs, usually two, with attendance counters and a readiness state |
| Manifest | The children in collection order: address, medical note, guardian's number, two buttons |

The manifest *is* the app. Each row is a name, a door, and a single wide
button — pressed one-handed from a driver's seat, where a row of small controls
would be pressed wrongly. Nothing can be checked before the run starts: a
pick-up recorded at eight for a van still in the yard is a lie in a record
parents rely on. Undo is always available on a settled row.

The medical note and the emergency number are on the card rather than in a file
somebody would have to go and find, because this is the screen a driver is
looking at when either becomes relevant.

### Admin panel (web)

Dashboard with eight KPI cards and trend charts; live operations map with
driver detail and a searchable fleet list; **live occupancy** with per-vehicle
crowding levels; **reports** across transport, drivers, passengers and revenue
with a selectable period; fleet with rotating QR display, revocation and
bus↔driver assignment; a driver dossier with licence, documents, assignments and
recent shifts; a network editor with route sequences and offset recalculation;
finance with a filterable transaction list, reversal, wallet adjustment, the
ledger integrity check, fare rules and settlements; a merchant dossier with
tills and staff; a complaint workbench with assignment, attachments, internal
notes and SLA figures; a taxi section with fleet, lines, tariffs, settlements, a
report split by the three products and a live board of every car in the city;
and a school transport section covering company approval, the contract path
from a family's request through fee, seat and invoice, routes with their
readiness answered as one blocker, vehicles with their compliance state, the
day's runs, and a live board of the vans under way.

Create and edit flows across fleet, drivers, network, merchants and fare rules
share one schema-driven modal (`admin/partials/form-modal.blade.php`), so a new
managed entity is a field list rather than another form. A form may pass an
`onDone` callback to own what happens after a successful submit — showing a new
balance, refreshing one list — instead of the default of reloading the screen.

Three screens deliberately do not use it. Uploading a driver document is
multipart rather than JSON; a route's stop sequence is a reorderable list,
because order is the entire content of a route; and both private-file viewers
ask the server for a signed URL rather than linking to a path.

Files held on the private disk — complaint photos, driver documents — are never
served from a guessable path. The panel requests a ten-minute signed URL and
opens that. Those download routes are registered *before* the `/admin/{any}`
catch-all that lets a bookmarked panel URL reload, since first match wins and a
download registered after it silently returns the panel's HTML instead.

The taxi live board is the operations counterpart of the passenger's "near me"
feed, and it exists because a dispatcher genuinely needs the plate and the load
that a rider does not. Cars are coloured by what they are offering rather than
by their status: a control room has to tell the three products apart at a
glance, not by reading a table, and the legend is also the filter.

The school section is shared by two audiences. A city administrator holds
`school.admin` and sees every company; a company manager holds only
`school.manage` and the server narrows each query to their own. The markup is
identical either way — the difference is in what comes back, which is the only
place it is safe for the difference to live.

The occupancy screen is what the specification called a live passenger map. It
publishes counts per vehicle — "bus 102: 27 of 40" — and never the position or
identity of a rider. Everything an operations team does with that screen —
spotting crowding, rebalancing frequency, dispatching a relief bus — is answered
by counts, and a per-person map would be a far larger capability than running a
bus network requires.

### Landing page

Hero with a live map, feature grid, live fleet section, wallet explainer,
audience sections, merchant call to action, covered cities, and an FAQ that
answers the questions people actually ask — including what happens if someone
photographs the QR code, and whether location is stored.

## Progressive Web App

The landing page and the public viewer are installable. Three manifests, one
per surface, each with its own name and scope.

The service worker is deliberately conservative: the app shell and hashed
assets are cached so the app opens instantly and survives a tunnel, but **API
responses are never cached**. A stale bus position is unhelpful; a stale wallet
balance is actively misleading. Offline, the app says so.

The web passenger surface is a read-only viewer — map, stops, arrival times.
Anything that moves money lives in the native app, which can hold a credential
in the platform keystore rather than in browser storage.

**Web Push.** The service worker's `push` handler renders the notification and
`notificationclick` focuses an existing tab rather than opening another.
Enrolment lives in `resources/js/lib/push.js` and is only ever triggered by a
user gesture (`hamsafar.push.enable()`): a permission prompt fired on page load
is usually answered with "block", and a blocked origin can never ask again. An
unsupported browser, a deployment without VAPID keys, or a denied permission
each return a status string rather than throwing.

## Client architecture

**Flutter.** Riverpod for state; `hamsafar_core` holds the API client, models,
theme, secure token storage, the string tables and the realtime client. Every live screen has two
sources — a socket subscription and a timer — because a live map that silently
freezes is worse than one that updates slowly.

**Web.** Alpine plus a shared `lib/api.js` that owns the envelope, the city
header, the token and the error shape. Leaflet is wrapped so the tile provider
comes from the API. The admin panel is one component with client-side routing
and lazy per-screen loading.

## Accessibility

Touch targets are 52 dp on primary actions — this app is used one-handed,
standing, on a moving bus. Text scaling is honoured but clamped at 1.3, beyond
which fare and ETA figures truncate, and a wrong-looking price is worse than a
small one. Focus is always visible. Status is never colour alone: every badge
pairs its colour with a label.
