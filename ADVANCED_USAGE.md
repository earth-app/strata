# 📗 Advanced Usage

> Real-world recipes for running Strata at scale

Strata's parts compose. The [README](README.md) covers what each one is; this document walks through
the deployments people actually build out of them, with a runnable sketch for each and a pointer at
the test that proves it works.

Every recipe below is exercised by a spec in [`tests/`](./tests/) or by a script in
[`scripts/`](./scripts/) that you can run against a throwaway site:

```bash
./startup.sh                         # a ddev drupal 11 site at /tmp/drupal-strata with minio
./startup.sh traffic --scale=medium  # traffic that looks like a site somebody uses
./startup.sh scenario --scale=medium # traffic, measure, rollback, meltdown, heal, attack
```

> The snippets are sketches against the public API. The linked spec is the asserted version that runs
> in CI.

## 🔑 Settings and Credentials

Every recipe reads from `strata.settings`, which exports with the rest of your site configuration.
Each recipe lists what it needs under **Prerequisites**. This is the catalog of what those keys are
and where the values come from.

| Key                                             | What it is                                       | Example                         | Where it comes from                                 |
| ----------------------------------------------- | ------------------------------------------------ | ------------------------------- | --------------------------------------------------- |
| `provider`                                      | Active provider id                               | `s3`, `azure`, `gcs`, `b2`      | whichever storage submodule you enabled             |
| `site_id`                                       | Prefix every key sits under                      | `earth-prod`                    | defaults to a digest of the database identity       |
| `key`                                           | `drupal/key` entity holding the encryption key   | `strata_encryption`             | `/admin/config/system/keys`, 32 bytes hex           |
| `retired_keys`                                  | Keys still needed to open old frames             | `[strata_2025]`                 | filled by `drush strata:rotate-key`                 |
| `s3.bucket` / `.region` / `.endpoint`           | S3 target                                        | `backups` / `us-east-1`         | your bucket; endpoint only for non-AWS              |
| `s3.access_key_id` / `.secret_access_key`       | S3 credentials                                   | `AKIA...`                       | leave empty to use env, `~/.aws` or an EC2 role     |
| `s3.path_style`                                 | Address the bucket in the path, not the host     | `true` for MinIO                | required by MinIO, Ceph and Garage                  |
| `azure.account` / `.container`                  | Azure target                                     | `earthapp` / `strata`           | the storage account and a blob container            |
| `azure.account_key` / `.sas_token`              | Azure credentials                                | a base64 key, or a SAS          | portal, Access keys or Shared access signature      |
| `gcs.bucket`                                    | GCS target                                       | `earth-strata`                  | your bucket                                         |
| `gcs.service_account`                           | Service-account JSON                             | `{"type":"service_account"...}` | IAM, a key for an account with Storage Object Admin |
| `b2.bucket` / `.key_id` / `.application_key`    | B2 target and credentials                        | `earth-cold`                    | B2, Application Keys; scope it to the bucket        |
| `flush.max_age` / `.max_bytes` / `.max_ops`     | Whichever fires first seals a segment            | `15` / `4194304` / `5000`       | your recovery point objective                       |
| `journal.backend`                               | Where operations queue before a flush            | `database` or `redis`           | `redis` needs `strata_redis` and `ext-redis`        |
| `capture.*`                                     | Which realms are captured                        | `true`                          | `/admin/config/system/strata/capture`               |
| `capture.access_churn`                          | How `access` and `login` timestamps are recorded | `event`                         | `event`, `full` or `off`                            |
| `tiers`                                         | The bucket ladder                                | see recipe 3                    | `/admin/config/system/strata/tiers`                 |
| `retention.base_interval`                       | Seconds between base anchors                     | `14400`                         | a restore-latency dial                              |
| `budget.bytes_per_month` / `.dollars_per_month` | Monthly ceilings                                 | `10737418240` / `5.00`          | what you are willing to spend                       |
| `drill.enabled` / `.sample`                     | Scheduled restore drills                         | `true` / `50`                   | how much to prove per run                           |
| `merge.base_ceiling` / `.walk_limit`            | How far a merge looks                            | `10000` / `5000`                | raise on a very long history                        |
| `telemetry.endpoint`                            | OpenTelemetry collector                          | `http://otel:4318`              | your collector                                      |

## 📚 Recipe Index

| Recipe                                                             | Uses                                 | What it gets you                                      |
| ------------------------------------------------------------------ | ------------------------------------ | ----------------------------------------------------- |
| [Undo One Field](#-undo-one-field)                                 | `strata`, `strata_ui`                | A surgical rollback that leaves everything else alone |
| [Recover From a Bad Deploy](#-recover-from-a-bad-deploy)           | code realm + config realm            | Code and settings back, content untouched             |
| [Tiered Buckets](#-tiered-buckets)                                 | `tiers`, `strata:tiers`              | Old history on colder storage, automatically          |
| [Two Vendors, One History](#-two-vendors-one-history)              | `strata_s3` + `strata_b2`            | A copy that survives losing an entire account         |
| [Many Sites, One Bucket](#-many-sites-one-bucket)                  | `site_id`, `SiteScopedProvider`      | Shared deduplication with separate histories          |
| [High-Traffic Tuning](#-high-traffic-tuning)                       | `flush`, `journal`, `capture`        | Capture that stays under 10 us per mutation           |
| [A Configuration Release Branch](#-a-configuration-release-branch) | `strata:branch`, `strata:merge`      | Settings staged and merged like code                  |
| [Prove the Backups Work](#-prove-the-backups-work)                 | `drill.*`, `strata:verify`           | A pass or fail verdict instead of an assumption       |
| [Survive a Meltdown](#-survive-a-meltdown)                         | tripwires, `strata:heal`             | Automatic repair, and a clear refusal when it cannot  |
| [Move to Another Provider](#-move-to-another-provider)             | `strata:export`, `strata:reindex`    | A migration with the history intact                   |
| [Rotate the Encryption Key](#-rotate-the-encryption-key)           | `strata:rotate-key`                  | A new key without losing what the old one sealed      |
| [Watch It From Outside](#-watch-it-from-outside)                   | webhooks, `strata_notify`, telemetry | An alert when the store falls behind                  |
| [Rehearse a Whole-Site Restore](#-rehearse-a-whole-site-restore)   | `--dry-run`, physical restore        | A rehearsal that never touches production             |
| [Large Media Libraries](#-large-media-libraries)                   | `strata_files`                       | Terabytes of media without paying for it twice        |
| [Stay Inside a Free Tier](#-stay-inside-a-free-tier)               | `budget`, `strata:estimate`          | A hard ceiling that degrades instead of overspending  |

---

## 🎯 Undo One Field

**Uses:** `strata`, `strata_ui`: [`RestoreTest.php`](./tests/src/Kernel/RestoreTest.php),
[`RollbackFlowTest.php`](./tests/src/Functional/RollbackFlowTest.php)

### Prerequisites

- **A provider and a key**: `provider`, `key`. Any provider will do, including `local` for a trial.
- **A permission**: `rollback strata content` for the account doing it.

Somebody rewrote a node body and published it. You want that one field back, at that one revision,
without disturbing anything else that has happened since.

```bash
# find the moment; the timeline at /admin/reports/strata/timeline does the same thing visually
drush strata:list --limit=20

# what actually changed between those two points, field by field
drush strata:diff HEAD~3 HEAD~2

# the manifest first, then the write
drush strata:restore HEAD~3 entity/node:42 --dry-run
drush strata:restore HEAD~3 entity/node:42
```

A restore never deletes content created after the restore point, and it seals a snapshot of the
current state into its own commit before the first write. Rolling back the rollback is an ordinary
restore targeting that snapshot, and `drush strata:audit` prints its id.

**Use case:** an editor mistake, an import that went wrong, a bad find-and-replace.

## 🚑 Recover From a Bad Deploy

**Uses:** code realm + config realm: [`CodeCaptureTest.php`](./tests/src/Kernel/CodeCaptureTest.php)

### Prerequisites

- **Code capture on**: `capture.code`. It costs about 9 MiB a year across 150 deploys.
- **Two permissions**: `rollback strata config` is restricted, since configuration includes
  `user.role.*` and `filter.format.*`.

A release went out and the site is throwing. The code and the settings that shipped with it are
wrong, but the content people created in the meantime is fine and must stay.

```bash
# what the deploy actually changed, including composer.lock and settings.php
drush strata:diff HEAD~1 HEAD
drush strata:diff HEAD~1 HEAD --subject=code/composer.lock

# put named configuration back without touching content
drush strata:restore HEAD~1 config/system.site,config/user.settings --dry-run
drush strata:restore HEAD~1 config/system.site,config/user.settings
```

Code is captured as bytes for your own modules and themes, with `composer.lock` standing in for the
whole `vendor/` tree. A patched dependency raises `code.vendor_drift`, and only then are the drifting
files stored. Schema changes are captured but a logical restore refuses them by name and points you
at physical restore, because replaying DDL against a live schema is not surgical.

**Use case:** a release that broke the site, where the database is not the thing at fault.

## 🪣 Tiered Buckets

**Uses:** `tiers`, `strata:tiers`: [`TierTest.php`](./tests/src/Kernel/TierTest.php)

### Prerequisites

- **One bucket per tier**, all reachable by the same provider and credentials.
- **A storage class per tier** if the provider has them: `STANDARD_IA`, `GLACIER_IR`, R2 Infrequent
  Access.

One bucket is the default and needs no configuration. A long-lived site wants recent history where it
is fast and cheap to read, and years of it somewhere that costs almost nothing to keep.

```yaml
# strata.settings, exported with the rest of your config
tiers:
    enabled: true
    verify_copies: true
    levels:
        - { name: hot, provider: s3, location: earth-hot, storage_class: STANDARD, from_age: 0 }
        - {
              name: warm,
              provider: s3,
              location: earth-warm,
              storage_class: STANDARD_IA,
              from_age: 2592000
          }
        - {
              name: cold,
              provider: s3,
              location: earth-cold,
              storage_class: GLACIER_IR,
              from_age: 15552000
          }
        - {
              name: deep,
              provider: s3,
              location: earth-deep,
              storage_class: DEEP_ARCHIVE,
              from_age: 63072000
          }
```

```bash
# what each bucket holds, whether it answers, and what a restore of one commit would need
drush strata:tiers
drush strata:tiers --restore=HEAD~500
```

Every write lands in tier 0, because an object's age is zero when it is written. A cron pass moves
objects one tier at a time as they age past each threshold, so every intermediate state is one the
code can describe. An object keeps its content address wherever it lives, so a copy in two buckets is
the same object.

Three refusals hold it together. A ladder with a repeated bucket, a threshold that does not increase,
or a nearest tier with an age is refused when the settings are read, not at the first flush. An
unreachable tier is reported as unreachable, never as empty, and a prune refuses outright on a partial
picture. A restore names the buckets it needs at plan time.

**Use case:** seven years of retention on a site where only last month gets read.

## 🔒 Two Vendors, One History

**Uses:** `strata_s3` + `strata_b2`:
[`ProviderSelectionTest.php`](./tests/src/Kernel/ProviderSelectionTest.php),
[`TierTest.php`](./tests/src/Kernel/TierTest.php)

### Prerequisites

- **Two accounts at two vendors**, so one compromised or closed account cannot take both.
- **Bucket-scoped credentials on the far one**: B2 Application Keys and Azure SAS tokens can both be
  scoped to a single bucket with no delete permission.

A ladder does not have to stay with one vendor. A tier marked as retaining below is a replica rather
than a destination, so the nearer copy stays and you get a second chance at the same object.

```yaml
tiers:
    enabled: true
    levels:
        - { name: hot, provider: s3, location: earth-hot, from_age: 0 }
        - {
              name: archive,
              provider: b2,
              location: earth-archive,
              from_age: 2592000,
              retain_below: true
          }
```

```bash
drush en strata_s3 strata_b2 -y

# check both answer before you rely on both
drush strata:status
drush strata:tiers

# read every frame back and check its digest, in every bucket
drush strata:verify
```

Content addressing is what makes this cheap. The same frame in two buckets is one object with one
name, so the replica costs storage and nothing else, and a restore that finds the near copy missing
reads the far one without being told to.

**Use case:** ransomware, a closed account, a region outage, or an accidental `rm` with the wrong
credentials loaded.

## 🏠 Many Sites, One Bucket

**Uses:** `site_id`, `SiteScopedProvider`: [`ArchiveTest.php`](./tests/src/Kernel/ArchiveTest.php),
[`SecurityTest.php`](./tests/src/Kernel/SecurityTest.php)

### Prerequisites

- **A distinct `site_id` per site.** It defaults to a digest of the database name and table prefix,
  which is what actually distinguishes two sites sharing a codebase.
- **One set of credentials**, since every site writes into the same bucket under its own prefix.

Twenty sites on one codebase share their configuration and their module code exactly. Deduplication is
per frame and content-addressed, so the second site's code realm costs almost nothing.

```bash
# per site, in its own settings
drush -l site-a strata:status
drush -l site-a config:set strata.settings site_id site-a
drush -l site-b config:set strata.settings site_id site-b

# what the bucket holds, per site
drush strata:sites
```

What they must not share is history. A commit is a statement about one site at one instant, and a ref
two sites both advanced would describe neither. Every key is prefixed with the site id, and refs,
commits, anchors and segments all live under it.

**Use case:** a multisite platform, or a hosting account running many builds of the same distribution.

## 🚄 High-Traffic Tuning

**Uses:** `flush`, `journal`, `capture`: [`FlushTest.php`](./tests/src/Kernel/FlushTest.php),
[`JournalBackendTest.php`](./tests/src/Kernel/JournalBackendTest.php),
[`OperationTest.php`](./tests/src/Kernel/OperationTest.php)

### Prerequisites

- **`ext-zstd`**, which is 4.28x at 422.9 MB/s against gzip -9's 4.40x at 67.0 MB/s. The flush runs
  inside a web request.
- **`ext-redis` and `strata_redis`** for the journal, so a burst does not write to the database.
- **A calibration run on the real host**, because none of the figures here were measured on yours.

Capture costs about 6 to 7 us per mutation and the statement tap adds about 3 us. On a site doing 10
million statements a day that is 0.09% of one core. What actually needs tuning at volume is how often
you seal, where operations queue, and what you record about session churn.

```bash
# measure this host before changing anything
drush strata:calibrate --level=fast
drush strata:estimate --users=500000 --nodes=2000000

# queue in redis instead of the database
drush en strata_redis -y
drush config:set strata.settings journal.backend redis

# seal on bytes and count before age, so a burst does not sit in the journal
drush config:set strata.settings flush.max_age 15
drush config:set strata.settings flush.max_bytes 4194304
drush config:set strata.settings flush.max_ops 5000

# two thirds of write volume is session timestamps; keep the audit trail, drop the field delta
drush config:set strata.settings capture.access_churn event

# a longer base interval trades restore latency for fewer anchors
drush config:set strata.settings retention.base_interval 14400
```

Leave `capture.access_churn` on `event`. Turning it `off` costs more, not less: the row is still
dirty, and the reconciler captures it later as a full field delta. Measured on a 50,000-user site, the
48-byte event is 0.716 GB a year, the full delta 0.939 GB, and dropping it 0.726 GB.

If a flush is ever slow enough to notice, `drush strata:status` prints the RPO lag and
`/admin/reports/strata/graphs` draws the operation rate. The statement tap has a hard off switch, and
the settings form shows what `strata:calibrate` measured for it on this host.

**Use case:** a site with a large authenticated user base, where session writes dominate everything
else.

## 🌿 A Configuration Release Branch

**Uses:** `strata:branch`, `strata:merge`: [`BranchTest.php`](./tests/src/Kernel/BranchTest.php)

### Prerequisites

- **Two permissions**: `branch strata config` to cut and list, `merge strata config` to write. Merging
  writes to the live site, so it is the more dangerous of the two.
- **Configuration capture on**: `capture.config`.

Configuration is captured whole, so a three-way merge over it is defined on complete values. That
makes a branch useful for staging a settings change, and it is why a branch carries the configuration
realm only. Every other realm is a field delta against a parent, and merging two divergent delta
chains would mean inventing a value wherever they disagree.

```bash
# cut a branch off the current tip and work on it
drush strata:branch release-12
drush strata:branch # list, including the trunk

# meanwhile the trunk moves on its own

# the manifest: every object, its classification, and both values for a conflict
drush strata:merge release-12 --dry-run
drush strata:merge release-12
```

An object touched on only one side is taken from that side. An object whose different keys were
touched on both sides comes out `merged`, which is a value neither side wrote and is labelled as such.
The same key changed to different values on both sides is a conflict, is never auto-resolved, and
stops the plan until you pass `--strategy=ours` or `--strategy=theirs`.

The apply is a restricted logical restore, so it keeps the forced snapshot and the audit row. Three
commits come out of it: the snapshot, the commit sealing the configuration it wrote, and a two-parent
merge commit whose second parent is the branch tip.

**Use case:** staging a permissions or field change through review before it reaches production.

## ✅ Prove the Backups Work

**Uses:** `drill.*`, `strata:verify`: [`DrillTest.php`](./tests/src/Kernel/DrillTest.php),
[`VerifyTest.php`](./tests/src/Kernel/VerifyTest.php)

### Prerequisites

- **Drills on**: `drill.enabled`, with `drill.sample` for how many subjects to prove per run and
  `drill.interval` for how often.
- **Cron running.** The drill is a cron stage and has no Drush command of its own, so a site with no
  cron never runs one.

Two different questions. A drill asks whether the store reproduces the site. A verify asks whether the
bytes are still the bytes.

```bash
# replay a bounded sample on its own interval and diff it against what the site holds now
drush config:set strata.settings drill.enabled 1
drush config:set strata.settings drill.sample 50
drush config:set strata.settings drill.interval 86400
drush cron

# read every frame back and check it hashes to the address it is filed under
drush strata:verify
drush strata:verify --shallow # the index only, no bytes

# what capture missed, measured on the tables themselves
drush strata:audit
```

A subject that reproduces exactly counts as matched. One the store cannot produce a value for counts
as unreadable and fails the drill. A subject edited after the commit being replayed is skipped, not
failed, because a difference there means the site is newer. A drill that could judge nothing reports
`inconclusive`, never `pass`, and a failing one raises `drill.drift`.

A full verify is the only thing that catches silent corruption, and it costs a Class B read per frame,
so run it on a schedule and use `--limit` to spread it out.

**Use case:** an audit that asks when you last tested a restore, and needs an answer with a date on it.

## 🔥 Survive a Meltdown

**Uses:** tripwires, `strata:heal`: [`MeltdownTest.php`](./tests/src/Kernel/MeltdownTest.php),
[`meltdown.php`](./scripts/meltdown.php), [`heal.php`](./scripts/heal.php)

### Prerequisites

- **Two permissions**: `repair strata` for the automatic rungs, `quarantine strata` for the ones that
  remove a restore target.
- **`health.auto_repair`** for the cron-driven rungs.

Twenty tripwires watch the store. Each asserts a symptom, is O(1) or explicitly bounded, and never
repairs anything, so repair can be gated and rate-limited on its own.

```bash
# break it on purpose, on a throwaway site
./startup.sh meltdown --kind=everything --share=40

# what is open, and which rung each finding sits on
drush strata:heal --list

# run the safe rungs
drush strata:heal --apply
drush strata:heal frame.missing --rung=refetch

# the two rungs a person has to authorise
drush strata:quarantine <commit>

# a bucket that survived a dropped database
drush strata:reindex
drush strata:reindex --adopt-ref   # the ref was deleted but the commits are there
```

`observe`, `reindex`, `refetch` and `rebuild` run automatically, gated by a circuit breaker keyed on
the finding code, so a persistent fault escalates instead of looping. `quarantine` and `refuse` never
run on their own, because both remove or block a restore target.

Nothing invents data. A preflight classifies every subject as `restorable`, `degraded` or
`unrestorable`, and a degraded subject is skipped, listed in the manifest and recorded in the audit
log. Filling one with defaults is opt-in per scope and the confirm form spells out what it would do.

**Use case:** a half-finished flush after a host died, a wiped bucket, or bit rot nobody noticed.

## 📦 Move to Another Provider

**Uses:** `strata:export`, `strata:reindex`: [`ArchiveTest.php`](./tests/src/Kernel/ArchiveTest.php),
[`ReindexTest.php`](./tests/src/Kernel/ReindexTest.php)

### Prerequisites

- **Both providers enabled** during the move, so you can read the old one and write the new one.
- **`export strata archive`** and **`import strata archive`** permissions.

An archive is self-contained: it carries its dictionaries and its delta anchors, so it restores
somewhere that has never seen the original store.

```bash
# everything, or a window starting at one commit
drush strata:export /backup/strata-full.tar.gz
drush strata:export /backup/recent.tar.gz --from=4f2a1b --limit=200

# point at the new bucket, then bring the history over
drush en strata_gcs -y
drush config:set strata.settings provider gcs
drush config:set strata.settings gcs.bucket earth-strata

drush strata:import /backup/strata-full.tar.gz --dry-run
drush strata:import /backup/strata-full.tar.gz
drush strata:verify
```

If the bucket already holds the objects and only the local index is gone, skip the archive entirely.
Packs carry a `PackIndex` trailer and standalone frames a `FrameEnvelope` header, so every frame's
offset, codec, cipher and decoded length travel with the bytes. `drush strata:reindex` rebuilds the
frame index, the commit index and the branch index from the bucket alone.

**Use case:** leaving a vendor, consolidating accounts, or recovering after the database was restored
from an older dump than the bucket.

## 🔐 Rotate the Encryption Key

**Uses:** `strata:rotate-key`: [`KeyRotationTest.php`](./tests/src/Kernel/KeyRotationTest.php)

### Prerequisites

- **A new `key` entity**, 32 bytes of hex. Keep the old one.
- **`manage strata storage`** permission.

Frames are sealed with XChaCha20-Poly1305 at 391 MB/s. Rotation is a keyring operation: the new key
becomes active, the old one is retired but still tried, and re-sealing happens pack by pack rather
than frame by frame.

```bash
# make the new key and hand it to strata
drush key:save strata_2026 --label='Strata 2026' --key-type=encryption \
	--key-provider=config --key-provider-settings='{"key_value":"'"$(openssl rand -hex 32)"'"}'

drush config:set strata.settings key strata_2026

# re-seal what still opens under a retired key, whole packs at a time
drush strata:rotate-key --dry-run
drush strata:rotate-key
```

Do not remove the old key from `retired_keys` until `strata:rotate-key` reports nothing left. A frame
whose key is gone raises `frame.aead_fail`, and `key.rotated_mid_flight` fires if the active key
changes while a flush is in progress.

**Use case:** an annual rotation policy, or a key you have reason to think leaked.

## 📡 Watch It From Outside

**Uses:** webhooks, `strata_notify`, telemetry:
[`WebhookTest.php`](./tests/src/Kernel/WebhookTest.php),
[`TelemetryTest.php`](./tests/src/Kernel/TelemetryTest.php),
[`NotifyTest.php`](./modules/strata_notify/tests/src/Kernel/NotifyTest.php)

### Prerequisites

- **`strata_notify`** for mail, and a webhook endpoint that answers fast.
- **A collector** at `telemetry.endpoint` if you want spans and metrics.

Six events are dispatched: `strata.commit.sealed`, `strata.restore.finished`,
`strata.health.finding`, `strata.budget.breached`, `strata.prune.applied` and
`strata.drill.finished`.

```bash
drush en strata_notify -y

# mail, gated on a severity floor so a warning does not wake anyone
drush config:set strata_notify.settings recipients 'ops@example.com'
drush config:set strata_notify.settings severity 2

# spans and metrics
drush config:set strata.settings telemetry.endpoint 'http://otel-collector:4318'
```

Webhook payloads are queued rather than sent inline, so an unreachable endpoint never slows a flush.
When a secret is set the delivery carries an HMAC over the timestamp and the exact body:

```text
X-Strata-Event: strata.restore.finished
X-Strata-Delivery: 3f1c9a2b4d5e6f708192a3b4c5d6e7f8
X-Strata-Signature: t=1735689600,v1=9f86d081884c7d65...
```

Check the timestamp as well as the digest. The digest alone lets any captured delivery be replayed
forever.

The metric worth alerting on is `strata.rpo_lag_seconds`, the age of the newest sealed commit. That is
exactly how much captured work would be lost if the host died now, and it is the one number that says
whether backups are actually happening.

**Use case:** an on-call rotation that needs to know the store fell behind before an editor does.

## 🧪 Rehearse a Whole-Site Restore

**Uses:** `strata:rollback --dry-run`, physical restore:
[`PhysicalRestoreTest.php`](./tests/src/Kernel/PhysicalRestoreTest.php),
[`RestoreTest.php`](./tests/src/Kernel/RestoreTest.php)

### Prerequisites

- **A copy of the site** to rehearse on, with the same bucket and key configured read-only.
- **MySQL or PostgreSQL** for `ShadowSwapStrategy`. On SQLite it refuses cleanly instead of
  half-restoring, and `TruncateRestoreStrategy` works on all three.
- **`rollback strata database`** permission, which is separate from restoring content.

Rehearse the restore you hope never to run. A dry run reports the whole plan without writing anything,
so it answers the interesting questions on production and the rehearsal only has to confirm timing.

```bash
# the manifest and the restorable / degraded / unrestorable split, no writes
drush strata:rollback HEAD~500 --dry-run

# what buckets it would need, before it starts reading them
drush strata:tiers --restore=HEAD~500

# on the copy, with the site in maintenance mode
drush state:set system.maintenance_mode 1
drush strata:rollback HEAD~500 -y
drush state:set system.maintenance_mode 0
```

Physical restore takes one of two strategies. `ShadowSwapStrategy` loads into shadow tables and swaps
them in one atomic rename, which is why it needs MySQL or PostgreSQL. `TruncateRestoreStrategy` is
driver-agnostic and takes the site into maintenance mode. A logical restore refuses the schema realm
by name and points at physical restore, since replaying DDL against a live schema is not a surgical
operation.

Degraded subjects are skipped and listed unless you pass `--fill-degraded`, and a concurrent edit
stops the plan unless you pass `--accept-conflicts`. Both are opt-in on purpose.

**Use case:** a quarterly disaster-recovery exercise that has to produce evidence, and a timing number
for the runbook.

## 🎬 Large Media Libraries

**Uses:** `strata_files`:
[`ManagedFileTest.php`](./modules/strata_files/tests/src/Kernel/ManagedFileTest.php),
[`FileCaptureTest.php`](./tests/src/Kernel/FileCaptureTest.php)

### Prerequisites

- **`strata_files`** enabled, and its own budget line, since files are about 98% of stored bytes.
- **A storage class for cold objects**, because media gets almost none of the compression benefit.

Files take their own path: fixed 64 KiB blocks at 645 MB/s, stored once per unique content, on an
infrequent-access class, with their own retention ladder.

```bash
drush en strata_files -y

# project the initial capture before you turn it on; the first upload is the expensive one
drush strata:estimate --files=100000 --file-bytes=800000000000

drush config:set strata.settings capture.file 1
drush config:set strata.settings file.storage_class STANDARD_IA
drush config:set strata.settings file.shift_threshold 0.3
```

A 2 MiB in-place edit to a 256 MiB file stores 2.1 MiB, which is 0.81%. Fixed blocks cannot follow an
insertion, so a 2 MiB insert would cost 61% of the file. A changed-block ratio above 30% is the
signature of shifted content and raises `file.shift_detected`, which offers whole-object storage or an
opt-in content-defined chunker per file type. The chunker runs at 4.29 MB/s in PHP and the UI prints
that number next to the switch, so nobody enables it by accident.

**Use case:** a video or image library measured in terabytes, where the initial capture is the whole
cost and everything after it is nearly free.

## 💸 Stay Inside a Free Tier

**Uses:** `budget`, `strata:estimate`:
[`CompactionTest.php`](./tests/src/Kernel/CompactionTest.php),
[`InstallTest.php`](./tests/src/Kernel/InstallTest.php)

### Prerequisites

- **A ceiling you actually mean**: `budget.bytes_per_month`, `budget.dollars_per_month`, or both, plus `budget.action`.
- **Cron running**, since the guard is evaluated on cron and escalates between runs.

R2's free plan is 10 GB-month of storage, 1,000,000 Class A operations and free egress. A 15 second
flush uses 20% of the Class A allowance at any site size, so storage is the binding constraint and
files are what fills it.

```bash
# project first, then set the ceiling under it
drush strata:estimate --users=50000 --nodes=200000
drush config:set strata.settings budget.bytes_per_month 10737418240

# what it has actually cost so far, read from the store and the request log
./startup.sh measure
drush strata:status
```

The guard escalates rather than cutting off: it warns, then reduces retention, then pauses
non-critical capture, raising `budget.exceeded` at each step and logging what it did. Files are on
their own ladder and their own budget line, so media growth cannot quietly evict database history.

Compaction is what buys headroom back. It recompresses at zstd level 19 with a trained dictionary,
5.86x against the flush path's 4.28x, and re-anchors delta chains. Collapse gains almost nothing below
the day level, so run it on a schedule and read the receipt.

```bash
drush strata:compact
drush strata:prune --dry-run # exactly which restore points this would destroy
```

**Use case:** a site that must never generate a storage bill, and needs the module to enforce that
itself.
