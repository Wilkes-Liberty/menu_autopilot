<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot_mcp\Plugin\tool\Tool;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Says what Menu Autopilot does with one menu link, before a client edits it.
 *
 * The module's two base fields are internal, so JSON:API and GraphQL leave
 * them out. A client cannot otherwise tell a managed link from a curated one.
 */
#[Tool(
  id: 'menu_autopilot_link_info',
  label: new TranslatableMarkup('Menu Autopilot link info'),
  description: new TranslatableMarkup('For one menu link UUID: whether it is a dynamic parent, an automatic child (and of which node id), or neither, and what an edit by an API client will do. Call it before renaming, re-weighting, moving or deleting a menu link. Returns the source type and existing-children policy of a dynamic parent. Never returns a label pattern or a node field value.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'link' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Menu link UUID'),
      description: new TranslatableMarkup('UUID of a menu_link_content entity.'),
      required: TRUE,
      constraints: ['Regex' => ['pattern' => '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D']],
    ),
  ],
)]
final class LinkInfoTool extends MenuAutopilotToolBase {

  /**
   * Entity repository.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * Module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityRepository = $container->get('entity.repository');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    $uuid = is_string($values['link'] ?? NULL) ? strtolower(trim($values['link'])) : '';
    if (!Uuid::isValid($uuid)) {
      throw new \InvalidArgumentException('Invalid UUID.');
    }
    $info = [
      'found' => FALSE,
      'role' => NULL,
      'menu' => NULL,
      'menu_is_managed' => NULL,
      'enabled' => NULL,
      'node' => NULL,
      'disabled_by_save' => NULL,
      'source_type' => NULL,
      'existing_children' => NULL,
      'under_dynamic_parent' => NULL,
      'parent_existing_children' => NULL,
      'effect_of_client_edit' => 'No menu link has this UUID.',
    ];
    $link = $this->entityRepository->loadEntityByUuid('menu_link_content', $uuid);
    if (!$link instanceof MenuLinkContentInterface) {
      return $info;
    }

    $data = _menu_autopilot_link_data($link);
    $menu = (string) $link->getMenuName();
    $info['found'] = TRUE;
    $info['menu'] = $menu;
    $info['menu_is_managed'] = in_array($menu, $this->syncManager->managedMenus(), TRUE);
    $info['enabled'] = $link->isEnabled();

    $parent_source = $this->sourceOf($this->parentOf($link));
    $info['under_dynamic_parent'] = $parent_source !== [];
    if ($parent_source !== []) {
      $info['parent_existing_children'] = _menu_autopilot_existing_children_policy($parent_source['existing_children'] ?? 'adopt');
    }

    if (!empty($data['managed'])) {
      $info['role'] = 'managed_child';
      $info['node'] = (int) ($data['node'] ?? 0);
      $info['disabled_by_save'] = !empty($data['disabled_by_save']);
      $info['effect_of_client_edit'] = $this->managedChildEffect($parent_source);
      return $info;
    }

    $source = $this->sourceOf($link);
    if ($source !== []) {
      $info['role'] = 'dynamic_parent';
      $info['source_type'] = (string) $source['type'];
      $info['existing_children'] = _menu_autopilot_existing_children_policy($source['existing_children'] ?? 'adopt');
      $info['effect_of_client_edit'] = $this->dynamicParentEffect($source, $info['existing_children'], $info['menu_is_managed']);
      return $info;
    }

    $info['role'] = 'plain';
    $info['effect_of_client_edit'] = $parent_source === []
      ? 'Menu Autopilot does not change this link. One exception: a dynamic parent in the same menu that moves matching links can move a link that points at one of its nodes under itself.'
      : $this->plainChildEffect($info['parent_existing_children']);
    return $info;
  }

  /**
   * The menu link content parent of a link, if it has one.
   */
  private function parentOf(MenuLinkContentInterface $link): ?MenuLinkContentInterface {
    $parent_id = (string) $link->getParentId();
    if (!str_starts_with($parent_id, 'menu_link_content:')) {
      return NULL;
    }
    $uuid = substr($parent_id, strlen('menu_link_content:'));
    if (!Uuid::isValid($uuid)) {
      return NULL;
    }
    $parent = $this->entityRepository->loadEntityByUuid('menu_link_content', $uuid);
    return $parent instanceof MenuLinkContentInterface ? $parent : NULL;
  }

  /**
   * A link's source descriptor, or an empty array when it is not a parent.
   *
   * The same test the module's presave hook uses for its dynamic marker.
   */
  private function sourceOf(?MenuLinkContentInterface $link): array {
    if ($link === NULL) {
      return [];
    }
    $data = _menu_autopilot_link_data($link);
    $source = $data['source'] ?? NULL;
    if (!empty($data['managed']) || !is_array($source) || ($source['type'] ?? 'none') === 'none') {
      return [];
    }
    return $source;
  }

  /**
   * What an edit to an automatic child does.
   */
  private function managedChildEffect(array $parent_source): string {
    $keeps_weight = ($parent_source['type'] ?? '') !== 'manual' && ($parent_source['sort'] ?? '') === 'preserve';
    $overwritten = $keeps_weight
      ? 'Title and URI are overwritten on the next sync of its parent. Weight is kept, because the parent keeps the current order.'
      : 'Title, weight and URI are overwritten on the next sync of its parent.';
    return $overwritten . ' The enabled state is kept: a sync never enables a link an editor disabled. If the link is deleted, the next sync creates it again while its node is published and in the source. To change the label or the order, edit the parent link.';
  }

  /**
   * What an edit to a dynamic parent does.
   */
  private function dynamicParentEffect(array $source, string $policy, bool $menu_is_managed): string {
    if ($source['type'] === 'term' && !$this->moduleHandler->moduleExists('taxonomy')) {
      return 'This parent has a taxonomy term source and Taxonomy is not installed, so no sync runs and its children are left alone.';
    }
    $effect = 'Saving this link runs a full sync of its children. Automatic children are created, retitled, re-weighted and deleted to match the source.';
    $effect .= match ($policy) {
      'replace' => ' Every unmanaged child is deleted.',
      'adopt_prune' => ' Unmanaged children that point at a source node become automatic. Every other unmanaged child is deleted.',
      'add' => ' Unmanaged children are left alone.',
      default => ' Unmanaged children that point at a source node become automatic. Other unmanaged children are kept.',
    };
    $effect .= ' Deleting this link deletes its automatic children.';
    if (!$menu_is_managed) {
      $effect .= ' Its menu is not a managed menu, so node changes and the rebuild command do not sync it.';
    }
    return $effect;
  }

  /**
   * What happens to a hand-made link that sits under a dynamic parent.
   */
  private function plainChildEffect(?string $policy): string {
    return match ($policy) {
      'replace' => 'This link sits under a dynamic parent that replaces all children. The next sync of the parent deletes it.',
      'adopt_prune' => 'This link sits under a dynamic parent. If it points at a node in the parent’s source, the next sync makes it automatic and overwrites its title, weight and URI. If not, the next sync deletes it.',
      'add' => 'This link sits under a dynamic parent that only adds missing children. A sync leaves it alone, unless an automatic child exists for the same node: then this link is deleted as a duplicate.',
      default => 'This link sits under a dynamic parent. If it points at a node in the parent’s source, the next sync makes it automatic and overwrites its title, weight and URI. If not, it is kept as a curated item.',
    };
  }

}
