# Accessibility

Menu Autopilot composes the whole navigation tree on the Drupal side and exposes it as
ordinary `menu_link_content` links, so a consumer renders **one** menu with no special
knowledge of how it was assembled. That backend-owned composition is what makes an
accessible front end straightforward: there is a single, coherent tree to render, and
the accessible behaviour lives entirely in the presentation layer.

This document describes the **recommended accessible pattern** for rendering the menu in
a decoupled (or traditional) front end. It is guidance, not a runtime dependency — the
module ships the data; you own the markup.

## Use the Disclosure Navigation pattern, not `menubar`

For site navigation with submenus, use the WAI-ARIA APG
[Disclosure Navigation Menu](https://www.w3.org/WAI/ARIA/apg/patterns/disclosure/examples/disclosure-navigation/)
pattern:

- A real `<nav aria-label="…">` containing a list of links.
- A top-level item **with children** is a `<button aria-expanded aria-controls="…">`
  that discloses a submenu `<ul>`.
- A top-level item **without children** is a plain link.
- The current page's link carries `aria-current="page"`.

**Do not** use `role="menubar"` / `role="menuitem"` for site navigation. The menubar
role models an *application* menu bar: it takes over the arrow keys, removes items from
the normal tab order, and makes <kbd>Tab</kbd> jump past the whole navigation —
conventions users do not expect on a website. The APG and accessibility practitioners
advise against it for site nav, and declaring the role without implementing its full
keyboard model is worse than plain links, because assistive technology announces
behaviour that is not there.

## Keyboard model

| Key | On a disclosure button | Inside an open submenu |
| --- | --- | --- |
| <kbd>Tab</kbd> | Move to the next control (normal order) | Move out; the submenu closes |
| <kbd>Enter</kbd> / <kbd>Space</kbd> | Toggle the submenu | Activate the focused link |
| <kbd>Arrow Down</kbd> | Open and focus the first item | Focus the next item |
| <kbd>Arrow Up</kbd> | — | Focus the previous item (or the button) |
| <kbd>Home</kbd> / <kbd>End</kbd> | — | First / last item |
| <kbd>Escape</kbd> | Close (if open) | Close and **return focus to the button** |

Opening on hover (mouse) is fine, but hover must never move focus.

## WCAG 2.1 AA checklist

- **1.4.1 / 4.1.2 Roles, names, values** — `aria-expanded` reflects the open state;
  `aria-controls` associates each button with its submenu; the submenu has an accessible
  name (e.g. `aria-label` set to the parent's title).
- **2.1.1 / 2.1.2 Keyboard, no trap** — every item is reachable and operable by keyboard;
  <kbd>Tab</kbd> is never trapped.
- **2.4.3 Focus order** — focus moves into an opened submenu and returns to the trigger
  on <kbd>Escape</kbd>.
- **2.4.7 Focus visible** — do not remove focus outlines; ensure a visible focus style.
- **2.4.8 / current location** — mark the active link with `aria-current="page"`.
- **2.3.3 / animation** — respect `prefers-reduced-motion`; disable non-essential
  transitions.

## Reference implementation

The Wilkes & Liberty Next.js front end implements exactly this pattern in
`components/navigation/NavBar.tsx` (disclosure buttons, roving focus within submenus,
focus return on Escape, `aria-current`, and `prefers-reduced-motion`). It renders the
Menu Autopilot tree returned by GraphQL Compose's `menu(name:)` query with no
navigation-specific backend code.
