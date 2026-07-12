<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\menu_autopilot\NavSyncManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures which menus Menu Autopilot manages and the defaults it applies.
 */
final class MenuAutopilotSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly NavSyncManager $syncManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('menu_autopilot.sync_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'menu_autopilot_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['menu_autopilot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('menu_autopilot.settings');

    $menus = [];
    foreach ($this->entityTypeManager->getStorage('menu')->loadMultiple() as $menu) {
      $menus[$menu->id()] = $menu->label();
    }
    natcasesort($menus);

    $form['managed_menus'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Managed menus'),
      '#description' => $this->t('The menus whose links can drive automatic children. Only links in these menus show the “Automatic children” controls and are kept in sync.'),
      '#options' => $menus,
      '#default_value' => $config->get('managed_menus') ?: [],
    ];

    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Defaults for new dynamic parents'),
      '#description' => $this->t('Applied as the starting values when an editor first configures a link. Each link can override them.'),
      '#open' => TRUE,
    ];
    $form['defaults']['default_sort'] = [
      '#type' => 'select',
      '#title' => $this->t('Default sort'),
      '#options' => [
        'title_asc' => $this->t('Title (A→Z)'),
        'title_desc' => $this->t('Title (Z→A)'),
        'created_desc' => $this->t('Newest first'),
        'created_asc' => $this->t('Oldest first'),
      ],
      '#default_value' => $config->get('default_sort') ?: 'title_asc',
    ];
    $form['defaults']['default_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Default maximum children'),
      '#description' => $this->t('The most children to generate by default. Use 0 for no limit.'),
      '#min' => 0,
      '#default_value' => (int) ($config->get('default_limit') ?? 0),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $managed = array_values(array_filter($form_state->getValue('managed_menus')));
    $this->config('menu_autopilot.settings')
      ->set('managed_menus', $managed)
      ->set('default_sort', $form_state->getValue('default_sort'))
      ->set('default_limit', (int) $form_state->getValue('default_limit'))
      ->save();

    // Apply the new set of managed menus immediately: reconcile picks up links
    // in newly managed menus and is a no-op for unchanged ones.
    $this->syncManager->reconcile();

    parent::submitForm($form, $form_state);
  }

}
