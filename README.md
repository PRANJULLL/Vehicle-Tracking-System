# Where's My Bus — School Bus Live Tracker (PHP)

A PHP rewrite of the original Node/Express school bus tracker, with a
front end redesigned to be simple and reassuring for parents to use.

Parents enter their child's Student ID → the system looks up which bus
that student rides → fetches the bus's live GPS location from FleetHunt →
shows it on a map, with a route line from the parent's location to the bus.

## What changed from the Node version

- **Backend rewritten in plain PHP** (no framework, no Composer packages —
  just PHP's built-in `curl` and `json` extensions). Same behavior, same
  `/api/track` contract, same `.env`-based bus/token config.
- **The 5-second in-memory cache became a small file cache** (`/cache`),
  since PHP doesn't keep a long-running process between requests the way
  Node does.
- **Front end redesigned for parents**: bigger touch targets, a
  Uber/Ola-style map with a floating search bar and a bottom details
  card, plain-language status messages instead of raw errors, and a
  live "Updated X seconds ago" clock so a parent always knows how fresh
  the bus location is.

## Project structure

```
bus-tracker-php/
├── .env                    # real tokens (gitignored, not committed)
├── .env.example             # template with placeholders
├── .htaccess                 # safety net if hosting can't set /public as document root
├── public/                   # <- point your web server here
│   ├── index.html
│   ├── .htaccess             # rewrites /api/track -> api/track.php on Apache
│   ├── assets/
│   │   ├── style.css
│   │   └── app.js             # map, geolocation, polling, routing
│   └── api/
│       ├── track.php          # POST /api/track
│       └── health.php         # GET /health
├── src/
│   ├── env.php                # tiny .env loader
│   ├── buses.php              # loads bus/token list from .env
│   ├── students.json          # student_id -> {name, busId} mapping (REPLACE with real data)
│   ├── cache.php               # 5s file cache to avoid hammering FleetHunt
│   └── fleethunt.php           # ⚠️ FleetHunt API integration — confirm/edit this
└── cache/                      # writable — auto-created cache files live here
```

## Setup

Requires PHP 8+ with the `curl` extension (`php-curl`).

```bash
cp .env.example .env   # already done for you locally — just double check values
```

**Point your web server's document root at `public/`.** For quick local testing:

```bash
cd public
php -S localhost:4000
```

Then open http://localhost:4000

If you're on shared hosting that only lets you point the domain at the
project root (not `public/`), the root `.htaccess` will route requests
into `public/` for you and block direct access to `src/`, `cache/`, and
`.env` — but setting the document root to `public/` directly is the
safer, preferred option if you have that control.

## ⚠️ Before this actually works: confirm the FleetHunt endpoint

`src/fleethunt.php` currently has a **best-guess** endpoint URL and
response field names, carried over unchanged from the original project. You need to:

1. Open the Postman collection: import
   `https://documenter.getpostman.com/view/3184032/UVXgMHcy` into Postman
   (Import button → paste URL).
2. Click into the actual "get location" request (not the bare token).
3. Note the exact:
   - HTTP method + URL path
   - Where the token goes (header? query param? which header name?)
   - The JSON response field names for latitude/longitude/timestamp
4. Update `src/fleethunt.php` accordingly — everything else (caching,
   the API route, the front end) will keep working unchanged.

## Replacing the student → bus mapping

`src/students.json` has the same dummy data as the original project.
Replace it with the real mapping once you have it:

```json
{
  "STU001": { "name": "Aarav Sharma", "busId": 1 },
  "STU002": { "name": "Priya Verma", "busId": 1 }
}
```

`busId` refers to the order buses appear in `.env` (`BUS_1_*`, `BUS_2_*`,
etc.) — see `src/buses.php`.

## Adding more buses

Just add more rows to `.env`:

```
BUS_5_VEHICLE_NO=PBXXXXXXXX
BUS_5_TOKEN=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

No code changes needed — `buses.php` auto-detects them.

## Security notes

- Tokens live server-side only (`.env`), never sent to the browser.
- Keep `.env` out of version control.
- `src/`, `cache/`, and `.env` are blocked from direct web access by the
  `.htaccess` rules — but the real safety net is pointing your document
  root at `public/`, so those files are outside the web root entirely.
- Consider asking FleetHunt to rotate tokens once they've passed through
  chat/dev tools, before going to production.

## Design notes (front end)

The interface is built around how parents actually use it: a quick,
anxious check on a phone, often one-handed. That drove a few concrete choices:

- **Map-first layout.** The map is the main surface, with a floating
  search bar on top and a details card at the bottom — the same layout
  pattern as ride-hailing apps, so it needs no explanation.
- **Plain language, no jargon.** Errors read like a person explaining
  what happened ("We couldn't find a student with that ID") rather than
  raw system messages.
- **A live "Updated X seconds ago" clock** so a parent always knows how
  fresh the data is, without guessing.
- **A green "Nearby!" state** that only appears once the bus is close,
  so the one moment that matters most is impossible to miss.
- **Large text and touch targets**, high-contrast status colors (green /
  amber / red), and a color palette drawn from an actual school bus
  (navy + bus yellow + a black-and-yellow hazard stripe) rather than a
  generic tech palette.

## Next steps (not yet built)

- Bus stops / geofencing (ETA to a specific stop, not just raw bus position)
- Push notifications when the bus is near a stop
- Auth so a parent only sees their own child's data (currently anyone
  who knows a student ID can track that student — fine for MVP/testing,
  not for production)
