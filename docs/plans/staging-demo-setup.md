# Staging Demo Setup — "Sample Honor Choir Association"

Scoped 2026-09-22 to support live prospect demos (NAfME/ACDA cold outreach)
on the `staging` Vapor environment, alongside the one-page PDF overview and
longer PPTX deck (both under
`C:\Users\RickRetzko\Documents\products\tdr2027\marketing\overview\`).

This doc is the runbook **you** run — deploying to staging and seeding it
were deliberately left as manual steps rather than something run
automatically, since both touch a real (if non-production) shared
environment.

---

## 0. What this gives you

A fully fictional "Sample County Honor Choir Association" dataset, built by
`database/seeders/SampleHonorChoirAssociationSeeder.php`:

- 1 Organization, 1 Event ("Sample All-State Honor Choir"), 3 Ensembles
- 4 fictional schools (Sample North/Valley/Ridge/Lakeside), 4 teachers
  (one of them is the demo Event Manager), 32 students
- **Two registration cycles** on the same Event:
  - **Fall 2025 Sample Cycle** — closed, with full results: 16 accepted
    candidates (each assigned to an ensemble), 4 not accepted, 4 no-shows.
    This is what a prospect sees when you show the finished pipeline.
  - **Fall 2026 Sample Cycle** — open now, with 24 candidates already
    registered/pending, **and 2 students per school held back specifically
    so you can register a brand-new candidate live on a call** without
    reusing someone already seen in the closed cycle.
- Two demo logins (see §3)

**Scope note:** the seeder does not populate the judge/rubric/room
adjudication pipeline (score categories, factors, rooms, judges, raw
per-judge scores) — that's configured per-Version through the
VersionScoringRubric admin screen, not seed data, and faking it risked
violating real invariants for no demo value. Instead, closed-cycle
candidates get a real terminal status (Accepted/NotAccepted/NoShow), a
frozen `AuditionResult` tally row, and — for Accepted candidates — an
ensemble assignment, which is what the results/roster screens actually
read from. Recordings exist as metadata rows with a placeholder
`example.com` URL, not playable audio files — don't click "play" live.

Full detail and the invariants it respects are documented in the seeder's
class docblock.

---

## 1. Deploy the current code to staging

From the repo root (Vapor CLI already installed globally via
`composer global require laravel/vapor-cli`, at
`~/AppData/Roaming/Composer/vendor/bin/vapor` — make sure that's on `PATH`,
or call it by full path):

```
vapor deploy staging
```

**Staging environment variables.** Staging needs its own values for a few
settings production already has. `storage:` in `vapor.yml` makes Vapor create
a staging S3 bucket and inject `AWS_BUCKET`. If that bucket is missing, every
Livewire action shows the **"This page has expired"** prompt: the S3 disk
throws a `TypeError`, and Livewire returns 419 when `APP_DEBUG=false`. Set
`APP_NAME` in the staging environment (`vapor env staging`) to the name
prospects should see in the sidebar; without it the sidebar says "Laravel".
If a 419 prompt shows up again, check the staging function's CloudWatch log
group (`/aws/lambda/vapor-tdr2027-staging`) for the real exception.

`vapor.yml`'s `staging` environment has no build step that runs migrations
automatically. After the deploy finishes, run migrations once:

```
vapor command staging --command="php artisan migrate --force"
```

## 2. Seed staging

Two separate commands — base reference data, then the demo dataset.

```
vapor command staging --command="php artisan db:seed --force"
vapor command staging --command="php artisan db:seed --class=SampleHonorChoirAssociationSeeder --force"
```

The first command is the **default** `DatabaseSeeder`, but it's safe to run
as-is on staging: every CSV-backed seeder in that chain (schools,
organizations, events, teachers, students, versions, candidates, etc.)
reads from `database/seeders/data/*.csv`, which is real NJ production data
that is **gitignored and excluded from Vapor deploys** (`.vaporignore`).
Those files won't exist on staging, so each of those seeders prints a
"skipped, file not found" warning and does nothing. What actually runs is
just the lookup tables (Geostate, County, Pronoun, VoicePart, Instrument,
Roles) plus one generic `test@example.com` user — no real student/teacher
data ever touches staging.

**Faker dependency:** both commands create rows through model factories,
which need `fakerphp/faker`. Faker is a `require-dev` package and deploys
are built with `composer install --no-dev`, so the `staging` build in
`vapor.yml` adds it back with a separate
`composer require fakerphp/faker ... --update-no-dev` step. It adds about
11 MB, and production doesn't get it. Without that step, both commands fail
with `Class "Faker\Factory" not found`: the default seeder stops after the
lookup tables (so `users` stays empty), and the demo seeder's single
transaction rolls back completely (no schools, events, versions, or users).
If you see empty tables after this step, check the command output for that
error first, then confirm the deployed build includes the Faker step.

The second command builds the fictional demo dataset described in §0. It's
**idempotent** — safe to re-run before any specific prospect call. It wipes
its own previously-seeded rows (matched by the fixed organization/school
names) and rebuilds from scratch, so you always get a clean Fall 2025
closed cycle and a fresh Fall 2026 open cycle with nobody's already
registered a test candidate from a prior demo.

## 3. Demo logins

Both use the password **`password`**.

| Role | Email | Notes |
|---|---|---|
| Event Manager | `demo.eventmanager@sample-honorchoir.example` | Has the Event Manager role on **both** cycles — use this to show the admin/registration-manager side: candidate lists, results, ensemble rosters, the still-open Fall 2026 cycle. Also a working teacher at Sample Lakeside High School (active, verified school link, 8 students, 6 candidates per cycle, 2 held back), so the same login can show a manager registering their own students. |
| Teacher | `demo.teacher@sample-honorchoir.example` | This is "Dana Whitfield" at Sample North High School — an active, verified school link with a full roster of students and candidates in both cycles. Use this to show the teacher-side registration experience. |

## 4. Getting to the staging URL

`vapor.yml` currently has **no custom domain configured** for `staging`
(only `production` has domains). After the first deploy, grab the
Vapor-assigned URL from either:

```
vapor open staging
```

or the environment's page in the Vapor dashboard (`vapor ui`). If you want
a memorable URL for prospect calls, that's a separate step (add a `domains`
block to `staging` in `vapor.yml` + `vapor cert`/`vapor record`) — not done
here, flagging it so it doesn't come as a surprise mid-demo-prep.

## 5. Resetting before a specific call

Just re-run the second seed command from §2:

```
vapor command staging --command="php artisan db:seed --class=SampleHonorChoirAssociationSeeder --force"
```

This gives you a clean slate — useful if a previous demo (or a prospect
poking around themselves) left the open Fall 2026 cycle with test
registrations you don't want visible on the next call.

## 6. Local verification already done

Before this was handed off:

- `SampleHonorChoirAssociationSeederTest` (Pest, `tests/Feature/`) runs the
  seeder end-to-end against an in-memory sqlite DB and asserts row counts,
  ensemble assignments, AuditionResult/Recording presence, demo-login
  passwords, and role/gate correctness (the Teacher demo login's school
  link is active + verified, which is what actually gates student
  visibility). A second test confirms running the seeder twice is
  idempotent. Both pass.
- `vendor/bin/phpstan analyse` (project's existing `level: 5` config) passes
  clean on the new seeder and test file.
- No browser testing was done (project convention — see memory) and no
  screenshots were taken of the seeded data; the PHPStan/Pest results above
  are the verification, not a UI walkthrough. You'll want to click through
  it yourself once staging is actually seeded.
