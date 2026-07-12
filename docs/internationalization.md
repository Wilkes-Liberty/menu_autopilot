# Internationalization

Menu Autopilot is designed to be language-aware from day one, so a multilingual site
gets a correctly translated, correctly aliased navigation tree with no extra work — and
a monolingual site pays nothing for that readiness.

## How it works

The generated children are ordinary `menu_link_content` entities, which are
**translatable** core content entities. Two properties make them language-correct:

1. **Titles** are stored on the standard translatable `title` field, so each translation
   can carry its own label.
2. **URIs** are canonical `entity:node/<id>` references. When the menu is resolved for a
   given language, the reference resolves to *that language's* path alias automatically —
   you never store a per-language URL.

Because the module stores its own bookkeeping in a single non-translatable `menu_autopilot`
map field (the structural source descriptor and the managed-child flags), the descriptor
is shared across translations while the human-visible title varies per language — which
is exactly what you want.

## Requesting a language

GraphQL Compose exposes the menu with an optional `langcode` argument:

```graphql
query {
  menu(name: MAIN, langcode: "es") {
    items { title url children { title url } }
  }
}
```

The returned titles and resolved alias URLs are the Spanish variants. Omitting `langcode`
returns the site default language.

## Front-end caching

Cache the resolved menu **per language**. In the reference Next.js front end the cache
key includes the langcode, and every language variant is tagged with the same `menu`
cache tag:

```
key:  ["menu", <name>, <langcode>]
tag:  "menu"
```

A single `menu` revalidation (fired by the CMS on any menu or alias change) busts every
language variant at once, so translations never drift from the source content.

## Enabling translation

1. Turn on `content_translation` for the `menu_link_content` entity type
   (**Configuration → Regional and language → Content language**).
2. Translate the generated links as you would any content — only the title needs a
   translation; the URI and structure are shared.
3. Resolve the menu with the visitor's `langcode` (see above).

## Scope

Menu Autopilot provides the language-aware **data layer**. The surrounding bilingual
rollout — locale routing, a language switcher, and translated content — is a separate
concern owned by the site, not the module. The module is built so that turning that on
requires no change to how navigation is composed or synced.
