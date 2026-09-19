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
- **Editor-controlled order.** Choose A→Z, date, or **Keep current order** so automatic
  children stay in the sequence you drag on the menu overview. New matches append at the
  end.
- **Headless-clean URLs.** Generated links use **canonical entity references**, so they resolve
  to your real path alias (Pathauto or manual) — never `/node/N` or an editorial route.
- **Custom labels via tokens.** By default a child is labelled with its node's title. On the
  parent’s **Menu Autopilot** section, **Child menu label** accepts a token pattern
  (e.g. `[node:title] [node:field_subtitle]`) so nav can include a subtitle or a shorter
  label without touching the page title. URIs are never tokenized, so the clean-URL
  guarantee always holds.
- **Multilingual.** Synced links are translatable and language-aware.
- **Decoupled-ready.** It produces real `menu_link_content` links, so the composed tree is
  exposed by *any* menu consumer — GraphQL (GraphQL Compose), JSON:API, or a traditional theme
  — with no extra work.

## How it works

Menu Autopilot adds a **"Menu Autopilot: children of …"** section to the menu-link edit form. Choose a
source and the module keeps a set of managed child links under that parent in sync with the
matching published content. Managed links are ordinary `menu_link_content` entities (so every
menu consumer sees them), flagged and reconciled by the module. Nothing is virtual, so there is
no schema to teach your GraphQL/JSON:API layer.

## Quick start

1. `composer require 'drupal/menu_autopilot:^1.0'` and enable the module.
2. Edit a top-level menu link (e.g. **Platforms**) → **Menu Autopilot: children of Platforms** → pick a source
   (e.g. *Taxonomy term* → your "Platform" term + the node field that references it). If the
   parent already has children, choose whether to reuse matching links (and whether
   to drop extras), add only the missing ones, or replace every child. Optionally
   move matching unmanaged links from elsewhere in the same menu under this parent.
   Set **Sort children by** to *Keep current order* if you want to drag the children
   yourself, and set **Child menu label** when the menu text should not be the node
   title alone.
3. Publish content — it appears under that item. Run `drush menu-autopilot:rebuild` any time to
   reconcile everything from scratch.

## Disabled automatic children

A sync creates its children enabled. `enabled` is the published key of
`menu_link_content`, so a module that governs publishing can force a link to
disabled during the save when the acting account may not publish. An API client
that updates a node is the usual case.

When one of the sync's own saves asks for an enabled child and storage holds a
disabled one, the module:

- logs a warning on the `menu_autopilot` channel that names the parent link, the
  node id and the acting account's uid;
- records `disabled_by_save` in the link's internal `menu_autopilot` map;
- enables the link on the next sync run by an account whose save keeps it
  enabled, and removes the flag. Each account tries once per request.

A link with no flag was disabled by an editor. A sync updates its title, weight
and URI and never enables it. Saving the link form with **Enabled** unchecked
removes the flag, so the link then stays disabled.

`drush menu-autopilot:rebuild` lists every automatic child that is still
disabled after the rebuild, with the reason. The parent link's **Menu
Autopilot** section shows the same list.

## Optional MCP tools

The module's two base fields are internal, so JSON:API and GraphQL leave them
out. An API client cannot tell an automatic child from a curated link, and an
edit to an automatic child is overwritten by the next sync. The optional
`menu_autopilot_mcp` submodule answers that before the client writes.

It needs [Tool API](https://www.drupal.org/project/tool) 1.0.0-beta8 or later
and [MCP Sentinel](https://www.drupal.org/project/mcp_sentinel) 2.22 or later.
Menu Autopilot itself depends on neither. Every tool needs MCP Sentinel
governance to be ready, the `access mcp sentinel context` permission and the
restricted `use menu autopilot mcp tools` permission. Every refusal is one fixed
message. Results are capped at 128 KiB, or the Sentinel profile's response cap
when that is lower.

| Tool | Kind | What it returns |
| --- | --- | --- |
| `menu_autopilot_status` | read | The managed menus. Each dynamic parent: title, UUID, menu, source type, existing-children policy, and counts of owned, adoptable, extra and disabled children. Disabled automatic children by title and node id, marked when a sync save, not an editor, disabled them. At most 50 parents and 25 listed children per parent; flags say when a list was cut. |
| `menu_autopilot_link_info` | read | For one `menu_link_content` UUID: `dynamic_parent`, `managed_child` (with the node id) or `plain`; whether it sits under a dynamic parent and that parent's policy; and `effect_of_client_edit`, a plain sentence such as "Title, weight and URI are overwritten on the next sync of its parent." |
| `menu_autopilot_normalize_uris` | write | Runs `menu-autopilot:normalize-uris` for one to ten named menus. Each name must be a managed menu. Returns each changed link id, its new `entity:node/<nid>` URI and `applied`. Lists at most 100 changes. Also needs the restricted `normalize menu link uris via mcp` permission. |

`applied` is read back from storage after the save. MCP Sentinel's default
profile denies publishing, and it turns a governed edit of a published menu link
into an unpublished pending revision. The live link keeps its old URI, and the
tool reports `applied: false` instead of a success that did not happen. To let
the tool write live links, set `entity_rules.menu_link_content.allow_publish` on
the Sentinel profile.

The tool works through the named menus one at a time. If another module refuses
a save with an exception, the tool stops and returns `completed: false` with
`failed_menu`. Links saved before that stay changed and are listed. The call is
safe to repeat.

The status counts say what is under each parent now. `adoptable` means an
unmanaged child that points at a node. The tool does not resolve the source, so
it does not predict what the next sync will do with it.

No tool returns the child label pattern or any node field value. A pattern can
name any node field, and its output is already the public link title. The old
URI of a normalised link is not returned either: it is whatever an editor typed
and can carry a query string.

These are deliberately not tools:

- **Setting or clearing a source descriptor.** Its validation lives in the link
  form. The `replace` policy deletes links. A label pattern can publish any
  node field into a public menu.
- **Changing the managed menus setting.** It decides which menus the module may
  write to at all.
- **Releasing managed flags.** It hands automatic children back to editors and
  is done by saving the parent with no source.
- **Rebuild or preview.** The sync has no plan-and-apply split, so a preview
  could not predict the write. Use `drush menu-autopilot:rebuild`.

## Requirements

- Drupal 10.6+ / 11.3+
- Core `menu_link_content` and `node`
- Suggested: [Token](https://www.drupal.org/project/token) — adds the token browser UI and extra
  tokens for the child-label pattern (core's token service handles `[node:*]` without it).

## Accessibility & internationalization

See `docs/accessibility.md` for the recommended frontend pattern (disclosure
navigation, not a menubar) and `docs/internationalization.md` for language
invariants. The module ships menu data, not frontend markup.

## Source & releases

Development happens on [GitHub](https://github.com/Wilkes-Liberty/menu_autopilot) (issues and
pull requests welcome there); the repository is mirrored to
[git.drupalcode.org](https://git.drupalcode.org/project/menu_autopilot), and tagged releases are
published on [drupal.org](https://www.drupal.org/project/menu_autopilot) and installable with
Composer.

## Maintainers

Built by [Jeremy Michael Cerda](https://www.drupal.org/u/jmcerda) and [Wilkes & Liberty, LLC](https://wilkesliberty.com).
