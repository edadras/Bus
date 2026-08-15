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

**Numbers.** Persian digits and the Persian thousands separator (U+066C)
throughout. Money is stored in rial and displayed in Toman, converted in exactly
one place per platform — `Money::format` and `Format.money`.

**RTL** is structural, not a stylesheet flip: logical properties throughout, and
`Directionality.rtl` at the Flutter root.

**Motion** respects `prefers-reduced-motion` / `MediaQuery.disableAnimations`. A
live map is already enough motion.

## Screens

### Passenger app (Flutter)

| Screen | Contents |
|---|---|
| Map | Live buses, stops, user position, arrival board for the nearest stop. **Usable signed-out.** |
| Bus sheet | Line, destination, next stop, ETA, occupancy, speed, staleness |
| Ride | Active journey with next stop and ETA, or the scan entry point |
| Scanner | Camera QR with viewfinder; expired and replayed codes are recoverable states, not errors |
| Wallet | Balance, quick top-up amounts, gateway hand-off with status polling, statement |
| Complaints | List, intake with up to five photos and optional position, threaded replies |
| Account | Profile, ride history, plain-language privacy disclosure |

The map tab is deliberately reachable without an account: a passenger must be
able to look up a stop and see the next bus before signing up. Tabs that move
money explain what signing in unlocks rather than hiding.

### Driver app (Flutter)

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

### Admin panel (web)

Dashboard with eight KPI cards and trend charts; live operations map with
driver detail and a searchable fleet list; fleet with rotating QR display and
revocation; driver approval and suspension; network browser with provenance
badges; finance with fare rules, transactions and settlement approval; merchant
management; and a complaint workbench with internal notes and SLA figures.

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

## Client architecture

**Flutter.** Riverpod for state; `hamsafar_core` holds the API client, models,
theme, secure token storage and the realtime client. Every live screen has two
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
