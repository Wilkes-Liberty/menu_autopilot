# Menu Autopilot

**Automatic, content-driven navigation for Drupal.** Curate your top-level menu the way you
always have — then let each item's children build and maintain themselves from your published
content. Publish a new Platform? It appears under **Platforms** automatically. Unpublish it?
It disappears. No more hand-editing menus to mirror your content.

Menu Autopilot is to navigation what [Pathauto](https://www.drupal.org/project/pathauto) is to
URL aliases: the automatic, config-driven default.

## Why

Drupal's menus are hand-curated. On a content-heavy or **decoupled** site that means editors
constantly re-doing the nav to match content — and in a headless setup a hand-placed link is
easy to point at the wrong URL (an editorial `/node/N/latest` route that 404s the frontend).
The existing "taxonomy menu" modules generate menu items from *terms*; none generate items from
*content nodes* under a curated parent with clean, headless-ready URLs. Menu Autopilot fills
that gap.

## What it does

- **Hybrid menus.** You curate the top level; each curated item can auto-populate its children
  from published content.
- **Editor picks the source, per item.** A taxonomy term (everything of a given type), a
  content bundle, or a hand-picked ordered list of nodes.
- **Always in sync.** Children are created, updated, re-ordered, re-parented, and removed
  automatically as content is published, unpublished, re-typed, or deleted.
- **Headless-clean URLs.** Generated links use **canonical entity references**, so they resolve
  to your real path alias (Pathauto or manual) — never `/node/N` or an editorial route.
- **Custom labels via tokens.** By default a child is labelled with its node's title; supply a
  token pattern (e.g. `[node:field_nav_title]`) to give nav a shorter or decorated label without
  touching the page title. URIs are never tokenized, so the clean-URL guarantee always holds.
- **Multilingual.** Synced links are translatable and language-aware.
- **Decoupled-ready.** It produces real `menu_link_content` links, so the composed tree is
  exposed by *any* menu consumer — GraphQL (GraphQL Compose), JSON:API, or a traditional theme
  — with no extra work, and it fans cache-tag revalidation to your decoupled frontend(s).
- **Accessible by design.** Ships with the recommended fully keyboard-navigable, WCAG 2.1 AA
  menubar pattern for the consuming frontend.

## How it works

Menu Autopilot adds an **"Automatic children"** section to the menu-link edit form. Choose a
source and the module keeps a set of managed child links under that parent in sync with the
matching published content. Managed links are ordinary `menu_link_content` entities (so every
menu consumer sees them), flagged and reconciled by the module. Nothing is virtual, so there is
no schema to teach your GraphQL/JSON:API layer.

## Quick start

1. `composer require 'drupal/menu_autopilot:^1.0.0-rc1'` and enable the module.
2. Edit a top-level menu link (e.g. **Platforms**) → **Automatic children** → pick a source
   (e.g. *Taxonomy term* → your "Platform" term + the node field that references it).
3. Publish content — it appears under that item. Run `drush menu-autopilot:rebuild` any time to
   reconcile everything from scratch.

## Requirements

- Drupal 10.6+ / 11.3+
- Core `menu_link_content` and `node`
- Suggested: [Token](https://www.drupal.org/project/token) — adds the token browser UI and extra
  tokens for the child-label pattern (core's token service handles `[node:*]` without it).

## Accessibility & internationalization

Accessibility and multilingual support are first-class goals, not afterthoughts — see `docs/`
for the recommended accessible menubar pattern and the translation workflow.

## Source & releases

Development happens on [GitHub](https://github.com/Wilkes-Liberty/menu_autopilot) (issues and
pull requests welcome there); the repository is mirrored to
[git.drupalcode.org](https://git.drupalcode.org/project/menu_autopilot), and tagged releases are
published on [drupal.org](https://www.drupal.org/project/menu_autopilot) and installable with
Composer.

## Maintainers

Built by Jeremy Michael Cerda and [Wilkes & Liberty, LLC](https://wilkesliberty.com).
