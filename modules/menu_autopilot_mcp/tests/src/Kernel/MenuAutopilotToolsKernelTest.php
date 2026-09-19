<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot_mcp\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\mcp_sentinel\Plugin\tool\Tool\McpGovernedToolBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\tool\Tool\ToolBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises discovery and direct execution against source governance.
 *
 * @group menu_autopilot
 *
 * @runTestsInSeparateProcesses
 */
#[Group('menu_autopilot')]
#[RunTestsInSeparateProcesses]
final class MenuAutopilotToolsKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * A node field value. It must never reach a tool result.
   */
  private const FIELD_VALUE = 'subtitle-that-stays-in-the-node-7Q';

  /**
   * A label pattern. It must never reach a tool result.
   */
  private const PATTERN = '[node:title] pattern-marker-7Q';

  private const READ_PERMISSION = 'use menu autopilot mcp tools';

  private const WRITE_PERMISSION = 'normalize menu link uris via mcp';

  private const TOOLS = [
    'menu_autopilot_status',
    'menu_autopilot_link_info',
    'menu_autopilot_normalize_uris',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'node',
    'serialization', 'jsonapi', 'tool', 'key', 'image', 'options',
    'path_alias', 'consumers', 'simple_oauth', 'encrypt', 'audit_chain',
    'mcp_sentinel', 'link', 'menu_link_content', 'menu_autopilot',
    'menu_autopilot_mcp',
  ];

  /**
   * The governed account the tools run as.
   */
  private AccountInterface $governed;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Tool API and MCP Sentinel are optional. A pipeline that does not install
    // them skips here; the GitHub workflow installs both and fails unless
    // these tests ran.
    if (!class_exists(ToolBase::class) || !class_exists(McpGovernedToolBase::class)) {
      $this->markTestSkipped('Tool API and MCP Sentinel are not installed.');
    }
    parent::setUp();
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])->execute();
    // Sentinel checks content locks before every governed write.
    $this->installSchema('mcp_sentinel', ['mcp_sentinel_content_locks']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'mcp_sentinel', 'menu_autopilot']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();

    $role = Role::load('mcp_api') ?? Role::create(['id' => 'mcp_api', 'label' => 'MCP API']);
    $role->grantPermission('access mcp sentinel context')
      ->grantPermission(self::READ_PERMISSION)
      ->save();
    $this->config('mcp_sentinel.settings')->set('governed_role_fallback', TRUE)->save();
    $this->setUpCurrentUser(['roles' => ['mcp_api']]);
    $this->governed = $this->container->get('current_user')->getAccount();
  }

  /**
   * The status tool runs for a governed account and refuses an anonymous one.
   */
  public function testGovernedToolsAndAnonymousDenial(): void {
    $tool = $this->tool('menu_autopilot_status');
    self::assertTrue($tool->discoveryAccess($this->governed)->isAllowed());
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    self::assertSame(['main'], $tool->getResult()->getContextValues()['managed_menus']);

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    foreach (self::TOOLS as $id) {
      $denied = $this->tool($id);
      self::assertFalse($denied->discoveryAccess(new AnonymousUserSession())->isAllowed(), $id);
      self::assertFalse($denied->access(), $id);
      $denied->execute();
      self::assertFalse($denied->getResultStatus(), $id);
      self::assertEmpty($denied->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Sentinel access alone does not grant the module's tools.
   */
  public function testModulePermissionIsRequired(): void {
    Role::load('mcp_api')->revokePermission(self::READ_PERMISSION)
      ->grantPermission(self::WRITE_PERMISSION)->save();
    $account = $this->container->get('current_user');
    foreach (self::TOOLS as $id) {
      $tool = $this->tool($id);
      self::assertFalse($tool->discoveryAccess($account)->isAllowed(), $id);
      self::assertFalse($tool->access(), $id);
      $tool->execute();
      self::assertFalse($tool->getResultStatus(), $id);
      self::assertEmpty($tool->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Disabled auditing makes governance not ready, so every tool refuses.
   */
  public function testGovernanceNotReadyRefusesDirectExecution(): void {
    $this->config('mcp_sentinel.settings')->set('audit_enabled', FALSE)->save();
    foreach (self::TOOLS as $id) {
      $tool = $this->tool($id);
      self::assertFalse($tool->discoveryAccess($this->container->get('current_user'))->isAllowed(), $id);
      self::assertFalse($tool->access(), $id);
      $tool->execute();
      self::assertFalse($tool->getResultStatus(), $id);
      self::assertEmpty($tool->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Status counts each kind of child and leaks no pattern or field value.
   */
  public function testStatusCountsChildrenAndLeaksNoPatternOrFieldValue(): void {
    [$parent, $nodes] = $this->ungoverned(function (): array {
      // Add-only keeps the hand-made link unmanaged, so the counts are stable.
      $parent = $this->createParent(['existing_children' => 'add', 'title_pattern' => self::PATTERN]);
      // Unpublished, so no automatic child is made for it: the hand-made
      // link is the only link to this node.
      $hand_made_target = $this->createPage('Hand made', FALSE);
      MenuLinkContent::create([
        'title' => 'Hand made',
        'menu_name' => 'main',
        'parent' => 'menu_link_content:' . $parent->uuid(),
        'link' => ['uri' => 'entity:node/' . $hand_made_target->id()],
      ])->save();
      MenuLinkContent::create([
        'title' => 'Curated',
        'menu_name' => 'main',
        'parent' => 'menu_link_content:' . $parent->uuid(),
        'link' => ['uri' => 'route:<nolink>'],
      ])->save();
      $nodes = [
        'atlas' => $this->createPage('Atlas'),
        'beacon' => $this->createPage('Beacon'),
        'comet' => $this->createPage('Comet'),
      ];
      // Beacon: an editor disabled it. Comet: a sync save left it disabled.
      $this->managedChild($parent, $nodes['beacon'])->set('enabled', FALSE)->save();
      $this->managedChild($parent, $nodes['comet'])->set('enabled', FALSE)->save();
      $comet = $this->managedChild($parent, $nodes['comet']);
      $comet->set('menu_autopilot', [
        'managed' => TRUE,
        'node' => (int) $nodes['comet']->id(),
        'disabled_by_save' => TRUE,
      ])->save();
      return [$parent, $nodes];
    });

    $tool = $this->tool('menu_autopilot_status');
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();

    self::assertSame(1, $values['parents_total']);
    self::assertFalse($values['parents_truncated']);
    $row = $values['parents'][0];
    self::assertSame($parent->uuid(), $row['uuid']);
    self::assertSame('Platforms', $row['title']);
    self::assertSame('main', $row['menu']);
    self::assertSame('bundle', $row['source_type']);
    self::assertSame('add', $row['existing_children']);
    self::assertSame(
      ['owned' => 3, 'adoptable' => 1, 'extra' => 1, 'disabled' => 2, 'disabled_by_save' => 1],
      $row['counts'],
    );
    $disabled = array_column($row['disabled_children'], NULL, 'node');
    self::assertSame(['title', 'node', 'disabled_by_save'], array_keys($row['disabled_children'][0]), 'Titles and node ids only.');
    self::assertFalse($disabled[(int) $nodes['beacon']->id()]['disabled_by_save']);
    self::assertTrue($disabled[(int) $nodes['comet']->id()]['disabled_by_save']);
    self::assertArrayNotHasKey((int) $nodes['atlas']->id(), $disabled);

    $json = json_encode($values);
    foreach (['title_pattern', '[node:', self::FIELD_VALUE] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $json, $forbidden);
    }
  }

  /**
   * The status report is bounded, and says when it was cut short.
   */
  public function testStatusReportIsBounded(): void {
    $this->ungoverned(function (): void {
      $first = $this->createParent();
      $this->createParent();
      $node = $this->createPage('Atlas');
      $this->managedChild($first, $node)->set('enabled', FALSE)->save();
    });

    $report = $this->container->get('menu_autopilot.sync_manager')->parentStatus(1, 0);
    self::assertSame(2, $report['parents_total']);
    self::assertTrue($report['parents_truncated']);
    self::assertCount(1, $report['parents']);
    self::assertSame(1, $report['parents'][0]['counts']['disabled']);
    self::assertSame([], $report['parents'][0]['disabled_children']);
    self::assertTrue($report['parents'][0]['disabled_children_truncated']);
  }

  /**
   * Link info tells a parent, an automatic child and a plain link apart.
   */
  public function testLinkInfoForParentChildAndPlainLink(): void {
    [$parent, $child, $under, $elsewhere, $node] = $this->ungoverned(function (): array {
      $parent = $this->createParent(['title_pattern' => self::PATTERN]);
      $node = $this->createPage('Atlas');
      $under = MenuLinkContent::create([
        'title' => 'Curated',
        'menu_name' => 'main',
        'parent' => 'menu_link_content:' . $parent->uuid(),
        'link' => ['uri' => 'route:<nolink>'],
      ]);
      $under->save();
      $elsewhere = MenuLinkContent::create([
        'title' => 'About',
        'menu_name' => 'main',
        'link' => ['uri' => 'route:<nolink>'],
      ]);
      $elsewhere->save();
      return [$parent, $this->managedChild($parent, $node), $under, $elsewhere, $node];
    });

    $info = $this->linkInfo($parent->uuid());
    self::assertTrue($info['found']);
    self::assertSame('dynamic_parent', $info['role']);
    self::assertSame('bundle', $info['source_type']);
    self::assertSame('adopt', $info['existing_children']);
    self::assertTrue($info['menu_is_managed']);
    self::assertStringContainsString('full sync', $info['effect_of_client_edit']);

    $info = $this->linkInfo($child->uuid());
    self::assertSame('managed_child', $info['role']);
    self::assertSame((int) $node->id(), $info['node']);
    self::assertFalse($info['disabled_by_save']);
    self::assertTrue($info['under_dynamic_parent']);
    self::assertStringContainsString('Title, weight and URI are overwritten on the next sync', $info['effect_of_client_edit']);

    $info = $this->linkInfo($under->uuid());
    self::assertSame('plain', $info['role']);
    self::assertTrue($info['under_dynamic_parent']);
    self::assertSame('adopt', $info['parent_existing_children']);
    self::assertNull($info['node']);

    $info = $this->linkInfo($elsewhere->uuid());
    self::assertSame('plain', $info['role']);
    self::assertFalse($info['under_dynamic_parent']);
    self::assertStringContainsString('does not change this link', $info['effect_of_client_edit']);

    // Upper case is the same UUID.
    self::assertSame('managed_child', $this->linkInfo(strtoupper($child->uuid()))['role']);

    $info = $this->linkInfo('00000000-0000-4000-8000-000000000000');
    self::assertFalse($info['found']);
    self::assertNull($info['role']);
  }

  /**
   * Link info never returns the label pattern, and follows the parent's sort.
   */
  public function testLinkInfoLeaksNoPatternAndKnowsKeptWeights(): void {
    [$parent, $child] = $this->ungoverned(function (): array {
      $parent = $this->createParent([
        'title_pattern' => self::PATTERN,
        'sort' => 'preserve',
        'existing_children' => 'replace',
      ]);
      return [$parent, $this->managedChild($parent, $this->createPage('Atlas'))];
    });

    $parent_info = $this->linkInfo($parent->uuid());
    self::assertSame('replace', $parent_info['existing_children']);
    self::assertStringContainsString('Every unmanaged child is deleted', $parent_info['effect_of_client_edit']);
    $child_info = $this->linkInfo($child->uuid());
    self::assertStringContainsString('Weight is kept', $child_info['effect_of_client_edit']);

    $json = json_encode([$parent_info, $child_info]);
    foreach (['title_pattern', '[node:', 'pattern-marker', self::FIELD_VALUE] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $json, $forbidden);
    }
    // The child's public title does carry the pattern's output. The tool does
    // not return titles at all.
    self::assertStringContainsString('pattern-marker-7Q', $child->getTitle());
  }

  /**
   * Bad input is refused with the fixed message and never echoed.
   */
  public function testInvalidInputRefusesWithoutEcho(): void {
    Role::load('mcp_api')->grantPermission(self::WRITE_PERMISSION)->save();
    $cases = [
      ['menu_autopilot_link_info', []],
      ['menu_autopilot_link_info', ['link' => 'not-a-uuid-7Q']],
      ['menu_autopilot_normalize_uris', []],
      ['menu_autopilot_normalize_uris', ['menus' => ['unmanaged-7Q']]],
      ['menu_autopilot_normalize_uris', ['menus' => ['main', 'Bad Name 7Q']]],
      ['menu_autopilot_normalize_uris', ['menus' => array_fill(0, 11, 'main')]],
    ];
    foreach ($cases as [$id, $inputs]) {
      $tool = $this->tool($id);
      try {
        foreach ($inputs as $name => $value) {
          $tool->setInputValue($name, $value);
        }
        $tool->execute();
      }
      catch (\Throwable $error) {
        // A typed-data refusal at input time is as good as one at execute.
        self::assertStringNotContainsString('7Q', $error->getMessage());
        continue;
      }
      self::assertFalse($tool->getResultStatus(), $id . ' ' . json_encode($inputs));
      self::assertStringNotContainsString('7Q', (string) $tool->getResultMessage());
      self::assertEmpty($tool->getResult()->getContextValues());
    }
  }

  /**
   * Normalising needs its own permission and a managed menu.
   */
  public function testNormalizeNeedsPermissionAndManagedMenu(): void {
    [$main, $footer, $canonical] = $this->editorialLinks();

    $tool = $this->normalizeTool(['main']);
    self::assertFalse($tool->discoveryAccess($this->container->get('current_user'))->isAllowed());
    self::assertFalse($tool->access());
    $tool->execute();
    self::assertFalse($tool->getResultStatus());
    self::assertEmpty($tool->getResult()->getContextValues());

    Role::load('mcp_api')->grantPermission(self::WRITE_PERMISSION)->save();
    $this->allowLivePublish();

    // Footer is a real menu name, but not a managed menu.
    $tool = $this->normalizeTool(['footer']);
    $tool->execute();
    self::assertFalse($tool->getResultStatus());
    $tool = $this->normalizeTool(['main', 'footer']);
    $tool->execute();
    self::assertFalse($tool->getResultStatus(), 'One unmanaged name refuses the whole call.');

    self::assertNotSame($canonical, $this->storedUri($main), 'A refused call writes nothing.');
    self::assertNotSame($canonical, $this->storedUri($footer));
  }

  /**
   * Normalising changes only the named menus and returns no old URI.
   */
  public function testNormalizeChangesOnlyNamedMenus(): void {
    $this->config('menu_autopilot.settings')->set('managed_menus', ['main', 'footer'])->save();
    Role::load('mcp_api')->grantPermission(self::WRITE_PERMISSION)->save();
    $this->allowLivePublish();
    [$main, $footer, $canonical] = $this->editorialLinks();

    $tool = $this->normalizeTool(['main', 'main']);
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();

    self::assertSame(['main'], $values['menus'], 'A repeated name is used once.');
    self::assertTrue($values['completed']);
    self::assertNull($values['failed_menu']);
    self::assertSame(1, $values['changed_total']);
    self::assertSame(1, $values['applied_total']);
    self::assertFalse($values['changes_truncated']);
    self::assertSame(
      [['link_id' => (int) $main->id(), 'to' => $canonical, 'applied' => TRUE]],
      $values['changes'],
    );
    self::assertSame($canonical, $this->storedUri($main));
    self::assertNotSame($canonical, $this->storedUri($footer), 'The footer menu was not named.');
    self::assertStringNotContainsString('7Q', json_encode($values), 'The old URI and its query string are not returned.');

    // Safe to repeat.
    $tool = $this->normalizeTool(['main']);
    $tool->execute();
    self::assertSame(0, $tool->getResult()->getContextValues()['changed_total']);
  }

  /**
   * A save that governance turns into a pending revision is not "applied".
   *
   * Sentinel's default profile denies publishing. A governed edit of a
   * published menu link becomes an unpublished forward revision, and the live
   * link keeps its old URI. The sync manager reports the save; the tool reads
   * the live link back and says the change did not reach it.
   */
  public function testNormalizeReportsChangesThatDidNotReachTheLiveLink(): void {
    Role::load('mcp_api')->grantPermission(self::WRITE_PERMISSION)->save();
    [$main, , $canonical] = $this->editorialLinks();

    $tool = $this->normalizeTool(['main']);
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();

    self::assertSame(1, $values['changed_total']);
    self::assertSame(0, $values['applied_total']);
    self::assertFalse($values['changes'][0]['applied']);
    self::assertNotSame($canonical, $this->storedUri($main), 'The live link is unchanged.');
  }

  /**
   * A save refused part way is reported, with the writes that happened.
   */
  public function testNormalizeReportsRefusedSavePartWay(): void {
    $this->config('menu_autopilot.settings')->set('managed_menus', ['main', 'footer', 'account'])->save();
    Role::load('mcp_api')->grantPermission(self::WRITE_PERMISSION)->save();
    $this->allowLivePublish();
    [$main, $footer, $canonical] = $this->editorialLinks();
    $this->ungoverned(static function () use ($main): void {
      $main->set('title', 'Refuses the save')->save();
    });
    // From here on, saving that link throws. Enabling a module rebuilds the
    // container, which forgets the acting account.
    $this->enableModules(['menu_autopilot_mcp_test']);
    $this->container->get('current_user')->setAccount($this->governed);

    $tool = $this->normalizeTool(['footer', 'main', 'account']);
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();

    self::assertFalse($values['completed']);
    self::assertSame('main', $values['failed_menu']);
    self::assertSame(1, $values['changed_total']);
    self::assertSame((int) $footer->id(), $values['changes'][0]['link_id']);
    self::assertTrue($values['changes'][0]['applied']);
    self::assertSame($canonical, $this->storedUri($footer));
    self::assertNotSame($canonical, $this->storedUri($main));
    self::assertStringNotContainsString('7Q', json_encode($values) . $tool->getResultMessage(), 'No exception text is returned.');
  }

  /**
   * A parent in an unmanaged menu syncs when saved, and only then.
   */
  public function testLinkInfoForParentInUnmanagedMenu(): void {
    $parent = $this->ungoverned(function (): MenuLinkContentInterface {
      $parent = MenuLinkContent::create([
        'title' => 'Footer platforms',
        'menu_name' => 'footer',
        'link' => ['uri' => 'route:<nolink>'],
        'menu_autopilot' => ['source' => ['type' => 'bundle', 'bundle' => 'page', 'sort' => 'title_asc', 'limit' => 0]],
      ]);
      $parent->save();
      return $parent;
    });

    $info = $this->linkInfo($parent->uuid());
    self::assertSame('dynamic_parent', $info['role']);
    self::assertFalse($info['menu_is_managed']);
    self::assertStringContainsString('not a managed menu', $info['effect_of_client_edit']);

    // The sentence is a claim about the base module. Hold it to it.
    $children = function () use ($parent): int {
      return (int) $this->container->get('entity_type.manager')->getStorage('menu_link_content')->getQuery()
        ->condition('parent', 'menu_link_content:' . $parent->uuid())
        ->accessCheck(FALSE)->count()->execute();
    };
    $this->ungoverned(function () use ($parent, $children): void {
      $this->createPage('Atlas');
      self::assertSame(0, $children(), 'A node change does not sync a parent in an unmanaged menu.');
      $parent->save();
      self::assertSame(1, $children(), 'Saving the parent does.');
    });
  }

  /**
   * An input the tool does not define is refused, not ignored.
   */
  public function testUnknownInputKeyIsRefused(): void {
    $tool = $this->tool('menu_autopilot_status');
    $execute = new \ReflectionMethod($tool, 'doExecute');
    $execute->setAccessible(TRUE);

    $result = $execute->invoke($tool, ['surprise_7Q' => 'value-7Q']);
    self::assertFalse($result->isSuccess());
    self::assertStringNotContainsString('7Q', (string) $result->getMessage());
    self::assertEmpty($result->getContextValues());

    self::assertTrue($execute->invoke($tool, [])->isSuccess());
  }

  /**
   * Runs fixture code as an account MCP Sentinel does not govern.
   *
   * Sentinel's presave rules apply to every save the governed account makes,
   * so fixtures written as that account would not be what the test asked for.
   */
  private function ungoverned(callable $fixtures): mixed {
    $current = $this->container->get('current_user');
    $current->setAccount(new AnonymousUserSession());
    try {
      return $fixtures();
    }
    finally {
      $current->setAccount($this->governed);
    }
  }

  /**
   * Lets the default profile write live menu links.
   *
   * The default profile denies publishing, which turns a governed edit of a
   * published menu link into a pending revision.
   */
  private function allowLivePublish(): void {
    $this->config('mcp_sentinel.mcp_policy_profile.default')
      ->set('entity_rules', ['menu_link_content' => ['allow_publish' => TRUE]])
      ->save();
  }

  /**
   * Creates one editorial-route link in the main menu and one in the footer.
   *
   * @return array
   *   The main link, the footer link and the canonical URI both should get.
   */
  private function editorialLinks(): array {
    return $this->ungoverned(function (): array {
      $node = $this->createPage('Atlas');
      $links = [];
      foreach (['main', 'footer'] as $menu) {
        $links[$menu] = MenuLinkContent::create([
          'title' => 'Atlas',
          'menu_name' => $menu,
          'link' => ['uri' => 'internal:/node/' . $node->id() . '/latest?token=query-marker-7Q'],
        ]);
        $links[$menu]->save();
      }
      return [$links['main'], $links['footer'], 'entity:node/' . $node->id()];
    });
  }

  /**
   * The normalise tool with its menus input set.
   */
  private function normalizeTool(array $menus): object {
    $tool = $this->tool('menu_autopilot_normalize_uris');
    $tool->setInputValue('menus', $menus);
    return $tool;
  }

  /**
   * Instantiates a tool plugin.
   */
  private function tool(string $id): object {
    return $this->container->get('plugin.manager.tool')->createInstance($id);
  }

  /**
   * Runs the link info tool and returns its values.
   */
  private function linkInfo(string $uuid): array {
    $tool = $this->tool('menu_autopilot_link_info');
    $tool->setInputValue('link', $uuid);
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    return $tool->getResult()->getContextValues();
  }

  /**
   * Creates a dynamic parent sourced from the page bundle.
   */
  private function createParent(array $overrides = []): MenuLinkContentInterface {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => $overrides + ['type' => 'bundle', 'bundle' => 'page', 'sort' => 'title_asc', 'limit' => 0],
      ],
    ]);
    $parent->save();
    return $parent;
  }

  /**
   * Creates a published page with a field value no tool may return.
   */
  private function createPage(string $title, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => $published,
      'field_subtitle' => self::FIELD_VALUE,
    ]);
    $node->save();
    return $node;
  }

  /**
   * The automatic child a parent holds for a node, fresh from storage.
   */
  private function managedChild(MenuLinkContentInterface $parent, Node $node): MenuLinkContentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    $storage->resetCache($ids);
    foreach ($storage->loadMultiple($ids) as $link) {
      $data = $link->get('menu_autopilot')->isEmpty() ? [] : $link->get('menu_autopilot')->first()->getValue();
      if (!empty($data['managed']) && (int) $data['node'] === (int) $node->id()) {
        return $link;
      }
    }
    self::fail('No automatic child for node ' . $node->id());
  }

  /**
   * The URI the live revision of a link holds.
   */
  private function storedUri(MenuLinkContentInterface $link): string {
    $stored = $this->container->get('entity_type.manager')->getStorage('menu_link_content')->loadUnchanged($link->id());
    return (string) $stored->get('link')->first()->getValue()['uri'];
  }

}
