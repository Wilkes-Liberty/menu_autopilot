# Changelog

All notable changes to Menu Autopilot are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Keep current order** (`preserve`) on term and bundle sources. Automatic
  children are still added, removed, and retitled from the source, but existing
  weights are left alone so editors can drag the overview into a custom order.
  Newly matching children append after the current maximum weight. A→Z and
  date sorts still rewrite weights on every sync.

### Changed
- **Child menu label** is the new name for the token pattern field (was
  “Child link title”), with an example that includes a subtitle field:
  `[node:title] [node:field_subtitle]`.
- The menu overview for a managed menu now points editors at the parent’s
  *Sort children by* and *Child menu label* controls.
- Editing an automatic child explains that its label and order are owned
  by the parent, so a one-off rename on the child does not last.
- The node edit form no longer treats an automatic child as that node’s
  own “Provide a menu link” item. Label and order stay on the parent.

### Fixed
- **Archiving or unpublishing a node from its edit form no longer fatals
  in menu_ui.** Autopilot deleted the managed child in `hook_entity_update`
  before menu_ui’s submit handler ran, so `getActive()` returned NULL and
  `menuUiNodeSave()` called `isTranslatable()` on it. Node-form sync is
  deferred until after that submit. (#29)
- **Keep current order is ignored for a hand-picked list.** Manual sources
  follow the node list even if a leftover or site-default `preserve` value
  is stored on the parent.

## [1.1.0] — 2026-08-14

### Added
- **Existing children policies** on the menu-link form, shown when a
  source is chosen. The operator picks what happens to hand-created
  children already under the parent (or elsewhere in the menu):

  - **Reuse matching links** (default): adopt hand-created links that
    already point at a source node; leave curated extras alone.
  - **Reuse matching links, remove extras:** same adoption, but delete
    unmanaged children that are not in the source.
  - **Add missing children only:** generate links only for source nodes
    that have no child yet; do not change existing hand-created links.
  - **Replace all children:** delete unmanaged children, then build the
    managed set from scratch.

  Choosing a policy shows a short description of what it will do.
- **Move matching links from elsewhere in this menu.** An optional
  checkbox reparents unmanaged matches that are not already under
  another automatic parent.
- **CI: No AI attribution gate.** Pull requests fail when commits, the
  PR title, or the PR body credit AI with authorship (shared Wilkes & Liberty
  drop-in). Covers server-side paths that local hooks cannot see.

### Changed
- **CI: the attribution check is now the shared workflow.**
  `.github/workflows/attribution.yml` becomes a thin caller pinned to
  `Wilkes-Liberty/shared-ci@v1`, and the vendored `.github/scripts/` copies are
  removed. One implementation for every repository makes copy drift structurally
  impossible instead of merely detectable. The trust property is unchanged:
  the scripts that judge a pull request are fetched from the shared repository
  at the exact commit the pin resolved to, so a pull request still cannot
  supply the code that decides whether it passes.

### Fixed
- **Enabling automatic children no longer duplicates existing child links.**
  Matching is by node URI (`entity:node/N`, `internal:/node/N`, editorial
  routes) and by path alias, so an `internal:/platforms/helios` child is
  treated as the same destination as `entity:node/N`. A later reconcile
  also removes unmanaged twins of a node the module already manages.
  Previously the reconcile only saw links it already owned, so it created
  a second copy of every matching node.
- **CI: the attribution gate no longer fails on clean commits.** The stripper
  compared each commit message against a copy that had gained a trailing newline,
  so every commit looked modified and the run ended with `strip count > 0 but tip
  unchanged`.


## [1.0.2] — 2026-07-30

### Changed
- **`composer.json` now declares `"php": ">=8.1"`.** It previously specified no PHP
  constraint at all, so the effective floor came only from whatever core happened to
  require — the supported surface was implied rather than stated, and a reader had
  to trace Drupal's own requirements to find it.

  8.1 is the real floor, checked rather than assumed: PHPCompatibility reports the
  codebase clean from 8.1 upward, Drupal 10.6 requires `>=8.1.0`, and this module
  takes no runtime dependency beyond core. It is also already verified — the
  drupal.org previous-major lane runs this suite on PHP 8.1.34 and passes.

  This does not change which sites can install today: `^10.6 || ^11.3` already implies
  the same floor. What it changes is that the claim is stated where Composer and a
  human both read it, and it stops moving silently if core's floor moves or this
  module adopts newer syntax.

### Added
- **A GitHub Actions test workflow.** GitHub ran no tests for this module at all — only
  changelog, composer-audit and dependabot-automerge — so the drupalcode pipeline was the
  only venue that could see a regression, and a PR here could go green, merge and ship
  while the suite was broken.

  `tests.yml` runs PHPUnit across the declared support range (`~10.6.0` on PHP 8.3 with
  PHPUnit 9.6, `~11.3.0` on PHP 8.3, `^11` on PHP 8.4), plus phpcs and phpstan against the
  module's own `phpcs.xml.dist` and `phpstan.neon.dist`, so the two venues check the same
  rulesets rather than two similar ones.

  Three things it deliberately does, each because the alternative has misled us before. It
  asserts the *resolved* core version matches the leg's name, because `^11.3` resolves to
  11.4 and a floor leg built that way silently retests the ceiling. It asserts a minimum
  test count rather than trusting the exit status, because an exit code says the tests that
  ran passed and cannot say which ran. And it discovers test directories rather than listing
  them, so a future submodule is covered without anyone remembering this file.

## [1.0.1] — 2026-07-23

### Fixed
- The settings form no longer declares its injected services as `readonly`. On PHP < 8.4 —
  within the supported range, since Drupal 10.6 runs on PHP 8.1+ — a `readonly` property on a
  form object is fatal when Drupal rebuilds the form from its cache
  (`DependencySerializationTrait::__wakeup()` cannot reinitialize it).

### Changed
- Added complete type declarations across the module (hook return and parameter types, entity
  type narrowing in `NavSyncManager`) so it passes PHPStan level 6 with no errors.

## [1.0.0] — 2026-07-23

First stable release.

### Changed
- Narrowed `core_version_requirement` to the oldest supported Drupal branches
  (`^10.6 || ^11.3`). The previous `^10.3 || ^11` claimed support for minor versions that
  are end-of-life upstream and were never exercised by CI. The floor now tracks the oldest
  branch still receiving upstream support.

### CI
- Added test legs for the supported floor: the suite runs against the previous major
  (10.6) and previous minor (11.3), so the version claim is verified rather than asserted.

### Documentation
- Install command uses the stable `^1.0` constraint now that 1.0.0 is released.

## [1.0.0-rc1] — 2026-07-21

### Added
- Initial release: hybrid navigation for Drupal — curated top-level menu links whose
  children are generated from published content and kept in sync automatically.
- **Sources, per parent link:** a taxonomy term (nodes whose reference field points at
  it), a content type, or a hand-picked ordered list of nodes.
- **Automatic sync** on content publish, update, unpublish, re-type, and delete:
  managed child links are created, re-titled, re-ordered, and pruned via a
  diff-before-write reconcile that is idempotent and re-entrancy-guarded.
- **Canonical URLs:** generated links always store an `entity:node/<id>` URI, so they
  resolve to the node's real path alias — never `/node/N` or an editorial route. This
  is the module's core invariant and is the reason it is headless-safe.
- **Token labels:** an optional per-source `title_pattern` (e.g. `[node:field_nav_title]`)
  lets editors give nav a shorter or decorated label without touching the page title.
  URIs are never tokenized.
- **Editor UI:** an "Automatic children" section on the menu-link form for choosing the
  source, sort, limit, and title pattern. The term reference field is chosen from a
  select of discovered taxonomy-reference fields (no machine-name typing); server-side
  validation reports missing required values as inline, accessible errors; and the
  section links to the settings page and warns when the link's menu is not managed.
- **Settings form** at Structure → Menu Autopilot (permission: *Administer Menu
  Autopilot*) to choose which menus are managed and set the default sort and limit.
  Saving reconciles immediately so newly managed menus take effect at once.
- **Drush:** `drush menu-autopilot:rebuild` (alias `ma:rebuild`) reconciles all dynamic
  parents on demand — safe to run repeatedly.
- **URI normalizer:** `NavSyncManager::normalizeNodeUris()` and
  `drush menu-autopilot:normalize-uris` (alias `ma:fix-uris`) rewrite hand-made menu
  links that target an editorial node route (e.g. `/node/12/latest`, `internal:/node/12`)
  to a canonical `entity:node/<id>` URI, so they resolve to the node's real alias instead
  of 404-ing a decoupled front end. A one-time cleanup when adopting the module; idempotent.
- Multilingual-ready storage (a single internal `menu_autopilot` map base field on
  `menu_link_content`; per-language titles use the standard translatable title field).
- Kernel test coverage for publish→child, unpublish→removal, idempotent reconcile, and
  token titles.

### Changed
- **Development branch is now `1.x`, not `1.0.x`.** A `1.x` branch ships every 1.y release
  from one line; `1.0.x` is patch-only for the 1.0 series. Track dev with
  `composer require 'drupal/menu_autopilot:1.x-dev'`. This standardizes the branch model
  across the W&L drupal.org modules.

### Fixed
- **Base field now installs when the module is enabled via configuration import.**
  `hook_install()` previously bailed out when `$is_syncing` was TRUE, so enabling the
  module through `drush config:import` or `site install --existing-config` skipped
  installing the `menu_autopilot` map field storage — leaving the field defined but with
  no database column, which errored at runtime. The base field is code-defined (not
  exported config), so its storage must be installed on every enable path; the guard is
  removed. The install remains idempotent.
