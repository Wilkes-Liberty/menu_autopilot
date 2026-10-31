<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\menu_autopilot\NavStatusReporter;
use Drupal\menu_autopilot\NavSyncManager;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parent-link form alter, validation, builders, and option helpers.
 *
 * This is not a FormInterface form. menu_link_content owns the form;
 * this class adds the Menu Autopilot section and writes the map field.
 * Procedural wrappers in menu_autopilot.module keep the hook and Form
 * API callback names (tests and #entity_builders look those up).
 */
final class MenuLinkAutopilotForm implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly NavStatusReporter $statusReporter,
    private readonly NavSyncManager $syncManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('menu_autopilot.status_reporter'),
      $container->get('menu_autopilot.sync_manager'),
    );
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * Adds the Menu Autopilot section to a menu_link_content form.
   *
   * Values are persisted onto the link's `menu_autopilot` base field
   * by buildLink(). Managed children get a notice instead of the
   * parent controls; a sync-disabled child also gets buildManagedLink().
   *
   * @param array $form
   *   The menu link form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function alterLinkForm(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $link = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$link instanceof MenuLinkContentInterface) {
      return;
    }
    // Never offer the controls on a link the module generates and owns.
    // The reporter already injected here owns the map read.
    $data = $this->statusReporter->getData($link);
    if (!empty($data['managed'])) {
      $form['menu_autopilot_managed'] = [
        '#type' => 'item',
        '#title' => $this->t('Menu Autopilot child'),
        '#markup' => $this->t('This link is kept in sync by Menu Autopilot. To change the label pattern or keep a drag-and-drop order, edit the <em>parent</em> item and open its <em>Menu Autopilot</em> section.'),
        '#weight' => 5,
      ];
      if (!empty($data['disabled_by_save'])) {
        // No editor disabled this link: one of the sync's own saves did.
        // Say so, and say what saving this form means, because a sync
        // never enables a link an editor chose to disable.
        $form['menu_autopilot_managed']['#markup'] = $this->t('This link is kept in sync by Menu Autopilot. It is disabled because another module disabled it while Menu Autopilot was saving it, not because an editor chose to. The next sync run by an account that may enable menu links enables it. If you save this form with <em>Enabled</em> unchecked, the link stays disabled until an editor enables it.');
        $form['#entity_builders'][] = '_menu_autopilot_managed_link_form_builder';
      }
      return;
    }
    $source = is_array($data['source'] ?? NULL) ? $data['source'] : [];
    $settings = $this->configFactory->get('menu_autopilot.settings');
    $managed_menus = $this->statusReporter->managedMenus();
    $settings_url = Url::fromRoute('menu_autopilot.settings_form')->toString();

    // Warn when this link's menu is not managed: parent save still
    // syncs; node changes and the rebuild command do not, until the
    // menu is enabled.
    $menu_name = $link->getMenuName();
    if ($menu_name && !in_array($menu_name, $managed_menus, TRUE)) {
      $description = $this->t('This link’s menu is not a managed menu. Saving this link syncs its children. Node changes and the rebuild command do not, until the menu is enabled on the <a href=":settings">settings page</a>.', [':settings' => $settings_url]);
    }
    else {
      $description = $this->t('Menu Autopilot generates this link’s children from published content and keeps them in sync. Only menus enabled on the <a href=":settings">settings page</a> are synced.', [':settings' => $settings_url]);
    }

    // Taxonomy is optional (see info.yml). Same pattern as Token: omit
    // the term source and its widgets when the module is not installed
    // so the form does not target taxonomy_term or load that storage.
    $has_taxonomy = $this->moduleHandler->moduleExists('taxonomy');
    $source_type = $source['type'] ?? 'none';
    if ($source_type === 'term' && !$has_taxonomy) {
      $source_type = 'none';
    }

    $form['menu_autopilot'] = [
      '#type' => 'details',
      '#title' => $this->parentSettingsTitle($link),
      '#description' => $description,
      '#open' => $source_type !== 'none',
      '#tree' => TRUE,
      '#weight' => 5,
    ];

    $source_options = [
      'none' => $this->t('Nothing (curated by hand)'),
    ];
    if ($has_taxonomy) {
      $source_options['term'] = $this->t('Nodes tagged with a taxonomy term');
    }
    $source_options['bundle'] = $this->t('All nodes of a content type');
    $source_options['manual'] = $this->t('A hand-picked list of nodes');

    $form['menu_autopilot']['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Children come from'),
      '#options' => $source_options,
      '#default_value' => $source_type,
    ];

    $existing_selector = ':input[name="menu_autopilot[existing_children]"]';
    $existing_help_id = 'menu-autopilot-existing-children-help';
    $form['menu_autopilot']['existing_children'] = [
      '#type' => 'radios',
      '#title' => $this->t('Existing children'),
      '#description' => $this->t('Matching links are ones that already point at a source node.'),
      '#options' => [
        'adopt' => $this->t('Reuse matching links (keep extras)'),
        'adopt_prune' => $this->t('Reuse matching links, remove extras'),
        'add' => $this->t('Add missing children only'),
        'replace' => $this->t('Replace all children'),
      ],
      '#default_value' => $this->existingChildrenPolicy($source['existing_children'] ?? 'adopt'),
      '#attributes' => ['aria-describedby' => $existing_help_id],
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle', 'manual'])],
    ];

    // One explanation under the radios; #states shows the selected option.
    $form['menu_autopilot']['existing_children_help'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $existing_help_id,
        'class' => ['form-item__description'],
        'aria-live' => 'polite',
      ],
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle', 'manual'])],
    ];
    foreach ($this->existingChildrenHelp() as $policy => $text) {
      $form['menu_autopilot']['existing_children_help'][$policy] = [
        '#type' => 'container',
        '#states' => [
          'visible' => [$existing_selector => ['value' => $policy]],
        ],
        'text' => [
          '#markup' => '<p>' . $text . '</p>',
        ],
      ];
    }

    $form['menu_autopilot']['reparent_matches'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Move matching links from elsewhere in this menu'),
      '#description' => $this->t('Unmanaged links in this menu that already point at a source node are moved under this parent, then treated according to the option above. Links owned by another automatic parent are left alone.'),
      '#default_value' => !empty($source['reparent_matches']),
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle', 'manual'])],
    ];

    if ($has_taxonomy) {
      $reference_fields = $this->referenceFieldOptions();
      $form['menu_autopilot']['reference_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Term reference field'),
        '#description' => $reference_fields
          ? $this->t('The node field that references the term. Only fields that reference taxonomy terms are listed.')
          : $this->t('No taxonomy reference fields exist on your content types yet. Add an entity reference field that targets taxonomy terms to use this source.'),
        '#options' => ['' => $this->t('- Select a field -')] + $reference_fields,
        '#default_value' => $source['reference_field'] ?? '',
        '#states' => ['visible' => $this->visibleIfSourceType(['term'])],
      ];

      $form['menu_autopilot']['term'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#title' => $this->t('Taxonomy term'),
        '#description' => $this->t('Children are the published nodes whose reference field points at this term.'),
        '#default_value' => !empty($source['term'])
          ? $this->entityTypeManager->getStorage('taxonomy_term')->load($source['term'])
          : NULL,
        '#states' => ['visible' => $this->visibleIfSourceType(['term'])],
      ];
    }

    $form['menu_autopilot']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#description' => $this->t('Required for a content-type source; an optional extra filter for a term source.'),
      '#options' => ['' => $this->t('- Any -')] + $this->nodeTypeOptions(),
      '#default_value' => $source['bundle'] ?? '',
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle'])],
    ];

    $form['menu_autopilot']['nodes'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#tags' => TRUE,
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('The children, in the order entered. Unpublished nodes are skipped.'),
      '#default_value' => $this->loadNodes($source['nodes'] ?? []),
      '#states' => ['visible' => $this->visibleIfSourceType(['manual'])],
    ];

    $form['menu_autopilot']['sort'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort children by'),
      '#description' => $this->t('“Keep current order” leaves weights alone so you can drag children on the menu overview. New items are appended at the end. The other options rewrite weights on every sync.'),
      '#options' => $this->sortOptions(),
      '#default_value' => $source['sort'] ?? $settings->get('default_sort') ?? 'title_asc',
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle'])],
    ];

    $form['menu_autopilot']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum children'),
      '#description' => $this->t('The most children to generate. Use 0 for no limit.'),
      '#min' => 0,
      '#default_value' => $source['limit'] ?? $settings->get('default_limit') ?? 0,
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle'])],
    ];

    $form['menu_autopilot']['title_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Child menu label'),
      '#description' => $this->t('Token pattern for each generated label. Leave blank to use the node title only. To include a subtitle field: <code>[node:title] [node:field_subtitle]</code>.'),
      '#default_value' => $source['title_pattern'] ?? '',
      '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle', 'manual'])],
    ];

    // A link with no source has no automatic children: saving it that
    // way released them.
    $disabled_children = $source_type === 'none' ? [] : $this->disabledChildrenSummary($link);
    if ($disabled_children !== []) {
      $form['menu_autopilot']['disabled_children'] = $disabled_children;
      $form['menu_autopilot']['#open'] = TRUE;
    }

    if ($this->moduleHandler->moduleExists('token')) {
      $form['menu_autopilot']['token_help'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['node'],
        '#states' => ['visible' => $this->visibleIfSourceType(['term', 'bundle', 'manual'])],
      ];
    }

    $form['#entity_builders'][] = '_menu_autopilot_link_form_builder';
    $form['#validate'][] = '_menu_autopilot_link_form_validate';
  }

  /**
   * Validation handler: enforces the fields each source type needs.
   *
   * Runs only against the Menu Autopilot values; the standard
   * menu-link validation is untouched. Errors are attached to the
   * offending element so they are announced to assistive technology
   * and anchored in the form.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function validateLinkForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('menu_autopilot') ?: [];
    switch ($values['source_type'] ?? 'none') {
      case 'term':
        if (!$this->moduleHandler->moduleExists('taxonomy')) {
          break;
        }
        if (empty($values['reference_field'])) {
          $form_state->setError($form['menu_autopilot']['reference_field'], $this->t('Select the node field that references the term.'));
        }
        if (empty($values['term'])) {
          $form_state->setError($form['menu_autopilot']['term'], $this->t('Choose the taxonomy term whose content should appear.'));
        }
        break;

      case 'bundle':
        if (empty($values['bundle'])) {
          $form_state->setError($form['menu_autopilot']['bundle'], $this->t('Choose the content type whose nodes should appear.'));
        }
        break;

      case 'manual':
        if (empty($values['nodes'])) {
          $form_state->setError($form['menu_autopilot']['nodes'], $this->t('Add at least one node, or choose a different source.'));
        }
        break;
    }
  }

  /**
   * Entity builder: writes the Menu Autopilot values onto the link.
   *
   * Runs as the menu link entity is assembled from the submitted form,
   * before it is saved, so the subsequent entity-save hook sees the new
   * descriptor and reconciles the children.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The menu link being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function buildLink(MenuLinkContentInterface $link, FormStateInterface $form_state): void {
    $values = $form_state->getValue('menu_autopilot') ?: [];
    $type = $values['source_type'] ?? 'none';
    if ($type === 'term' && !$this->moduleHandler->moduleExists('taxonomy')) {
      $type = 'none';
    }

    if ($type === 'none') {
      // Clear the descriptor. Save releases managed children (clears the
      // owned flag, keeps the links) so they are not stranded without a
      // parent.
      $link->set('menu_autopilot', NULL);
      return;
    }

    $source = [
      'type' => $type,
      'existing_children' => $this->existingChildrenPolicy($values['existing_children'] ?? 'adopt'),
      'reparent_matches' => !empty($values['reparent_matches']),
      'title_pattern' => trim((string) ($values['title_pattern'] ?? '')),
    ];
    if ($type === 'term' || $type === 'bundle') {
      $source['sort'] = $values['sort'] ?? 'title_asc';
      $source['limit'] = (int) ($values['limit'] ?? 0);
    }
    if ($type === 'term') {
      $source['reference_field'] = trim((string) ($values['reference_field'] ?? ''));
      $source['term'] = (int) ($values['term'] ?? 0);
      $source['bundle'] = (string) ($values['bundle'] ?? '');
    }
    elseif ($type === 'bundle') {
      $source['bundle'] = (string) ($values['bundle'] ?? '');
    }
    elseif ($type === 'manual') {
      $source['nodes'] = array_values(array_map(
        static fn (array $item): int => (int) $item['target_id'],
        $values['nodes'] ?? [],
      ));
    }

    $link->set('menu_autopilot', ['source' => $source]);
  }

  /**
   * Entity builder for an automatic child that a sync save left disabled.
   *
   * An editor who saves the link form with Enabled unchecked has chosen
   * disabled, so the `disabled_by_save` flag is dropped and no sync
   * enables the link again. With Enabled checked the flag stays: if this
   * save is disabled too, a later sync can still enable the link, which
   * is what the editor asked for. The flag is dropped on the next save
   * once the link is enabled.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The menu link being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function buildManagedLink(MenuLinkContentInterface $link, FormStateInterface $form_state): void {
    // No value means the editor could not see the checkbox. That is not
    // a choice.
    if (!$form_state->hasValue('enabled') || $link->isEnabled()) {
      return;
    }
    $this->syncManager->releaseDisabledBySaveFlag($link);
  }

  /**
   * The Menu Autopilot details title on a parent link form.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The parent link being edited.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   A title that names this module and this parent.
   */
  public function parentSettingsTitle(MenuLinkContentInterface $link): TranslatableMarkup {
    $title = trim((string) $link->getTitle());
    return $title !== ''
      ? $this->t('Menu Autopilot: children of @parent', ['@parent' => $title])
      : $this->t('Menu Autopilot: children of this item');
  }

  /**
   * Sort options for the parent-link form and the site-wide defaults.
   *
   * @return array
   *   Translatable labels keyed by sort id.
   */
  public function sortOptions(): array {
    return [
      'title_asc' => $this->t('Title (A→Z)'),
      'title_desc' => $this->t('Title (Z→A)'),
      'created_desc' => $this->t('Newest first'),
      'created_asc' => $this->t('Oldest first'),
      'preserve' => $this->t('Keep current order (drag to rearrange)'),
    ];
  }

  /**
   * Normalize an Existing children policy to a known value.
   *
   * @param mixed $value
   *   Stored or submitted policy.
   *
   * @return string
   *   One of adopt, adopt_prune, add, or replace.
   */
  public function existingChildrenPolicy(mixed $value): string {
    $value = (string) $value;
    return in_array($value, ['adopt', 'adopt_prune', 'add', 'replace'], TRUE)
      ? $value
      : 'adopt';
  }

  /**
   * Per-option explanations for the Existing children radios.
   *
   * @return array
   *   Translatable markup keyed by policy id.
   */
  public function existingChildrenHelp(): array {
    return [
      'adopt' => $this->t('Hand-created links that already point at a source node are reused and become automatic. Extra children that are not in the source stay as curated items.'),
      'adopt_prune' => $this->t('Matching links are reused and become automatic. Extra unmanaged children under this link are removed.'),
      'add' => $this->t('A managed child is added only when a source node has no child yet. Existing hand-created links are not changed.'),
      'replace' => $this->t('Every unmanaged child under this link is deleted. The module then builds a new set of automatic children from the source.'),
    ];
  }

  /**
   * Lists a parent's disabled automatic children on its link form.
   *
   * The node is published and its link exists, but no menu shows it.
   * Nothing else in the UI says why, so the parent's Menu Autopilot
   * section does.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The parent link being edited.
   *
   * @return array
   *   A render array, or an empty array when no automatic child is
   *   disabled.
   */
  private function disabledChildrenSummary(MenuLinkContentInterface $parent): array {
    if ($parent->isNew()) {
      return [];
    }
    $rows = $this->statusReporter->disabledManagedChildren($parent);
    if ($rows === []) {
      return [];
    }
    // Bounded: a parent can have hundreds of children.
    $limit = 20;
    $items = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
      $items[] = $row['disabled_by_save']
        ? $this->t('@title — disabled by another module while Menu Autopilot was saving it. The next sync run by an account that may enable menu links enables it.', ['@title' => $row['title']])
        : $this->t('@title — disabled outside Menu Autopilot. It stays disabled until an editor enables it.', ['@title' => $row['title']]);
    }
    if (count($rows) > $limit) {
      $items[] = $this->t('… and @count more.', ['@count' => count($rows) - $limit]);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $this->formatPlural(count($rows), '1 automatic child is disabled and does not show in the menu', '@count automatic children are disabled and do not show in the menu'),
      '#items' => $items,
      '#weight' => -10,
    ];
  }

  /**
   * Content-type options (machine name => label) for the source form.
   *
   * @return array
   *   Options keyed by node type machine name.
   */
  private function nodeTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $options[$type->id()] = $type->label();
    }
    natcasesort($options);
    return $options;
  }

  /**
   * Node fields that reference taxonomy terms (machine name => label).
   *
   * Powers the "Term reference field" select so editors pick from real
   * fields instead of typing a machine name. Fields are de-duplicated
   * across bundles by machine name; the label shows both the human
   * label and the machine name.
   *
   * @return array
   *   Options keyed by field machine name.
   */
  private function referenceFieldOptions(): array {
    $options = [];
    $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo('node'));
    foreach ($bundles as $bundle) {
      foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }
        if ($definition->getType() === 'entity_reference'
          && $definition->getSetting('target_type') === 'taxonomy_term'
        ) {
          $options[$name] = $definition->getLabel() . ' (' . $name . ')';
        }
      }
    }
    natcasesort($options);
    return $options;
  }

  /**
   * Loads node entities for an ordered list of ids (for form defaults).
   *
   * @param array $ids
   *   Node ids.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded nodes, in the given order, skipping any that no
   *   longer exist.
   */
  private function loadNodes(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = [];
    foreach ($ids as $id) {
      $node = $storage->load($id);
      if ($node instanceof NodeInterface) {
        $nodes[] = $node;
      }
    }
    return $nodes;
  }

  /**
   * Builds a #states visible condition for the given source types.
   *
   * Builds [ [sel => term], 'or', [sel => bundle] ] so one widget can
   * appear for more than one source.
   *
   * @param string[] $types
   *   Source type ids (term, bundle, manual).
   *
   * @return array
   *   A #states visible condition.
   */
  private function visibleIfSourceType(array $types): array {
    $type_selector = ':input[name="menu_autopilot[source_type]"]';
    $states = [];
    foreach ($types as $type) {
      if ($states) {
        $states[] = 'or';
      }
      $states[] = [$type_selector => ['value' => $type]];
    }
    return $states;
  }

}
