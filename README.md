# AnglerHub API

A small, focused look at the Laravel API layer behind
[AnglerHub Mobile](https://github.com/Voorbeelden/AnglerMobile) — the
REST endpoints the mobile app actually talks to, kept separate from the
[AnglerHub Web](https://github.com/Voorbeelden/AnglerHubWeb)
repo, which shows the traditional Blade/web side of the same application.

This is a **curated excerpt**, not the full API — see
[Repository scope](#repository-scope).

## What's here

- **Authentication** — Sanctum token issuance, a 2FA challenge-token flow,
  and a server-enforced block on staff accounts ever authenticating
  through the mobile client.
- **Weigh-ins** — the endpoint the mobile app's offline sync queue talks
  to, including batch/idempotent sync after a period offline.
- **Standings** — yearly rankings, filterable by year/type/date range.
- **A uniform response envelope** (`{ success, data, message }`), shared
  across every endpoint via a trait.
- **API Resources used as a security boundary** — explicit field
  whitelisting rather than serializing Eloquent models directly.

## Why a separate repo from the dashboard

The API and the web dashboard share the same underlying models, services
and Policies in the real application — only the response format differs
(`JsonResponse` here vs. Blade views in the dashboard repo). Splitting
them into two repos here isn't about the code being unrelated; it's about
letting someone who specifically wants to see "how do you design a REST
API for a mobile client" find exactly that, without wading through
unrelated Blade/session code — and vice versa for someone evaluating
traditional Laravel MVC work.

## Repository layout

```
anglerhub-api/
├── routes/
│   └── api.php                                 routes for the 3 controllers below
├── app/Http/Controllers/Api/
│   ├── V1/
│   │   ├── AuthController.php                  Sanctum tokens, 2FA challenge flow, staff-login block
│   │   ├── WeighInController.php                weigh-in recording + offline batch sync
│   │   └── StandingsController.php              standings + per-member detail
│   └── Concerns/
│       └── ApiResponses.php                    the shared { success, data, message } envelope
└── app/Http/Resources/Api/V1/
    ├── ClubResource.php                        field whitelist + server-computed capabilities
    ├── WeighInResource.php                     mirrors the mobile app's WeighingModel DTO
    └── UserResource.php                        minimal field whitelist
```

## Architecture notes

**The server owns idempotency for offline sync.** The mobile app generates
a UUID per weigh-in at the moment it's recorded locally, before it's ever
sent anywhere. `WeighInController::sync()` processes a batch of those one
at a time, so a single failing item never blocks the rest of the batch —
and a retried request after a dropped connection is a no-op the second
time, not a duplicate.

**Rate limiting matched to how each endpoint is actually used**, not one
blanket limit — login gets a stricter limit than general traffic; the
weigh-in endpoints get a *higher* limit, since several people legitimately
record weights in quick succession on a competition day.

**Field whitelisting is enforced structurally.** `ClubResource` explicitly
documents which fields must never appear in a response (payment-provider
IDs, bank account numbers, invite codes) — an earlier version of
`AuthController::me()` did leak a raw model relation, and fixing it is
what led to writing that whitelist down explicitly rather than trusting
it to stay obvious. See the resource's own doc comment.

## Security and Privacy

Real client identifiers, the production domain, and anything
Stripe/AWS/mail-credential-shaped are replaced with placeholders or
removed outright. Route paths, class, method and field names are also
translated/renamed from the original, Dutch-language production code
(`/wedstrijden` → `/competitions`, `gewicht` → `weight`, and so on) —
this is a real, live product, and keeping this excerpt's naming distinct
from the exact strings the production API uses avoids handing over a
literal map of it to anyone inspecting network traffic on the live site.

## Repository scope

- **The actual business logic services are not included**
  (`ParticipantService`, `StandingsService`) — these are where
  competition rules, capping/disqualification logic, and standings
  calculations actually live, referenced from the controllers above but
  not shown.
- **Eloquent models, payments (Stripe Connect), and admin/support tooling
  are not included.**

**This excerpt won't run standalone** — it references services, models
and Policies that aren't part of this repository.

## What this demonstrates

- Designing a REST API on top of an existing application without
  duplicating its business logic per client
- API Resources used deliberately as a security boundary
- Token auth (Sanctum) with 2FA and a server-enforced staff/member
  authorization boundary
- Idempotent, batchable endpoints designed for an offline-first mobile
  client
- Recognizing and fixing a real data-exposure bug, and turning that into
  a structural safeguard rather than a one-off patch

## License

Shared for portfolio purposes only. Not licensed for reuse, redistribution
or use as a starting point for a similar application.
