# Contributing to Menu Autopilot

Thanks for helping improve Menu Autopilot. Contributions of all kinds are welcome —
bug reports, patches, docs, and test coverage.

## Where development happens

- **Primary repository (issues + pull requests):**
  [github.com/Wilkes-Liberty/menu_autopilot](https://github.com/Wilkes-Liberty/menu_autopilot)
- **Mirror:** [git.drupalcode.org/project/menu_autopilot](https://git.drupalcode.org/project/menu_autopilot)
- **Releases:** [drupal.org/project/menu_autopilot](https://www.drupal.org/project/menu_autopilot)
  (installable with Composer)

Open issues and pull requests on GitHub. The drupalcode.org repository is a mirror that
carries release branches and tags so `packages.drupal.org` can build the Composer
package; you do not need to interact with it to contribute.

## Branches

- `1.x` is the active development and release branch.
- Branch your work off `1.x` with a short, descriptive name:
  `feature/<slug>`, `fix/<slug>`, or `chore/<slug>` (lowercase, hyphenated).
- Open your pull request against `1.x`.

## Making a change

1. Fork and branch off `1.x`.
2. Make your change, following Drupal coding standards (see below).
3. Add or update tests for any behavior change.
4. Add an entry under `## [Unreleased]` in `CHANGELOG.md` for anything users
   would notice. The `CHANGELOG updated` CI check is opt-in: apply the
   `changelog` label to PRs whose changes belong in the release notes and it
   enforces the entry (Dependabot PRs are exempt).
5. Open a pull request with a clear description of the problem and the fix.

## Coding standards & checks

This module targets Drupal 10.6+/11.3+ and PHP 8.1+. Please run the same checks CI runs:

```bash
# Drupal coding standards
phpcs --standard=Drupal,DrupalPractice /path/to/menu_autopilot

# Static analysis
phpstan analyse -c phpstan.neon.dist

# Tests (from a Drupal codebase that contains the module)
phpunit -c web/core web/modules/contrib/menu_autopilot
```

## Accessibility

Accessibility is a first-class goal of this project. Navigation is one of the most
accessibility-sensitive parts of any site, so changes that affect the rendered menu or
the recommended frontend pattern must preserve WCAG 2.1 AA conformance (keyboard
operability, focus visibility, correct roles and names). Please call out accessibility
implications in your pull request.

## License

By contributing, you agree that your contributions are licensed under the
[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) license that
covers the project.
