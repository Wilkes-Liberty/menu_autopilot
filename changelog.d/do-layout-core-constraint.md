---
section: Tests
---
`CoreVersionRequirementTest` reads the module `composer.json` from the drupal.org project mount when the checkout is also the Composer project root. The 1.5.0 and 1.5.1 drupal.org pipelines read the expanded project file, which has no `require.drupal/core`, and failed with a null constraint.
