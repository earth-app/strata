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
./vendor/bin/phpunit --testsuite Kernel
```

The default 128M memory limit makes PHPStan OOM and report a fake "2 errors"; that is not a pass. The
Kernel suite takes roughly ten minutes and skips seven tests when Docker is down - those are the MinIO
lane and the skip is expected.

`bun run test:kernel --filter <ClassName>` while iterating.

## Tests

Three lanes with different budgets:

| Lane       | Directory              | Budget                                              |
| ---------- | ---------------------- | --------------------------------------------------- |
| Unit       | `tests/src/Unit`       | Deterministic, offline, free, fast                  |
| Kernel     | `tests/src/Kernel`     | A booted Drupal on in-memory SQLite, `LocalStorage` |
| Functional | `tests/src/Functional` | A browser and a live backend                        |

Every test carries `#[Test]`, `#[TestDox('a lowercase sentence describing the behaviour')]` and
`#[Group('strata/<area>')]`. Method names are bare camelCase with no `test` prefix. Test methods get
no docblock - the TestDox is the description. Test classes get a real class docblock saying what the
lane proves and why.

**One spec file per domain.** Never create `*-expansion`, `*-additional` or `*-part2` alongside an
existing spec; extend the existing one.

Two things this suite does deliberately and should keep doing:

- **Drive every report page on an empty store as well as a populated one.** A fresh install is the
  first thing a user sees, and code that divides by a total or indexes the newest commit works on a
  busy site and fatals on an empty one. That is how the uncaught refusal on the storage explorer was
  found: encryption is on by default with no key until somebody chooses one.
- **Assert the claim a docblock makes, not the code that implements it.** Several docblocks here have
  turned out to be wrong, and in every case a test found it.

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
| `src/Drush/Commands/`                                     | 27 commands, thin over `Engine`                                     |
| `modules/strata_ui/`                                      | Controllers, forms, blocks, toolbar, templates, CSS, one JS file    |

`Engine` is the only place that reads settings and decides which parts a site gets. Nothing is built
until asked for and each piece is built once, so a request that captures but never flushes never
constructs a provider or a cipher. **Call `Engine::reset()` after a settings change** - the settings
forms already do.

## Decisions That Look Wrong Until Explained

Do not "fix" these without measuring first.

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

## Core Behaviour Worth Knowing

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
  a usage example.

## Git

**Never run a write or state-changing git command unless it is asked for in that same message.** That
covers `commit`, `push`, `add`, `checkout`, `switch`, `branch`, `stash`, `rebase`, `merge`, `reset`,
`revert`, `cherry-pick`, `tag`, `restore`, `clean` and `rm`. Finished work stays as uncommitted
changes on the current branch. Read-only git is always fine.

Commit subjects are lowercase and imperative with no trailing period, prefixed as this repository's
own history does: `feat:`, `fix:`, `chore:`, `feat(testing):`, `chore(deps):`. Small commits grouped
by concern, with source and the test that proves it together.

`composer.lock` stays uncommitted; this is a library.
