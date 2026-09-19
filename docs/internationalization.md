# Internationalization

Menu Autopilot writes ordinary `menu_link_content` entities. Language behaviour
is core's, not a separate pipeline.

- **Title** uses the standard translatable `title` field. Sync sets it from the
  node's label, or from the child-label token pattern replaced in the node's
  language. The module does not create translations.
- **URI** is always `entity:node/<id>`. Core resolves that to the
  language-appropriate alias when the menu is built. Per-language URLs are not
  stored.
- **Bookkeeping** (`menu_autopilot` map, `menu_autopilot_dynamic` marker) is not
  translatable. Structure is shared; the visible title can vary per language
  once `content_translation` is enabled for `menu_link_content`.

- **Enabled state.** The check for a child that a sync save left disabled reads
  the link's default translation, the only one the module writes. A
  translation an editor disabled is not touched.

Locale routing, a language switcher, and translated content belong to the site.
