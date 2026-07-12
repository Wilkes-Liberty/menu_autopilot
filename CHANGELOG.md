# Changelog

All notable changes to Menu Autopilot are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
