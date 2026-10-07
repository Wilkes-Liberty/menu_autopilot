# Internationalization

Menu Autopilot writes ordinary `menu_link_content` entities. Language behaviour
is core's, not a separate pipeline.

- **Title.** When content translation is enabled for menu links, sync writes
  the `title` field in every language the source node has. A new link is
  created in the node's default language. When menu links are not
  translatable, a new link keeps the language Drupal assigns and only that
  title is stored. A link that already exists keeps its language: its title
  comes from the node translation in that language,
  or from the node's default translation when the node has none. Each other
  node language is created or updated on the link. A link translation in a
  language the node no longer has is removed on the next sync. The title is
  the node's label, or the child-label token pattern replaced for that
  translation.
- **URI** is always `entity:node/<id>`, on the shared link field. Core
  resolves that to the language-appropriate alias when the menu is built.
  Per-language URLs are not stored.
- **Bookkeeping** (`menu_autopilot` map, `menu_autopilot_dynamic` marker) is
  not translatable. Structure is shared.

- **Enabled state.** `enabled` is not translatable. The check for a child that
  a sync save left disabled reads the link's default translation. A link an
  editor disabled stays disabled. Sync still updates translated titles on
  that link.

Locale routing, a language switcher, and translated content belong to the site.
The module does not translate the node. It copies labels from translations
the node already has.
