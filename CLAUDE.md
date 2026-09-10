# CLAUDE.md

Working notes for this repository. The [README](README.md) is the product document; this is what an
agent needs that the README does not say.

## What This Is

`earth-app/strata` is a public, drupal.org-review-ready Drupal 11.2+ contrib module. It captures every
mutation a site makes into a content-addressed object store on S3-compatible storage and can restore
any instant. Cost is linear in churn, not in `snapshots x site size`.

It is distributed on GitHub, released through Packagist, and later intended for the `drupal/*`
namespace. Code has to pass drupal.org review, so nothing here may assume a private deployment.

## Style, Non-Negotiable

- **Tabs at four columns, 100 column soft limit, LF, UTF-8.** Prettier is the authority on
  formatting; where phpcs disagrees with prettier, prettier wins. `Drupal.Files.LineLength` is set to
  110 for exactly that reason: prettier accounts for a docblock's leading `*` and phpcs counts raw
  characters, so 10 columns of headroom is the difference between the two measurements.
- **ASCII only in strings and comments.** No em dashes, no curly quotes, no arrows. Use `-`, `'`,
  `"`, `<`, `>`.
- **Always `use`-import a class; never write a backslash-prefixed name.** This includes docblocks. A
  class that appears only in an `@see`, `@param`, `@return` or `@throws` still gets an import. The one
  structural exception is a YAML value, which has no import mechanism, so
  `\Drupal\strata\Access\StrataPermissions::permissions` in `strata.permissions.yml` is correct.
- **Comments are lowercase, one short sentence, no trailing period, and only where the WHY is not
  obvious.** Two lines maximum. No file-header comment blocks except a real `@file` docblock.
- `#region Title Case` / `#endregion` marks a foldable group. Not dashed dividers.
- Prefer `final class`. Prefer a value object over a shaped array once it has more than about four
  fields or any behaviour.
- **No `@phpstan-ignore`, no baseline, no `assert()`, no inline `@var`, no cast and no widened type to
  silence PHPStan.** Fix the cause.

## Verification

Run all five. Paste the real counts; never imply coverage that was not run.

```bash
bunx prettier --check .
./vendor/bin/phpcs
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/phpunit --testsuite Unit
bun run test:kernel
```

The default 128M memory limit makes PHPStan OOM and report a fake "2 errors"; that is not a pass.

The Kernel suite skips the vendor integration classes, and the count depends on what is reachable.
Measured on 2026-08-22 with MinIO, Azurite and fake-gcs all up: 12 skips, being
`AzureIntegrationTest`, `GcsIntegrationTest` and `B2IntegrationTest` at four each. **A running
emulator is not enough for two of them:** Azure needs `STRATA_AZURE_ENDPOINT` and `STRATA_AZURE_KEY`,
GCS needs `STRATA_GCS_BUCKET` plus one of `STRATA_GCS_KEY_FILE` or `STRATA_GCS_TOKEN`, whereas
`MinioIntegrationTest` probes its endpoint and so runs whenever MinIO answers. B2 has no emulator, so
its four never run without real credentials.

`bun run test:kernel --filter <ClassName>` while iterating.

**The Functional lane cannot use the in-memory SQLite `phpunit.xml.dist` declares**, because the test
runner and the web server are two processes and an in-memory database is not shared between them. The
failure names nothing useful: the browser gets a 500 whose assertion header reads
`The service file "core/core.services.yml" is not valid`, on every test, in `setUp()`. PHPUnit cannot
scope an `<env>` to one testsuite, so the lane has its own config file:

```bash
bun run serve &         # php tests/drupal-root.php, then php -S localhost:8087
bun run test:functional # phpunit -c phpunit.functional.xml
```

`phpunit.functional.xml` carries the file-backed DSN. The path in it is relative and both processes
resolve it against the Drupal root, which is how one file reaches both.

**A green SQLite run is not a green suite.** `phpunit.xml.dist` points at in-memory SQLite, and
SQLite hides two whole classes of defect: it implements a table prefix as an attached database rather
than as a name prefix, and it stores any byte sequence in any column. Four separate bugs have reached
CI through that gap - the statement tap recording prefixed table names, the journal sequence
overflowing a 32-bit column, and two fixtures putting invalid UTF-8 in a varchar. **Anything touching
SQL, the journal schema, the statement tap or a test fixture's columns runs all three drivers before
it is called done:**

```bash
docker compose -f docker/compose.yml up -d mariadb postgres
bun run test:kernel:all
```

PHPStan is pinned to `phpVersion.min: 80300` because the composer floor is `php: ^8.3` and some rules
are version-gated - readonly promoted properties are incompatible with `DependencySerializationTrait`
below 8.4, which a run on 8.5 cannot see. The Lint job also runs `php tests/drupal-root.php` first:
`mglaman/phpstan-drupal` is auto-included by `phpstan/extension-installer` and registers module
namespaces from a Drupal root, so without one `Drupal\key\KeyRepositoryInterface` is `class.notFound`.

## Tests

Three lanes with different budgets:

| Lane       | Directory              | Budget                                         |
| ---------- | ---------------------- | ---------------------------------------------- |
| Unit       | `tests/src/Unit`       | Deterministic, offline, free, fast             |
| Kernel     | `tests/src/Kernel`     | A booted Drupal on SQLite, MySQL or PostgreSQL |
| Functional | `tests/src/Functional` | A browser and a live backend                   |

The Kernel lane runs under paratest with `--functional --max-batch-size 20`, which splits by test
method rather than by class. Workers share a database without sharing a schema, because every kernel
test gets its own random table prefix. CI splits the same suite four ways through `ShardPlanner`,
whose plan is a pure function of `phpunit --list-tests` and therefore needs no coordination between
jobs. `bun run test:kernel:serial` is the escape hatch for a failure that only appears in order.

**A table prefix isolates a schema, not the database.** Anything global to the database is still
shared, and PostgreSQL has one of those on the kernel path: core's pgsql install tasks run
`CREATE EXTENSION IF NOT EXISTS pg_trgm` in **every** `setUp()`, and that statement is not atomic. On
a fresh database every worker in the first wave sees it missing, every one issues `CREATE`, and the
losers get `duplicate key value violates unique constraint "pg_extension_name_index"` - which core
catches and reports as "Failed to run installer database tasks", so it reads like a Strata failure
inside `setUp()` rather than a race. Measured 2026-08-22: extension dropped plus eight workers gives
7 failures out of 85; the identical command with it present gives 85 passing.

It is **first-run-only**, which is what makes it dangerous: the winner creates the extension, so every
later run on that container passes and the bug is invisible locally while CI fails on every fresh
database. `docker/postgres-init/pg_trgm.sql` creates it at first init and the `restore` job in
`build.yml` creates it before the workers start. A new database-global dependency needs the same
treatment.

Every test carries `#[Test]`, `#[TestDox('a lowercase sentence describing the behaviour')]` and
`#[Group('strata/<area>')]`. Method names are bare camelCase with no `test` prefix. Test methods get
no docblock - the TestDox is the description. Test classes get a real class docblock saying what the
lane proves and why.

**One spec file per domain.** Never create `*-expansion`, `*-additional` or `*-part2` alongside an
existing spec; extend the existing one.

**A stale-router fixture cannot be built with the uninstaller.** A real uninstall rebuilds the router
AND `user_modules_uninstalled()` strips the module's permissions out of every role, so every route
answers 403 at the access check and no controller is ever resolved - the reproduction passes for the
wrong reason. What an operator does is delete the code, which runs no hook: the roles keep the
permissions and the router keeps the rows. `InstallFlowTest::orphan()` edits `core.extension` and
calls `$kernel->invalidateContainer()`, which is the one operation that drops a module's services
without asking anything to rebuild the router. Never `drupal_flush_all_caches()` there.

Three things this suite does deliberately and should keep doing:

- **Assert a status code, not the absence of one.** `everyDeclaredRouteAnswers()` asserted
  `assertNotSame(404, ...)` until 1.0.3, so the only sweep across both modules with every permission
  granted passed on a 500.

- **Drive every report page on an empty store as well as a populated one.** A fresh install is the
  first thing a user sees, and code that divides by a total or indexes the newest commit works on a
  busy site and fatals on an empty one. That is how the uncaught refusal on the storage explorer was
  found: encryption is on by default with no key until somebody chooses one.
- **Assert the claim a docblock makes, not the code that implements it.** Several docblocks here have
  turned out to be wrong, and in every case a test found it.
- **Never end a sweep's assertions inside a loop with no guard that the loop runs.**
  `ExtensionTest::theRootModuleRequiresWhatItDependsOn()` skipped every dependency whose project part
  was `drupal`, so with `drupal:key` in the info file it iterated nothing and asserted nothing while
  reporting green - the test written to catch a missing `drupal/key` could not see `key` at all. The
  filter is now a lookup against `vendor/drupal/core/modules`, so both spellings resolve. Every other
  sweep that reads a file list carries `assertNotSame([], ...)` before the loop for the same reason.

## Where Things Live

| Path                                                      | Holds                                                               |
| --------------------------------------------------------- | ------------------------------------------------------------------- |
| `src/Engine.php`                                          | The single façade. Everything is assembled here from configuration  |
| `src/Journal/`                                            | Capture buffer, Redis stream or database table                      |
| `src/Cas/`                                                | Hashing, 16 KiB framing, frame index, packing, object store         |
| `src/Delta/`                                              | `zstd -D` coding against the previous version, chain cap, re-anchor |
| `src/Codec/`                                              | Codec registry, dictionaries, calibration                           |
| `src/Crypto/`                                             | XChaCha20-Poly1305, key provider                                    |
| `src/Segment/` `src/Tree/`                                | Segment manifests, commits, base anchors, refs, subject index       |
| `src/Capture/` `src/Hook/`                                | Entity, config, state, key-value, statement and cron capture        |
| `src/Restore/`                                            | Replay, preflight, logical and physical restore, audit              |
| `src/Compaction/`                                         | Recompression, rollup, reachability, prune receipts                 |
| `src/Health/`                                             | Tripwires, findings, repair ladder, circuit breaker, ledger         |
| `src/Timeline/` `src/Diff/` `src/Metrics/` `src/Explore/` | Read models the UI renders                                          |
| `src/Branch/`                                             | Config-only branches, merge base, three-way merge, merge commits    |
| `src/Drush/Commands/`                                     | 28 commands, thin over `Engine`                                     |
| `modules/strata_ui/`                                      | Controllers, forms, blocks, toolbar, templates, CSS, one JS file    |

`Engine` is the only place that reads settings and decides which parts a site gets. Nothing is built
until asked for and each piece is built once, so a request that captures but never flushes never
constructs a provider or a cipher. **Call `Engine::reset()` after a settings change** - the settings
forms already do.

## Decisions That Look Wrong Until Explained

Do not "fix" these without measuring first.

- **`SqlStatement::parse()` takes the connection prefix and strips it; the subject is the logical
  table, never the physical one.** A statement event carries SQL that is already prefixed, so
  recording it verbatim writes a `settings.php` deployment detail into the subject a restore looks
  for, and - worse - `CaptureScope::coversTable()` stops recognising `strata_` as this module's own,
  so journaling a write becomes a write that gets journaled. Core's `Schema::findTables()` already
  strips the prefix, so the tap was the only place reading a physical name.
- **`strata_journal.sequence` is a big serial, not a plain one.** A serial never reuses a number, so
  the column climbs with every operation ever captured rather than with the rows held; at 800,000
  operations a day a 32-bit key runs out in about seven years. It also makes the type honest:
  `trim(PHP_INT_MAX)` is a legitimate way to say "everything", and against a 32-bit column MySQL and
  SQLite coerce it silently while PostgreSQL refuses the statement.
- **Fixed 16 KiB framing, never content-defined chunking.** CDC measured 4.29 MB/s in pure PHP
  against 683 MB/s for framing plus BLAKE2b. It is 120 to 160 times slower than every other stage.
  What it was meant to buy is bought by `zstd -D` against the previous version instead.
- **zstd level 1 on the flush path, level 19 in compaction.** Level 1 is 4.28x at 422.9 MB/s; level
  19 with a dictionary is 5.86x at 2.4 MB/s. The flush runs inside a web request.
- **A dictionary is loaded per frame, so it costs throughput badly** - 422.9 MB/s down to 105.1 MB/s
  at level 1. CLI timing hides this completely because the CLI loads a dictionary once per
  invocation.
- **`DictionaryTrainer` builds both a raw-concatenation and a `zstd --train` candidate and keeps
  whichever scores better on held-out samples.** Neither wins generally: 11.64x against 8.42x on a
  uniform corpus, 4.96x against 5.42x on a mixed one.
- **`SubjectIndex` is permanent and never cleared by an anchor.** Its first version was truncated at
  each anchor, which left the first rewrite after every anchor with no parent to delta against, so the
  63.7x gain applied to some writes and not others with nothing saying so.
- **Recompression rewrites whole packs, never single frames.** Densifying one 16 KiB frame into its
  own object trades the compression gain for one Class A request per frame.
- **Collapse gains almost nothing below the day level** - 1.00x. What compaction buys at fine levels
  is denser compression and shorter delta chains.
- **The base interval is a restore-latency dial, not a storage dial.** A longer interval writes fewer
  anchors and each records more changed subjects; the two effects largely cancel. An earlier model had
  it as the largest lever on stored bytes and that was wrong.
- **`user.access` churn is downgraded to a 48-byte event, not dropped.** Dropping it costs MORE,
  because the row stays dirty and the reconciler captures it later at full price.
- **A commit's subject path is `entity/user:1`** - a slash after the realm, a colon inside it.
  Splitting the whole path on slashes silently produces a subject nothing can read.
- **`MetricSet` keys on the metric name AND its attributes.** Four severities under one name would
  otherwise overwrite each other down to whichever was recorded last.
- **`??` already suppresses a property access on null**, so `$obj?->prop ?? $default` is redundant and
  PHPStan is right to say so.
- **`LIBXML_NOENT` enables entity substitution.** Parse untrusted XML with
  `LIBXML_NONET | LIBXML_NOCDATA`; the common `NONET | NOENT` pairing opens XXE, measured on libxml
  2.9.13.
- **`zstd_compress_dict()` takes its level third.** Calling it with two arguments silently compresses
  at the default level 3 whatever level the caller asked for.
- **`ext-brotli` does support dictionaries** - `brotli_compress(string, int, int, ?string)`. An
  earlier comment asserted it did not and was wrong.
- **`gzdeflate()` takes no dictionary but `deflate_init()` does**, through its `dictionary` option,
  and so does `inflate_init()`. Both have been in `ext-zlib` since PHP 7.0 and `ext-zlib` is already
  a hard require, so delta coding needs no extension at all on an ordinary host: measured
  1,085 bytes to 17 against the previous version, against 43 with no dictionary. `DeflateDictCodec`
  is that, and its `cfw_zlib_dict()` bridge is a fallback for a WASM runtime shipped without the
  incremental API - not the mechanism. Both paths emit `ZLIB_ENCODING_RAW`, which is what
  `gzdeflate()` emits, so one codec id covers an anchor frame and a delta frame.
- **A branch carries the config realm only, and refuses the rest by name.** Config is captured WHOLE,
  so a three-way merge over it is defined on complete values; every other realm is a field delta
  against a parent, and merging two divergent delta chains means inventing a resolution.
- **`Commit` serializes `merge` only when it is set.** A commit is addressed by the bytes of its own
  JSON, so writing the key as NULL on every commit would re-address every commit ever written.
  `BranchTest::aSingleParentCommitRoundTripsUnchanged()` asserts the exact document.
- **A merge base walk refuses a cycle by peeling, never by "met this commit twice".** A merge whose
  branch was cut off the target's current tip reaches that tip down both sides, and every diamond
  reaches its fork point twice; both are correct histories.
- **A merge's restore plan carries `plannedAt = 0` on purpose.** The restore's own forced snapshot
  writes every pending subject between plan and apply, so timestamp-based conflict detection would
  flag every object in the plan.
- **A merge always targets `refs/heads/main`.** It writes config to the live site, and the live site
  is what the trunk describes; merging into another ref would leave the trunk describing a site that
  no longer exists.
- **A views page display overrides a module route on the same path, silently.** `config/optional`
  ships five views, and one of them claimed `admin/reports/strata/health` in 1.0.1, so the health
  dashboard was replaced by an empty table with no error anywhere. `ExtensionTest` compares every
  routing file against every shipped view's page paths and fails the build on a collision; that check
  costs milliseconds and no lane below Functional-with-Views-enabled can find this any other way.
- **A commit cannot carry its own stored size.** It is addressed by the bytes of its own JSON, so
  writing a size into it would re-address it. `Flusher::seal()` measures `ObjectStore::written()`
  across the window instead and passes it to `CommitIndex::record()`'s third argument, which existed
  and was never supplied - which is why every "Stored" column read 0 B at 1.00x until 1.0.2.
- **`ObjectStore::written()` counts what reached the provider, so a deduplicated frame costs
  nothing.** It is monotonic rather than resettable: a caller measuring one window takes the
  difference across it, and two callers sharing a store cannot clear each other's reading.

- **`composer.json` PSR-4 maps `Drupal\strata\` onto `src/`, and no submodule has its own package.**
  So every class in the root module loads off composer's autoloader whether or not the module is in
  `core.extension`, while `Drupal\strata_ui\` is registered by Drupal alone. One condition - the
  router still holding rows for a module that has left the module list - therefore produces two
  different 500s, which is exactly what a real site reported against 1.0.2:
  `ServiceNotFoundException: strata.engine` on `strata.settings.storage`, and
  `The controller for URI "/admin/reports/strata" is not callable` on `strata_ui.status`. Neither is
  a code defect on a correctly installed site; the remedy is `drush cache:rebuild`, and
  `strata_route_requirement()` says so on the status report.
- **A `*SettingsForm::create()` must ask the container, never require it.** `ClassResolver` calls the
  static factory before the form object exists, so there is no `guard()` to reach and no page to
  degrade into: whatever `create()` demands is a hard requirement of the route answering at all. Use
  `$container->has(...) ? $container->get(...) : null` against a nullable property.
  `SettingsFormTest` drives every routed form against a container holding no Strata services.
- **`PriceTable::of()` raises for a provider it ships no table for, and four are shipped.** The
  provider select offers every registered submodule, so `azure`, `gcs`, `b2` and `null` all reach it.
  Anything costing whatever a site happens to have configured calls `PriceTable::forProvider()`,
  which falls back to the local table. `StorageSettingsForm` called the raiser and made the page that
  changes the provider back the page that was down.
- **`CodecRegistry::prefer()` raises, and `Engine::codecs()` must not let it.** The raise is right for
  the registry - a form asking whether a choice can be honoured wants an answer, not a silent
  fallback - and wrong for the engine, because availability is a property of the host and a host
  changes: a PHP rebuilt without `ext-zstd`, a container image swapped, a migration between servers.
  A site that pinned a codec and then lost it had every report page and every flush raise at once.
  `Engine::codecs()` asks `canWritePerFrame()` first, falls back to the write preference, and records
  `codec.pin_unavailable` at WARN. The store is not damaged either way: every reader stays registered,
  so frames written under the old codec still decode.
- **Nothing caches codec availability, so there is no refresh button and must not be one.**
  `CodecRegistry::withShippedCodecs()` is built per request and each codec answers from the host, the
  status report and the settings form both read that live registry, and every report page is
  `max-age: 0`. What does not update on its own is the operator's own pinned choice, which is what the
  invariant above covers. Operator-facing text has to state when each probe sees a change:
  `extension_loaded()` describes the running process, so a new PHP extension needs php-fpm restarted,
  while a binary appearing on PATH is seen by the next request.
- **`LocalStorage` creates its root at the first write, so a missing directory is not a fault.**
  `strata_local_storage_requirement()` reported one as an Error until 1.0.3, which is a red mark on
  the status report of every site that had done nothing wrong. It now asks
  `LocalStorage::unreachableReason()` rather than repeating the rules, which is also the only way the
  two cannot drift; the class takes a path and nothing else, so there is no engine to assemble.
- **A key entity is generated with the `config` provider and `base64_encoded` on.** Raw random bytes
  are not valid UTF-8 and configuration is YAML, so the value has to be encoded and the provider
  decodes it on the way back out; `key_type_settings.key_size` is in BITS while
  `KeyProviderInterface::KEY_BYTES` is in bytes. `KeyMaker::create()` never overwrites an existing id
  and suffixes instead, because the value inside a key is the only copy of it.
- **Making a new key always retires the old one, and neither entry point offers a flag to skip it.**
  Everything sealed before the change stays readable only while the key that sealed it is on the ring,
  and a store whose key was dropped is indistinguishable from a corrupt one. `StorageSettingsForm`'s
  button and `strata:new-key` both push the outgoing id into `retired_keys` and name
  `strata:rotate-key` as the pass that ends the rotation.
- **The generate button carries `#limit_validation_errors => []` and saves configuration itself.** The
  state it exists for is the one where the form cannot be saved at all - encryption on, no key chosen -
  so it has to be pressable while validation fails, and the rest of the submitted values are therefore
  unchecked and must not be written.
- **A shipped config key, permission or public method that nothing reads is the defect class this
  module keeps producing.** Four instances so far: `health.auto_repair` (default TRUE, a form
  checkbox writing it, nothing reading it), `CircuitBreaker` (231 lines, a full unit suite, no
  caller), `create strata snapshot` (a permission with no route), and the whole `src/Budget/`
  subsystem (two classes, two unit suites, never constructed). **A unit test that instantiates a
  class proves the class works and says nothing about whether anything reaches it.** Two structural
  checks now close the two halves that can be decided from source: `everyDeclaredPermissionIsChecked()`
  and `everyEventHasAProducer()` in `ExtensionTest`. The half neither can decide - a config key read
  by nothing - is covered per-key by asserting the built object carries the configured value, which is
  what `BudgetTest` does for the ceilings and the circuit.
- **`budget.action` is a ceiling on the escalation ladder, not a separate switch.**
  `EscalationLadder::rungFor()` reads the measured overspend alone, so without
  `EscalationLadder::cap()` a site that chose Warn Only had its capture stopped at 1.5x - the opposite
  of what it asked for. The measurement is never changed by the cap; `BudgetAssessment::withRung()`
  moves the rung and leaves the fraction alone.
- **The budget stage is skipped outright when no ceiling is set**, which is the shipped state. Pricing
  a site against no ceiling costs a read of the stat table and the frame index every cron run for an
  answer that cannot change anything.
- **`ProviderStats::fromOperations()` is the inverse of `byOperation()` and exists because replaying
  `record()` is O(requests).** A month of traffic is millions of calls to reproduce three totals. It
  does not reproduce latency samples, because the stat table does not hold them, so `p95()` on a
  rebuilt window reports nothing while the counts, volumes and failures are exact.
- **A notifier call belongs on the private method every entry point funnels through, never on one of
  them.** `LogicalRestore` has three public ways in - `restore()`, `restoreAll()` and `apply()` - and
  announcing from `apply()` alone would have left two of them silent. `RestoreTest` drives all three
  and counts the announcements.
- **A `#key` a theme hook does not declare in `variables` is dropped in silence.** The template's
  variable is simply undefined, so a section guarded by it never renders and nothing anywhere says
  why. The setup checklist shipped invisible for exactly this reason, and no assertion on a page's
  markup could have found it: the page renders perfectly well without the section. `UiTest`'s
  `everyThemeVariableIsDeclared()` compares a controller's build against `Theme::hooks()` instead.
- **`create strata snapshot` shipped in 1.0.0 with no route, form or command asking for it**, the same
  shape as `health.auto_repair`. `strata_ui.flush` is what now uses it, and it is also the answer to a
  setup checklist whose last step linked nowhere: a site with no working cron and no shell had no way
  to seal its first window, and no report shows anything until one commit exists.
- **A finding's severity picks its repair rung, so severity is now a cost decision.**
  `RepairLadder::initialRung()` sends anything at or above `ERROR` to an automatic pass, and since
  1.0.3 those passes actually run on cron. `capture.tap_disabled` is `WARN` for that reason: nothing
  unattended repairs a statement tap, and an `ERROR` would spend a bucket-wide reindex on it.
- **`RepairPass` clears a code's findings before running its pass and puts them back if it raises.**
  `Verifier::verify()` re-records what is still true on its way through and `Reindexer::reindex()`
  does not, so clearing afterwards deletes a symptom the pass just re-confirmed - and clearing
  before, with no restore on failure, makes a broken store look healthy. Both directions have a test.
- **A PipeCodec is built on the one convention `zstd` and `brotli` share**: given several files each
  writes beside its input, adding the suffix when compressing and stripping it when decompressing. So
  a batch is laid out as `<n>` and `<n><suffix>` and read back by name, and neither
  `--output-dir-flat` nor `-o` is needed. They disagree on exactly one flag: `-q` is quiet for zstd
  and quality for brotli.
- **`drupal:key` in an info file resolves the same as `key:key`, which is why it was wrong for two
  releases without breaking anything.** `Dependency::createFromString()` discards the project part.
  What it did break is `ExtensionTest::theRootModuleRequiresWhatItDependsOn()`, which skips a
  dependency whose project is `drupal` - so the test written to catch a missing `drupal/key` in
  composer could not see `key` at all.

## Error Handling Invariants

- **`StatementCaptureSubscriber::onStatement()` must never throw.** It runs inside
  `Connection::execute()` on every write the site performs, so an exception there is not a failed
  capture - it is a failed query, and with it a failed request, on every write. It is the one place
  where the module's own rule ("a backup never takes a save down with it") has the widest blast
  radius, and it was the one place not holding to it until 1.0.2. The catch disables the tap before
  logging, because logging issues a query and a repeating failure would otherwise recurse through its
  own error handler.
- **`Cron::processQueues()` catches `\Exception`, not `\Throwable`.** An `Error` raised inside
  `processItem()` therefore does not fail that item - it aborts the whole cron run and skips every
  module whose cron had not gone yet, for as long as the lock is held. A queue worker here catches
  `Throwable` around its work and rethrows nothing but `RequeueException`.
- **Guzzle follows five redirects by default, and a webhook must follow none.** A subscribed endpoint
  answering 302 - or whoever controls the DNS for it - would otherwise choose where a signed payload
  describing this site's history is posted next, and the operator who approved the subscription never
  saw that address. `allow_redirects => false` on every delivery.
- **A toolbar item renders on every admin page, so its whole body is inside the guard.** 1.0.2 moved
  the label inside and left `Toolbar::item()` itself outside, where `Url::fromRoute()` raises on a
  route the router no longer knows. `Hook\Toolbar::build()` is the guarded body; `item()` is the
  try/catch around it. `StrataBlockBase::build()` had the same shape - its detail URL was built
  outside the try that already wrapped the rows.
- **`StatusController::page()` is guarded as a whole, on top of its sections.** It is the landing
  page for the report section and the first thing a fresh install opens, so it is the last page that
  may white-screen. `requirements()` and `links()` were the two unguarded halves.
- **`strata_requirements()` renders `/admin/reports/status` for the whole site, so one row raising
  is every other module's row gone.** The storage row is the only one that touches the network and
  the only one built from submodule code; it has its own try/catch, and its Error branch names what
  could not be checked rather than disappearing.
- **`ArchiveImporter` verifies only content-addressed keys.** `frames/`, `packs/`, `commits/` and
  `bases/` are checked against the digest in their own key; `refs/`, `segments/` and `dictionaries/`
  are written as given, and the exporter includes `refs/heads/main`. So importing an archive moves
  this site's trunk to whatever the archive says. **What gates it is `--apply` being off by default
  and the archive path being reachable from Drush alone.** It carried a `restrict access` permission
  until 1.0.3 and that permission gated nothing - no route, form or command read it - so it was
  removed rather than left describing an authorisation the module does not perform. Drush is trusted
  here the way it is everywhere else in this module: it runs as uid 0 unless `--user` is passed, and
  no command checks a permission. Anything that puts an import behind a browser has to bring the
  permission back with it and revisit this whole entry. Path traversal is closed separately:
  `ObjectKeys::resolve()` and `LocalStorage` both refuse `..`, `.`, an empty segment and a null byte
  by name.

## Core Behaviour Worth Knowing

- **A readonly promoted property is incompatible with `DependencySerializationTrait` when a parent
  brings the trait in and PHP is below 8.4.** `__wakeup()` cannot initialize a readonly property
  declared in a child class, so a plugin extending `PluginBase` - every block and every queue worker
  here - has to `use DependencySerializationTrait` itself, putting `__wakeup()` in the scope that
  declares the property. Only visible below 8.4, which is why `phpstan.neon` pins `phpVersion.min`.
- **MySQL refuses an invalid UTF-8 sequence in a utf8mb4 varchar outright.** A fixture proving what
  happens to bytes JSON cannot describe has to put them in a `blob`; SQLite accepts them anywhere and
  hides the constraint. A blob also cannot carry `'default' => ''` on MySQL.
- **`use SomeGlobalClass;` in a file with no namespace declaration is a PHP warning, not a style
  choice.** `tests/shard.php` and `tests/drupal-root.php` therefore reference `Throwable` and
  `RuntimeException` bare. This is the one real structural exception to the always-import rule.
- **Never take an engine-built service as a constructor argument.** Anything defined with
  `factory: ['@strata.engine', ...]` assembles the store, and assembling it refuses on a site that
  has not chosen a key - encryption is on by default. Whoever builds the object then wears the
  refusal, and none of them handle it: Drupal resolves a `#[Hook]` class inside
  `ModuleHandler::invokeAllWith()`, which for cron runs **before** the per-module try/catch in
  `Cron::invokeCronHandlers()`, so a throw skipped every module's cron and left the cron lock held
  for its full 900 seconds. Drush answers a constructor that throws by dropping every command on the
  class with a debug-level line nobody reads. Take `@strata.engine` and resolve inside a guarded
  method; `DrushCommandTest::commandClassAsksForNothingAssembled()` derives the forbidden set from
  the service file and fails the build. A service taking only `@database` is cheap and fine.
- **A kernel test cannot prove this by constructing the class.** The container caches a service it
  has already built, and `Engine::reset()` clears the engine's memo rather than the container's, so
  the assertion has to be structural.
- **`json_encode()` returns FALSE for the whole document when any string in it is not valid UTF-8**,
  and `(string) false` is `''`. That sealed an empty manifest under `Hash::of('')` and reported
  success. `JournalOp` refuses such a string on construction so the loss is one operation; use
  `Hash::ofData()` for a digest that is only ever compared. Check strings joined with an ASCII
  separator, never concatenated bare: a subject ending in a truncated `"\xC3"` and a label opening
  with `"\xA9"` splice into a valid sequence and both halves pass.
- **The `database` service cannot be decorated.** It is a factory returning a driver subclass chosen
  in `settings.php`. Statement events are the only driver-agnostic write tap.
- **Do not wrap the `state` key-value collection.** Core's `State` asks the key-value factory for a
  collection called `state`, so wrapping it records every state write twice - once as `state`, again
  as `keyvalue` under `state:<key>`. Invisible in the kernel lane, where `keyvalue` is synthetic.
- **`StatementExecutionEndEvent` only fires when the Start event is also enabled**, and the Start
  event's constructor calls `findCallerFromDebugBacktrace()` on every statement - 2.99 us at a stack
  depth of 100.
- **`Config::isNew()` is unusable in a save subscriber.** `Config::save()` clears the flag on the line
  before it dispatches and copies `originalData` on the line after. A create is identified by
  `getOriginal('', FALSE) === []`.
- **`state` carries `needs_destruction` and `State extends CacheCollector`**, so a decorator must
  implement `DestructableInterface` and delegate or state silently stops persisting.
- **`keyvalue` is made synthetic by `KernelTestBase`** and cannot be decorated, so that wiring lives
  in `StrataServiceProvider::alter()` guarded on `isSynthetic()`. Key-value capture does not work in
  the kernel lane; use the state or table realm there instead.
- **`hook_uninstall()` runs while the tables still exist**, before `uninstallSchema()`. Nothing in
  core touches remote storage, so an uninstall destroys the local index and never the bucket.
- **`Schema::findPrimaryKeyColumns()` is protected**, so the reconciler's row identity is a heuristic
  verified by `COUNT(*) = COUNT(DISTINCT col)` only on tables that actually drifted.
- **`ViewsField` and `ViewsFilter` are not repeatable attributes**, so each plugin id needs its own
  class. An abstract base plus one leaf each.
- **Drupal 11 has no `user_name` views field.** An actor column is `numeric` plus a relationship to
  `users_field_data`.
- **`#[Hook('views_data')]` is collected.** No `strata.views.inc` is needed, and a test asserts the
  file does not exist.
- **`core/optional` config cannot be installed in a kernel test** through the optional installer,
  because core's own optional config drags in `filter`. `ViewsDataTest` imports the view YAML
  directly.
- **A form's second parameter must be named `$form_state`, not `$formState`.** This is the one place
  house style loses to a technical boundary. `FormController::getContentResult()` sets request
  attributes called `form` and `form_state` and then resolves `buildForm()`'s arguments **by name**
  through Symfony's `ArgumentResolver`, so a parameter called anything else resolves to nothing and
  every `_form` route answers 500. `FormBuilder::retrieveForm()` passes positionally, which is why
  the kernel lane never sees it: a form driven through `getForm()` works and the same form over HTTP
  does not. The rest of the file stays camelCase.
- **`FormBuilder::submitForm()` takes its first argument by reference**, so a class name has to be a
  variable rather than a literal.
- **`DrushCommands::REQ` is `InputOption::VALUE_REQUIRED`, the integer 2, not NULL.** An
  `$options['x'] === null` check is therefore false when a method runs with its own declared defaults.
- **Never pass `Schema::changeField()` a primary key that is not changing.** MySQL emits
  `ADD PRIMARY KEY` alongside the `CHANGE` and refuses the whole statement with "Multiple primary key
  defined"; SQLite and PostgreSQL accept it. The key stays on the column through a `CHANGE`, so the
  fifth argument is for a key that actually moves. This broke `strata_update_11103()` on MySQL only.
- **`is_dir()` on a stream wrapper PHP has not registered raises a warning instead of answering
  FALSE**, and the shipped `local_path` default is `private://strata`, which a site with no private
  file system does not have. Guard with `in_array($scheme, stream_get_wrappers(), true)` and **not**
  with `StreamWrapperManager::isValidScheme()`: that knows only Drupal's wrappers and rejects genuine
  PHP ones, including the `vfs://` URI a kernel test's `siteDirectory` is.
- **An update hook needs a test, and that test runs on all three drivers.** Nothing executed
  `strata_update_11101` or `11102` before 2026-08-22 and they still have no coverage; `11103` has
  four tests because it alters a primary key, which is the most driver-sensitive thing here.
- **`StrataServiceProvider::alter()` is the only thing that wires key-value capture onto a site, and
  the kernel lane structurally cannot reach it** - `KernelTestBase` makes `keyvalue` synthetic and the
  method returns early. It is unit-tested over a real `ContainerBuilder` instead, which is what caught
  it wrapping the decorator in itself on a second pass and journaling every write twice.
- **`StorageProviderManager` is a registry, not a policy.** Which provider a flush goes to is
  `strata.settings`'s `provider` key, resolved by `Engine::buildProvider()`; the manager never knew.
  It once carried `activate()` and `active()` modelling an active provider it did not own, and both
  were dead. Registration also follows the enabled submodules rather than the configuration, so
  anything sweeping `ids()` reports an unconfigured submodule as broken - which is why
  `reachability()` takes one id.
- **`local` and `null` are resolved by `Engine::buildProvider()` and are not registered with the
  manager.** Anything asking the manager about the configured provider has to handle those two
  itself, or a site on local storage - the shipped default - is told its provider does not exist.

## Translatable Strings

`Drupal.Semantics.FunctionT.Concat` forbids concatenating inside `t()`, and that sniff protects
something real: Drupal's translation extractor cannot read a concatenated argument, so the string
would ship untranslatable. **Do not disable it.** A `t()` argument is one string literal on one line.
If it does not fit, the text is too long for a form description - shorten it, and put the longer
explanation in `hook_help`, which returns a render array of short sentences for the same reason.

## Adding Something

- **A new realm**: a case on `Realm`, a capture source, a `live()` arm in `DrillRunner` if it can be
  read back, a `write*()` arm in `LogicalRestore` if it can be restored, a `views_data` entry, a
  checkbox on `CaptureSettingsForm`, and a `Realm::isRestorable()` decision.
- **A new tripwire**: implement `TripwireInterface`, register it in the relevant
  `TripwireRegistry::with*()` factory, and give the code a rung. A tripwire asserts a symptom, is O(1)
  or explicitly bounded, and never repairs.
- **A new event**: a constant on `StrataEvents`, an event class extending `StrataEvent`, a method on
  `Notifier`, and a gate in `NotificationPolicy::GATES`. A unit test asserts every constant on
  `StrataEvents` appears in `all()`, so an unregistered one fails the build.
- **A new report page**: a route with `_permission` and `_admin_route`, a controller extending
  `StrataControllerBase`, and the page built through `guard()` so an unassembled engine explains
  itself instead of white-screening. Add a theme hook and a template; a test asserts every hook has
  one.
- **A new Drush command**: a method on one of the four command classes in `src/Drush/Commands/` with
  at least one `#[CLI\Usage]`. A test asserts every command name is declared exactly once and carries
  a usage example, and the count in `DrushCommandTest`, `README.md` and the table above all move
  together.
- **A setup step**: an entry in `StatusController::setup()` that links somewhere the reader can act,
  and the matching sentence in `Hook\Help`'s `help.page.strata` list. A step whose `url` is empty
  renders as plain text, which is the right degradation for an account that may not follow it and the
  wrong one for a step nobody can complete - the last step was the second of those until 1.0.3.

## Git

**Never run a write or state-changing git command unless it is asked for in that same message.** That
covers `commit`, `push`, `add`, `checkout`, `switch`, `branch`, `stash`, `rebase`, `merge`, `reset`,
`revert`, `cherry-pick`, `tag`, `restore`, `clean` and `rm`. Finished work stays as uncommitted
changes on the current branch. Read-only git is always fine.

Commit subjects are lowercase and imperative with no trailing period, prefixed as this repository's
own history does: `feat:`, `fix:`, `chore:`, `feat(testing):`, `chore(deps):`. Small commits grouped
by concern, with source and the test that proves it together.

`composer.lock` stays uncommitted; this is a library.
