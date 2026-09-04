# ⛰️ Strata

> Advanced Backup & Instant Rollback for Drupal 11.2+, over any object store.

[![Packagist](https://img.shields.io/packagist/v/earth-app/strata)](https://packagist.org/packages/earth-app/strata)
[![Build](https://github.com/earth-app/strata/actions/workflows/build.yml/badge.svg)](https://github.com/earth-app/strata/actions/workflows/build.yml)
[![Coverage](https://github.com/earth-app/strata/actions/workflows/coverage.yml/badge.svg)](https://github.com/earth-app/strata/actions/workflows/coverage.yml)
[![Prettier](https://github.com/earth-app/strata/actions/workflows/prettier.yml/badge.svg)](https://github.com/earth-app/strata/actions/workflows/prettier.yml)
[![codecov](https://codecov.io/gh/earth-app/strata/branch/master/graph/badge.svg)](https://codecov.io/gh/earth-app/strata)
[![Drupal](https://img.shields.io/badge/drupal-%3E%3D11.2-0678be.svg)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.3-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Strata** is a backup and rollback module for Drupal that records every mutation your site makes into
a content-addressed, deduplicated, compressed, encrypted object store, and can put the site back to
any instant. It is designed to be simple to run and hard to lose data with, while still giving you a
rollback that can target one field on one node.

It keeps a commit log instead of periodic snapshots, so storage cost is linear in **churn** and not in
`snapshots x site size`. It is written in PHP and speaks to S3, Cloudflare R2, Azure Blob Storage,
Google Cloud Storage and Backblaze B2.

```bash
composer require earth-app/strata
drush en strata strata_s3 strata_ui -y
drush strata:calibrate
```

## ❓ Why?

- **Granular**: A rollback can target one field on one node. You are not restoring the whole site to
  undo one edit.
- **Cheap**: Cost follows how much the site changes, so a full year of database, configuration,
  content and code history fits inside R2's free tier.
- **Verifiable**: Scheduled drills replay the store and diff it against the live site, so you find
  out the backups work before you need them.
- **Self-healing**: Twenty tripwires watch the store, and a repair ladder fixes what it safely can
  without waking anyone up.
- **Measured**: Every figure below was taken through the code that ships, and `drush strata:calibrate`
  takes them again on your own host.

## 🧰 Features

- **Capture**
    - Entities, configuration, state and key-value entries
    - Custom tables and schema changes
    - Managed and unmanaged files, as 64 KiB blocks
    - Module and theme code, with `composer.lock` standing in for `vendor/`
    - Authoritative Redis keys
- **Storage**
    - AWS S3, Cloudflare R2 & any S3-compatible endpoint
    - Azure Blob Storage, Google Cloud Storage & Backblaze B2
    - Tiered buckets, so old history moves to colder storage on its own
    - ...and much more!
- **Rollback**
    - Logical restore, down to a single field
    - Physical restore of whole tables
    - Configuration branches & three-way merges
    - A forced snapshot before anything is written
- **Operations**
    - 27 Drush commands
    - Timeline, diff viewer, graphs, storage explorer & a health dashboard
    - Mail, webhook & OpenTelemetry outputs
    - Budget guards with a cost estimator
    - ...and much more!

## 🏗️ How It Works

```text
mutation -> hook and event capture (~6-7 us, field-level delta)
                  |
                  v
         journal (Redis stream or database table)
                  |  adaptive flush: 15 s, 4 MiB or 5,000 operations
                  v
   delta coding against the previous version (63.7x on a rewrite)
                  |
                  v
   16 KiB framing -> BLAKE2b -> dedup -> zstd -> XChaCha20-Poly1305 -> pack
                  |
                  v
   segment manifest + base anchor + commit -> object store
                  |
                  v  cron
   compaction: recompress, re-anchor delta chains, roll up, prune with a receipt
```

Each captured change becomes an operation of 181 to 2,211 bytes. Operations accumulate in a local
journal and a flush seals them into a segment, frames the payloads at a fixed 16 KiB, addresses each
frame by its BLAKE2b-256 digest, and writes only frames the store does not already hold.

A **base anchor** is a complete index of every subject at a moment. A restore starts from the nearest
anchor and applies the operations after it. Anchors are chained and record only what changed since
the previous one, which keeps a flush at four write requests whatever the size of the site.

Files take a separate path: fixed 64 KiB blocks, stored once per unique content, on an
infrequent-access storage class. A 2 MiB in-place edit to a 256 MiB file stores 2.1 MiB.

Module code takes a third path: the site's own modules and themes as bytes, `settings.php` redacted,
and `composer.lock` as the reference for everything in `vendor/`. The whole code realm costs about
9 MiB a year across 150 deploys.

## 📦 Requirements

| Requirement  | Version                                                      |
| ------------ | ------------------------------------------------------------ |
| PHP          | 8.3 or newer                                                 |
| Drupal       | 11.2 or newer                                                |
| `ext-sodium` | Required. BLAKE2b addressing and XChaCha20-Poly1305 sealing  |
| `ext-zlib`   | Required. The compression floor                              |
| `ext-zstd`   | Strongly recommended. 4.28x at 422.9 MB/s on the flush path  |
| `ext-brotli` | Optional. Higher ratio at much lower throughput              |
| `ext-redis`  | Optional. Backs the journal with a stream instead of a table |
| `drupal/key` | Required. Holds the encryption key                           |

Without `ext-zstd`, Strata uses the `zstd` binary through one long-lived pipe when it is on `PATH`.
Failing that it deflates against a preset dictionary through `ext-zlib`, which is already required,
so delta coding keeps working on a stock VPS with nothing installed. Plain gzip is the last resort,
and at 8 KiB frames it costs 76% more stored bytes than zstd with a dictionary.

## 🚀 Installation

```bash
composer require earth-app/strata
drush en strata -y
```

Choose a storage provider and an encryption key at
`/admin/config/system/strata/storage`, then confirm what the host can do:

```bash
drush strata:status
drush strata:calibrate
```

`strata:calibrate` measures compression ratios, dictionary gain, delta-coding gain and capture
overhead on the host that will run them. Use its numbers, not the ones below.

## 🧩 Submodules

| Module          | Provides                                                                    |
| --------------- | --------------------------------------------------------------------------- |
| `strata`        | Capture, journal, object store, restore, compaction, health, Drush commands |
| `strata_s3`     | AWS S3, any S3-compatible endpoint, and Cloudflare R2                       |
| `strata_azure`  | Azure Blob Storage over the Blob REST API                                   |
| `strata_gcs`    | Google Cloud Storage over the JSON API                                      |
| `strata_b2`     | Backblaze B2 over the native API, which takes a bucket-scoped key           |
| `strata_ui`     | Timeline, diff viewer, graphs, storage explorer, health dashboard           |
| `strata_files`  | Managed and unmanaged file capture as 64 KiB blocks                         |
| `strata_notify` | Mail and webhook notifications                                              |
| `strata_redis`  | Capture of the authoritative parts of a Redis keyspace                      |

The top-level module is the engine. Each submodule is optional, and uninstalling one leaves stored
history untouched.

### Provider Maturity

Every provider signs its own requests and is covered by unit tests over a mocked transport. What
differs is how far each one has been driven against something that answers.

| Provider              | Exercised against                                |
| --------------------- | ------------------------------------------------ |
| S3, S3-compatible, R2 | MinIO, in CI on every push                       |
| Azure Blob Storage    | Azurite, when `STRATA_AZURE_ENDPOINT` is set     |
| Google Cloud Storage  | fake-gcs-server, when `STRATA_GCS_BUCKET` is set |
| Backblaze B2          | Nothing yet; B2 has no emulator                  |

Run `drush strata:verify` after the first flush on any of them, which fetches and decodes everything
the store holds, and read the report before trusting a schedule. On B2 that is the only evidence
available.

## ⚙️ Configuration

Settings live at `/admin/config/system/strata/`, one page per concern: Storage, Capture, Retention,
Webhooks, Telemetry, Notifications. Everything is in `strata.settings` and exports with the rest of
the site configuration.

Three settings move the outcome more than the others.

**Access churn.** Two thirds of a Drupal site's write volume is the `access` and `login` timestamps
core updates for every active session. Recording each as a compact 48-byte login event costs 24% less
than a full field delta, and less than dropping it: a dropped operation leaves the row dirty for the
reconciler to capture later at full price. `access`, `login` and `init` are never written back by a
restore.

**Flush interval.** The default is whichever of 15 seconds, 4 MiB or 5,000 operations arrives first.
This is the durability window - how much captured work is lost if the host dies before a flush - and
not the restore granularity. Operations inside a segment stay individually addressable.

**Base interval.** The default is four hours. A longer interval writes fewer anchors and each one
records more changed subjects, so the two effects largely cancel and stored bytes barely move. What
it does control is restore latency: at four hours and a 15 second flush, a replay to an arbitrary
moment walks up to 960 segments.

## 🧰 Commands

```bash
drush strata:status                                  # provider, key, budget headroom, open findings
drush strata:flush                                   # seal the current window now
drush strata:list --limit=20                         # recent commits
drush strata:diff HEAD~1 HEAD                        # what changed, field by field
drush strata:restore HEAD~1 entity/node:42 --dry-run # one subject, manifest only
drush strata:rollback HEAD~1 --dry-run               # everything at that commit
drush strata:verify                                  # read every frame back and check its digest
drush strata:audit                                   # what the reconciler found that capture missed
drush strata:compact                                 # recompress, re-anchor, roll up
drush strata:prune --dry-run                         # what pruning would destroy
drush strata:estimate --users=50000 --nodes=200000
drush strata:export /tmp/site.tar.gz
drush strata:branch release-12   # cut a config branch
drush strata:merge release-12    # three-way merge it back
drush strata:reindex             # rebuild the local index from the bucket
drush strata:reindex --adopt-ref # recover a history whose ref was deleted
drush strata:rotate-key          # re-seal what still opens under a retired key
drush strata:tiers               # which bucket holds what, and what a restore needs
```

Twenty-seven commands in total, and `drush list --filter=strata` shows them all. `strata:restore`,
`strata:rollback`, `strata:prune`, `strata:import`, `strata:merge` and `strata:rotate-key` all take
`--dry-run` and print their manifest before asking. `strata:verify` reads every frame back by default,
and `--shallow` checks the index without the bytes.

## 📊 Reports

`strata_ui` adds six pages under `/admin/reports/strata/` and `/admin/config/system/strata/`.

**Status.** Whether the site is backed up right now: how much captured work would be lost if the host
died, when the last commit was sealed, what is stored, and the same checks Drupal's own status report
shows. It is the landing page for the section and renders before anything has been configured.

**Timeline.** Every point the site can be restored to, pannable and zoomable to the second. Each bar
links to the commits inside it. The window is in the URL, so a view can be bookmarked or shared.

**Diff viewer.** Field-level differences between any two commits, over Drupal's own diff engine.
Field names are visible to anyone who may see diffs. The values need the payload permission, because
they are the content of the site.

**Graphs.** Stored size, operation rate, deduplication ratio, compression ratio, delta chain depth
and monthly cost. Deduplication ratio and chain depth are whole-store readings and are drawn flat.

**Storage explorer.** What is stored now, and what deleting something would actually free. A frame
can be shared by many commits, held as the anchor a later version decodes against, or named as a
dictionary by frames elsewhere, so a removal is priced by what would become unreachable.

**Health dashboard.** Open findings by severity, the repair rung each sits on, and what the last
restore drill proved.

The pages need no JavaScript. Wheel-zoom and drag-pan are added when scripting is available, and
every navigation is also a link. Colours are taken from the admin theme's own tokens, so the pages
match whatever theme is drawing them. Charts honour `prefers-reduced-motion` and `prefers-contrast`,
and encode state in shape as well as colour.

## 🔬 Measurements

Every figure Strata acts on was measured through its own code path. These were taken on PHP 8.5.7,
Darwin arm64, over a 6,357,223-byte corpus of entity JSON and custom-table rows including
incompressible hashed tokens.

Compression at 16 KiB frames:

| Codec  | Level | Dictionary | Ratio     | Compress       | Decompress |
| ------ | ----- | ---------- | --------- | -------------- | ---------- |
| gzip   | 9     | no         | 4.40x     | 67.0 MB/s      | 589.8 MB/s |
| zstd   | 1     | no         | **4.28x** | **422.9 MB/s** | 640.3 MB/s |
| zstd   | 19    | no         | 4.87x     | 4.5 MB/s       | 640.3 MB/s |
| zstd   | 19    | yes        | **5.86x** | 2.4 MB/s       | 640.3 MB/s |
| brotli | 11    | yes        | 6.07x     | 0.9 MB/s       | 461.6 MB/s |

zstd level 1 is the flush-path default: a fraction worse on size than gzip -9 for more than six times
the throughput, on a path that runs inside a web request. The dictionary costs throughput badly per
frame, so it is used during compaction and as the enabler for delta coding.

Throughput of each pipeline stage:

| Operation                            | Throughput     |
| ------------------------------------ | -------------- |
| BLAKE2b-256                          | 718 MB/s       |
| Fixed 16 KiB framing plus BLAKE2b    | 683 MB/s       |
| XChaCha20-Poly1305 encrypt / decrypt | 391 / 392 MB/s |
| Fixed 64 KiB file blocks             | 645 MB/s       |
| Content-defined chunking, pure PHP   | 4.29 MB/s      |

Content-defined chunking is 120 to 160 times slower than every other stage, so it is on no default
path. It survives as an opt-in, byte-budgeted option for files that genuinely shift, with its
measured throughput shown next to the switch.

Delta coding against the previous version, over 1,000 blobs averaging 5,967 bytes with one flag
flipped and one entry appended:

| Encoding                | Bytes per object | Ratio      |
| ----------------------- | ---------------- | ---------- |
| zstd -19 standalone     | 1,330            | 4.49x      |
| zstd -19 against parent | **94**           | **63.70x** |

In aggregate, where small operations dominate by count, delta coding removes 25.8% of journal bytes.

Large-file behaviour on a 256 MiB file:

| Block size   | Hash time | 2 MiB in-place edit | 2 MiB insertion |
| ------------ | --------- | ------------------- | --------------- |
| **64 KiB**   | 0.40 s    | **2.1 MiB (0.81%)** | 155.6 MiB (61%) |
| whole object | -         | 256 MiB (100%)      | 256 MiB (100%)  |

Fixed blocks cannot follow an insertion. A changed-block ratio above 30% is the signature of shifted
content and raises `file.shift_detected`, which offers whole-object storage or the opt-in chunker per
file type. Media re-encodes, metadata rewrites and in-place patches never reach it.

## 💰 Cost

Stored per year with zstd, dictionaries and delta coding, at a 15 second flush and a 4 hour base
interval:

| Site                   | Policy                    | Per year  | R2 / month | S3 / month |
| ---------------------- | ------------------------- | --------- | ---------- | ---------- |
| 1k users / 5k nodes    | database, config, content | 0.03 GB   | free       | $1.01      |
| 10k users / 40k nodes  | database, config, content | 0.24 GB   | free       | $1.01      |
| 50k users / 200k nodes | database, config, content | 1.21 GB   | free       | $1.04      |
| 50k users / 200k nodes | plus 80 GB of files       | 70.74 GB  | $0.91      | $2.63      |
| 500k users / 2M nodes  | database, config, content | 12.11 GB  | $0.03      | $1.29      |
| 500k users / 2M nodes  | plus 800 GB of files      | 707.35 GB | $10.46     | $17.28     |

Files are about 98% of stored bytes and receive almost none of the compression benefit, so they get
their own retention ladder, budget line and storage class.

Marginal cost of growth: 100,000 users is 1.026 GB a year, 100,000 nodes is 0.100 GB, and a gigabyte
of media is 0.952 GB. A user costs about ten times what a node costs, because users generate access
churn, profile edits and notification writes while nodes mostly sit.

On Cloudflare's R2 free plan - 10 GB-month of storage, 1,000,000 Class A operations, egress free - a
15 second flush uses 20% of the Class A allowance at any site size, and storage is the binding
constraint. That supports a full year of complete database, configuration, content, custom-table and
module-code history, with per-operation restore granularity, for a site of roughly 1,529,000 users
and 6.1 million nodes.

On AWS S3, request cost is a floor a small site cannot escape: a 1,000-user site pays about $1.01 a
month entirely in PUTs at a 15 second interval. The install wizard recommends 15 seconds on R2 and
60 seconds on S3 for sites under about 10,000 users.

## 🗂️ Tiered Buckets

One bucket is the default and needs no configuration. A site that wants more can put history on a
ladder of buckets, nearest first, with an age threshold on each:

| Tier | Holds               | Written to          |
| ---- | ------------------- | ------------------- |
| 0    | Everything, now     | Every flush         |
| 1    | Older than 30 days  | By a migration pass |
| 2    | Older than 6 months | Less often          |
| 3    | Older than 2 years  | Least often         |

Every write lands in tier 0, because an object's age is zero when it is written. A cron pass moves
objects that have aged past a threshold one tier at a time, so every intermediate state is one the
code can describe. A tier marked as retaining below is a replica rather than a destination: the
nearer copy stays, which is what buys a second chance at the same object.

An object keeps its content address wherever it lives, so a copy in two buckets is the same object
and not a second one. Where each object is gets recorded in `strata_placement`, which is derived
state: `drush strata:reindex` rebuilds it by listing the buckets, and an uninstall dropping the
table costs nothing.

Three refusals hold the whole thing together:

- A ladder with a repeated bucket, a threshold that does not increase, or a nearest tier with an age
  is refused when the settings are read, not at the first flush.
- An unreachable tier is reported as unreachable, never as empty. A verify pass says which bucket it
  could not read, and a prune refuses outright rather than deleting on a partial picture.
- A restore names the buckets it needs before it starts, so an operator learns that a rollback wants
  the cold bucket at plan time.

`drush strata:tiers` reports what each bucket holds, whether it answers, and what a restore of a
given commit would need.

## 🩹 Self-Healing

A **tripwire** asserts a symptom, is O(1) or explicitly bounded, and never repairs anything.
Detection and repair are separate so repair can be gated, rate-limited and escalated independently.
Twenty tripwires ship, covering missing and unreadable frames, digest mismatches, authentication
failures, short pack reads, broken delta chains, chains past the cap, missing dictionaries,
truncated segments, missing commit parents, unreachable anchors, a lost ref, an unreachable tier, a
key rotated mid-flush, shifted files, vendor drift, unclassified growth and watermark drift.

Findings go to a ledger with a bounded context column and are put on a repair ladder:

| Rung         | Does                                                         | Automatic     |
| ------------ | ------------------------------------------------------------ | ------------- |
| `observe`    | Records only                                                 | yes           |
| `reindex`    | Rebuilds the local index from the bucket                     | yes           |
| `refetch`    | Re-downloads and re-verifies an object                       | yes           |
| `rebuild`    | Regenerates a derived object from what survives              | yes           |
| `quarantine` | Removes a commit from the restore targets, keeping its bytes | **no, human** |
| `refuse`     | Blocks the restore entirely                                  | **no, human** |

Automatic repair is gated by a circuit breaker keyed on the finding code, so a persistent fault
escalates rather than looping. Anything that removes a restore target or blocks a restore is a
decision for a person.

**Nothing invents data.** A preflight classifies every subject as `restorable`, `degraded` or
`unrestorable`. A degraded subject is skipped by default, listed in the manifest and recorded in the
audit log. Filling one with defaults is an opt-in choice the confirm form spells out.

Restore drills close the loop: on a schedule, Strata replays a sample of subjects and compares them
against what the site holds, then publishes a pass, fail or inconclusive verdict. A drill that could
judge nothing reports `inconclusive` rather than `pass`.

## 🔐 Permissions

Rollback is not one permission. Restoring one node and replacing every table have different blast
radii, so the check is per realm and a plan spanning three realms needs all three.

| Permission                 | Grants                                      |
| -------------------------- | ------------------------------------------- |
| `administer strata`        | Everything, including storage credentials   |
| `view strata timeline`     | When the site changed and who changed it    |
| `view strata diffs`        | Which fields changed between two points     |
| `view strata payloads`     | The stored values themselves                |
| `view strata health`       | Findings, rungs and circuit-breaker state   |
| `rollback strata content`  | Restore entities                            |
| `rollback strata config`   | Restore configuration                       |
| `rollback strata database` | Replace whole table contents                |
| `repair strata`            | Run an automatic repair rung                |
| `quarantine strata`        | Remove a commit from the restore targets    |
| `delete strata snapshots`  | Prune stored history                        |
| `manage strata storage`    | Change the provider, credentials and bucket |

Configuration rollback includes `user.role.*` and `filter.format.*`, so it is a path to further
privilege. It is marked restricted and is never implied by content rollback. A per-realm permission
is also generated for each realm, and a per-provider permission for each configured provider.

Optional two-person approval requires that the account approving a destructive restore is not the
account that requested it.

## 🔔 Events and Webhooks

Six events are dispatched: `strata.commit.sealed`, `strata.restore.finished`,
`strata.health.finding`, `strata.budget.breached`, `strata.prune.applied` and
`strata.drill.finished`.

Webhook subscriptions are configured at `/admin/config/system/strata/webhooks`. Each payload is
posted as JSON and queued rather than sent inline, so an unreachable endpoint never slows a flush.
When a secret is set, the delivery carries an HMAC over the timestamp and the exact body:

```text
X-Strata-Event: strata.restore.finished
X-Strata-Delivery: 3f1c9a2b4d5e6f708192a3b4c5d6e7f8
X-Strata-Signature: t=1735689600,v1=9f86d081884c7d65...
```

A receiver must check that the timestamp is recent as well as that the digest matches. The digest
alone lets any captured delivery be replayed indefinitely.

Spans and metrics can be exported to any OpenTelemetry collector. The metric worth alerting on is
`strata.rpo_lag_seconds`, the age of the newest sealed commit, which is how much captured work would
be lost if the host died now.

## ❓ FAQ

### At a 15-second interval, does a quiet site keep writing duplicates?

**Quick answer.** No. Nothing extra gets written, and you do not lose storage when nothing is
happening.

**Technical answer.** Three independent guards. `Flusher::flush()` returns
`skipped('nothing pending')` when fewer than one operation is pending, before it consults the policy,
takes the lease or builds a provider. `FlushPolicy::reason()` separately returns NULL below one
pending operation, so the age bound is unreachable without pending work. And every object is
addressed by its content, so even a repeated flush of an identical window produces the same segment,
anchor and commit id, and `CommitLog::write()` checks `exists()` before putting.

The interval is a maximum staleness bound on work that exists - the recovery point objective - not a
heartbeat. The 201,635 Class A operations a month quoted for a 15 second interval is the worst case
for a continuously written site. The cron stages on a quiet site - code capture, the reconciler,
keyspace discovery - write only to local `strata_*` tables and cost no requests at all.

### Does a longer flush interval make rollback less precise?

**Quick answer.** No. It only changes how much you would lose in a crash.

**Technical answer.** A segment is an ordered manifest of individually addressable operations, each
with its own sequence number, microsecond timestamp, subject and payload digest. `Replayer` resolves
a subject by walking from the nearest anchor and applying operations up to a target, and the target
is a commit, not a segment boundary. Lengthening the interval puts more operations in each segment
and changes nothing about their individual addressability. What it changes is the durability window:
operations captured but not yet flushed live only in the local journal.

### What happens to my backups if I uninstall the module?

**Quick answer.** Nothing. The bucket is untouched, and reinstalling recovers the full history.

**Technical answer.** `ModuleInstaller::uninstall()` calls `hook_uninstall()` before
`uninstallSchema()`, so `strata_uninstall()` runs while the tables still exist and exports the three
tables nothing in the bucket can reproduce - `strata_restore_log`, `strata_health` and `strata_drill` -
to the file system first. `uninstallSchema()` then drops only tables declared in `hook_schema()`, and
nothing in Drupal core touches remote storage. Everything else is derived state: `drush
strata:reindex` rebuilds the frame index and the commit index from the objects in the bucket. Packs
carry a `PackIndex` trailer and standalone frames a `FrameEnvelope` header, so every frame's offset,
codec, cipher and decoded length travel with the bytes rather than living only in the dropped table.

### Why is turning access-timestamp capture off more expensive than keeping it?

**Quick answer.** Because the row is still dirty, and something else picks it up later at full price.

**Technical answer.** Core writes `users_field_data.access` whenever the request time exceeds the last
access by `session_write_interval`, 180 seconds by default. Suppressing the capture does not suppress
the write. The reconciler measures each captured table's row count, highest ordering value, highest
changed timestamp and a sampled digest, notices the table moved with no operation recorded, and
captures the drifted rows as a full field delta. Measured on a 50,000-user site: a 48-byte login event
costs 0.716 GB a year, a full field delta costs 0.939 GB, and dropping the operation costs 0.726 GB -
more than the event, and without the login audit trail.

### How is a rollback itself reversible?

**Quick answer.** A snapshot of the current state is captured and flushed before anything is written,
and there is no switch to turn that off.

**Technical answer.** `LogicalRestore::apply()` calls the flusher to seal the pre-restore state into
its own commit before the first write, and records that commit id on the `RestoreAudit` row. Rolling
back a rollback is therefore an ordinary restore targeting that commit. A restore also never deletes
content that postdates the restore point, so a rollback narrows what exists rather than truncating
history.

### Can two sites share one bucket?

**Quick answer.** Yes, and often they should.

**Technical answer.** Deduplication is per frame and content-addressed, so two sites running the same
modules share the frames for their identical configuration and code, and the second site's code realm
costs almost nothing. What they must not share is history: a commit is a statement about one site at
one instant, and a ref two sites both advanced would describe neither. Every key is therefore
prefixed with a site id through `SiteScopedProvider`, and refs, commits, anchors and segments all live
under it. The id defaults to a digest of the database connection's own identity - the database name
and table prefix - because that is what distinguishes two sites sharing a codebase and it does not
change when a domain does.

### How do I know the backups actually restore?

**Quick answer.** Set `drill.enabled` and let cron prove it. Nothing else actually answers the
question.

**Technical answer.** The drill stage replays a bounded sample of subjects from the store on its own
interval and compares each against what the site holds now. A subject that reproduces exactly counts
as matched. One the store cannot produce a value for counts as unreadable and fails the drill. A
subject edited after the commit being replayed is **skipped**, not failed, because a difference there
means the site is newer, not that the store is wrong. A drill that could judge nothing reports
`inconclusive`, never `pass`. Every drill is recorded in `strata_drill` and a failing one raises a
`drill.drift` finding. Separately, `drush strata:verify` reads every frame back and checks that its
bytes hash to the address it is filed under. That is the only thing that catches silent corruption,
and `--shallow` is what turns it off.

### Why fixed-size framing instead of content-defined chunking?

**Quick answer.** Content-defined chunking runs at 4.29 MB/s in PHP, which is 150 times slower than
everything else in the pipeline.

**Technical answer.** FastCDC with per-byte `ord()` measured 4.29 MB/s, and `unpack('C*')` over 64 KiB
windows measured 5.63 MB/s while exhausting a 128 MB memory limit on an 8 MB buffer. An 80 GB initial
file capture would spend 5.9 hours of CPU in the chunker alone. Fixed 16 KiB framing with BLAKE2b runs
at 683 MB/s, and an append-only operation log has no shifted content for chunking to find. What
chunking was meant to buy - not re-storing a value because part of it moved - is bought instead by
`zstd -D` against the previous version, at 63.70x on the rewrite class and at zstd speed.

## 🧪 Development

```bash
composer install && bun install

bunx prettier --check .
./vendor/bin/phpcs
./vendor/bin/phpstan analyse --memory-limit=1G

bun run test:unit # fully offline, including the SigV4 vectors
bun run test:kernel
```

The kernel lane runs under paratest and defaults to in-memory SQLite. The physical-restore
strategies, the journal and the statement tap all behave differently per driver, so it also runs
against MySQL and PostgreSQL from [docker/compose.yml](docker/compose.yml):

```bash
docker compose -f docker/compose.yml up -d mariadb postgres

bun run test:kernel:mysql
bun run test:kernel:pgsql
bun run test:kernel:all    # all three, in order
bun run test:kernel:serial # one process, for a failure that only appears in order
```

A driver runs the suite with a random table prefix, which is what makes a prefix-dependent bug
visible. Running only the SQLite lane will not find one.

CI splits the same suite across four jobs. The plan comes from `phpunit --list-tests`, so it stays
balanced as tests are added, and it is reproducible locally:

```bash
bun run test:shard -- --suite=Kernel --total=4 --plan    # what each shard would take
bun run test:shard -- --suite=Kernel --index=3 --total=4 # write phpunit.shard.xml
bun run test:kernel:shard                                # run just that shard
```

The browser lane installs a real site into a synthesised root and drives it over HTTP, so it needs a
server. One command brings the server up, or reuses one already listening, and runs the suite:

```bash
./startup.sh functional        # start or reuse the server, then run the lane
./startup.sh serve --port=8090 # just the server, on a port of your choosing
./startup.sh serve --stop      # stop the one this started
```

The server is detached from the shell that started it and is independent of the DDEV site below, so
the two run side by side.

`./startup.sh` builds a throwaway DDEV Drupal 11 site at `/tmp/drupal-strata` on port 8788, with the
engine, `strata_ui`, `strata_files` and `strata_notify` installed and four accounts, one per
permission level, all with the password `demo`.

It can run against any of the object stores, each in its own container, so the same traffic can be
sealed into a different API and compared:

```bash
./startup.sh                       # minio, which is how r2 and every s3-compatible endpoint behaves
./startup.sh --provider=azure      # azurite, over the native Blob REST API
./startup.sh --provider=gcs        # fake-gcs-server, over the native JSON API
./startup.sh --provider=s3 --tiers # two buckets, the near one plus a replica
./startup.sh --provider=local      # the filesystem, no container at all
```

Backblaze B2 has no emulator, so there is no simulated lane for it. Point `b2.api_url` at a real
account, or run `modules/strata_b2/tests/src/Kernel/B2IntegrationTest.php` with credentials.

`--db=mariadb|postgres|sqlite` picks the database, because the physical-restore strategies are
driver-specific. `--no-ui` installs the engine alone. `--fresh` rebuilds from scratch.

The DDEV web image ships neither `ext-zstd` nor `ext-brotli`, so the build in
[docker/web-build/](docker/web-build/) adds both extensions and both CLI binaries to the container.
Without them the playground compresses with gzip and none of the measurements above can be
reproduced by hand. `--no-codecs` skips that build.

That site can then be driven, measured and broken by hand:

```bash
./startup.sh traffic --scale=medium # traffic that looks like a site somebody uses
./startup.sh measure                # what it holds, what it cost, what a month costs
./startup.sh codecs                 # every codec measured on the payloads it captured
./startup.sh rollback --to=HEAD~1   # the manifest a rollback would write
./startup.sh meltdown --kind=everything --share=40
./startup.sh heal --apply            # rebuild what can be rebuilt, name what cannot
./startup.sh attack                  # what each probe an attacker would try gets
./startup.sh scenario --scale=medium # all of it, in order
```

Code style is tabs at four columns, 100 columns, LF. Prettier is the authority on formatting.

## 📝 Contributing

Contributions are welcome! Feel free to open an issue or submit a pull request.

This project is licensed under the MIT License - see the [LICENSE](./LICENSE) file for details.
