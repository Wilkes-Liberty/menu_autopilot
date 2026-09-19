<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_autopilot\NavSyncManager;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Managed children that another module's presave hook disables.
 *
 * `enabled` is the published key of menu_link_content, so a module that
 * governs publishing can force a new link to disabled. The test module
 * menu_autopilot_disable_test does that for the account ids in a state key.
 *
 * @group menu_autopilot
 * @coversDefaultClass \Drupal\menu_autopilot\NavSyncManager
 */
final class DisabledBySaveChildTest extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'link',
    'menu_link_content',
    'menu_autopilot',
    'menu_autopilot_disable_test',
  ];

  /**
   * Log records written to the menu_autopilot channel.
   *
   * @var array<int, array{level: int, message: string}>
   */
  private array $records = [];

  /**
   * An account whose menu link saves are forced to disabled.
   */
  private User $restricted;

  /**
   * A second account whose menu link saves are forced to disabled.
   */
  private User $alsoRestricted;

  /**
   * An account whose menu link saves are left alone.
   */
  private User $privileged;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // The first account is uid 1. Keep it out of the way so no assertion
    // depends on the superuser.
    User::create(['name' => 'root'])->save();
    $this->restricted = User::create(['name' => 'restricted']);
    $this->restricted->save();
    $this->alsoRestricted = User::create(['name' => 'also_restricted']);
    $this->alsoRestricted->save();
    $this->privileged = User::create(['name' => 'privileged']);
    $this->privileged->save();

    $this->container->get('state')->set('menu_autopilot_disable_test.restricted_uids', [
      (int) $this->restricted->id(),
      (int) $this->alsoRestricted->id(),
    ]);
    $this->container->get('logger.factory')->addLogger($this);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (($context['channel'] ?? '') !== 'menu_autopilot') {
      return;
    }
    $placeholders = $this->container->get('logger.log_message_parser')
      ->parseMessagePlaceholders($message, $context);
    $this->records[] = [
      'level' => (int) $level,
      'message' => strtr((string) $message, array_map('strval', $placeholders)),
    ];
  }

  /**
   * A child the save left disabled is logged and flagged.
   *
   * @covers ::syncNode
   */
  public function testCreatedDisabledChildIsLoggedAndFlagged(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);

    $node = $this->createPage('Atlas');

    $child = $this->onlyChildOf($parent);
    $this->assertFalse($child->isEnabled(), 'The presave hook disabled the new child.');
    $data = $this->dataOf($child);
    $this->assertTrue($data['managed']);
    $this->assertSame((int) $node->id(), (int) $data['node']);
    $this->assertTrue($data['disabled_by_save'], 'The map records that the save, not an editor, disabled the link.');

    $warnings = $this->recordsAt(RfcLogLevel::WARNING);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('Platforms', $warnings[0]);
    $this->assertStringContainsString('node ' . $node->id(), $warnings[0]);
    $this->assertStringContainsString('uid ' . $this->restricted->id(), $warnings[0]);
  }

  /**
   * An enabled child writes no warning and carries no flag.
   *
   * @covers ::syncNode
   */
  public function testEnabledChildIsNotFlagged(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);

    $this->createPage('Atlas');

    $child = $this->onlyChildOf($parent);
    $this->assertTrue($child->isEnabled());
    $this->assertArrayNotHasKey('disabled_by_save', $this->dataOf($child));
    $this->assertSame([], $this->recordsAt(RfcLogLevel::WARNING));
  }

  /**
   * A later sync by an account that may enable links heals the child.
   *
   * @covers ::reconcile
   */
  public function testPrivilegedSyncEnablesFlaggedChildAndClearsFlag(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $this->createPage('Atlas');
    $id = (int) $this->onlyChildOf($parent)->id();

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();

    $child = $this->onlyChildOf($parent);
    $this->assertSame($id, (int) $child->id(), 'The same link is enabled, not replaced.');
    $this->assertTrue($child->isEnabled());
    $data = $this->dataOf($child);
    $this->assertArrayNotHasKey('disabled_by_save', $data);
    $this->assertTrue($data['managed']);
  }

  /**
   * A sync whose save is disabled again keeps the flag for the next one.
   *
   * @covers ::reconcile
   */
  public function testFailedEnableKeepsTheFlag(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $this->createPage('Atlas');

    $this->actAs($this->alsoRestricted);
    $this->resetSaveCount();
    $this->syncManager()->reconcile();

    $child = $this->onlyChildOf($parent);
    $this->assertFalse($child->isEnabled());
    $this->assertTrue($this->dataOf($child)['disabled_by_save']);
    $this->assertSame(1, $this->saveCount(), 'One attempt to enable, and no second save.');

    // The account that failed does not try again in the same request.
    $this->resetSaveCount();
    $this->syncManager()->reconcile();
    $this->assertSame(0, $this->saveCount());

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $this->assertTrue($this->onlyChildOf($parent)->isEnabled());
  }

  /**
   * Renaming the node in the request that created the child does not loop.
   *
   * @covers ::syncNode
   */
  public function testSameRequestRenameDoesNotLoop(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $node = $this->createPage('Atlas');

    $this->resetSaveCount();
    $node->setTitle('Atlas Two')->save();

    $child = $this->onlyChildOf($parent);
    $this->assertSame('Atlas Two', $child->getTitle());
    $this->assertFalse($child->isEnabled());
    $this->assertTrue($this->dataOf($child)['disabled_by_save']);
    $this->assertSame(1, $this->saveCount(), 'The rename is the only save: no enable attempt, no repeat.');
    $this->assertCount(1, $this->recordsAt(RfcLogLevel::WARNING), 'The warning is written once, when the child is created.');
  }

  /**
   * A link an editor disabled is never enabled by a sync.
   *
   * @covers ::reconcile
   */
  public function testEditorDisabledChildStaysDisabled(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);
    $node = $this->createPage('Atlas');

    $child = $this->onlyChildOf($parent);
    $child->set('enabled', FALSE)->save();

    $this->syncManager()->reconcile();
    $node->setTitle('Atlas Two')->save();

    $child = $this->onlyChildOf($parent);
    $this->assertSame('Atlas Two', $child->getTitle(), 'The sync still mirrors the title.');
    $this->assertFalse($child->isEnabled());
    $this->assertArrayNotHasKey('disabled_by_save', $this->dataOf($child));
  }

  /**
   * An editor who enables, then disables, a flagged link has the last word.
   *
   * @covers ::clearStaleDisabledBySaveFlag
   */
  public function testEditorDisableAfterEnableIsKept(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $this->createPage('Atlas');

    $this->actAs($this->privileged);
    // The menu overview form saves the link directly; no sync runs.
    $child = $this->onlyChildOf($parent);
    $child->set('enabled', TRUE)->save();
    $child = $this->onlyChildOf($parent);
    $this->assertTrue($child->isEnabled());
    $child->set('enabled', FALSE)->save();

    $this->assertArrayNotHasKey('disabled_by_save', $this->dataOf($this->onlyChildOf($parent)), 'The flag is dropped once the link has been enabled.');

    $this->syncManager()->reconcile();
    $this->assertFalse($this->onlyChildOf($parent)->isEnabled());
  }

  /**
   * Dropping a stale flag is itself a save another module can disable.
   *
   * @covers ::syncNode
   */
  public function testDroppingStaleFlagKeepsDisabledLinkMarked(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $node = $this->createPage('Atlas');

    // An editor enables the link on the overview form. The flag is still set.
    $this->actAs($this->privileged);
    $this->onlyChildOf($parent)->set('enabled', TRUE)->save();
    $this->assertTrue($this->dataOf($this->onlyChildOf($parent))['disabled_by_save']);

    // The next sync runs as a restricted account, and its save is disabled.
    $this->actAs($this->alsoRestricted);
    $node->setTitle('Atlas Two')->save();

    $child = $this->onlyChildOf($parent);
    $this->assertFalse($child->isEnabled());
    $this->assertTrue($this->dataOf($child)['disabled_by_save'], 'The link is still marked for a later sync to enable.');

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $this->assertTrue($this->onlyChildOf($parent)->isEnabled());
  }

  /**
   * Saving the link form with Enabled unchecked makes it the editor's choice.
   */
  public function testLinkFormSaveRecordsTheEditorsChoice(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->restricted);
    $this->createPage('Atlas');
    $child = $this->onlyChildOf($parent);

    $form = $this->container->get('entity.form_builder')->getForm($child);
    $this->assertContains('_menu_autopilot_managed_link_form_builder', $form['#entity_builders']);
    $this->assertStringContainsString('disabled', (string) $form['menu_autopilot_managed']['#markup']);

    // Enabled checked: the editor wants it on, so a failed save keeps the flag.
    $checked = (new FormState())->setValue('enabled', ['value' => 1]);
    $child->set('enabled', TRUE);
    $form = [];
    _menu_autopilot_managed_link_form_builder('menu_link_content', $child, $form, $checked);
    $this->assertTrue($this->dataOf($child)['disabled_by_save']);

    // Enabled unchecked: the editor chose disabled.
    $unchecked = (new FormState())->setValue('enabled', ['value' => 0]);
    $child->set('enabled', FALSE);
    _menu_autopilot_managed_link_form_builder('menu_link_content', $child, $form, $unchecked);
    $data = $this->dataOf($child);
    $this->assertArrayNotHasKey('disabled_by_save', $data);
    $this->assertTrue($data['managed'], 'The rest of the map is kept.');
    $child->save();

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $this->assertFalse($this->onlyChildOf($parent)->isEnabled());
  }

  /**
   * A sync update that disables an enabled child is logged and flagged too.
   *
   * Adding a node re-weights its siblings, and those saves run as the acting
   * account as well.
   *
   * @covers ::syncNode
   */
  public function testUpdateThatDisablesAnEnabledChildIsFlaggedAndHealed(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);
    $this->createPage('Beacon');
    $this->assertTrue($this->onlyChildOf($parent)->isEnabled());

    // Atlas sorts first, so Beacon is re-weighted by the restricted account.
    $this->actAs($this->restricted);
    $this->createPage('Atlas');

    $children = $this->childrenByTitle($parent);
    $this->assertFalse($children['Beacon']->isEnabled());
    $this->assertTrue($this->dataOf($children['Beacon'])['disabled_by_save']);
    $warnings = $this->recordsAt(RfcLogLevel::WARNING);
    $this->assertCount(2, $warnings, 'One warning for the new child, one for the re-weighted one.');
    $this->assertStringContainsString('Beacon', $warnings[1]);

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $children = $this->childrenByTitle($parent);
    $this->assertTrue($children['Atlas']->isEnabled());
    $this->assertTrue($children['Beacon']->isEnabled());
    $this->assertArrayNotHasKey('disabled_by_save', $this->dataOf($children['Beacon']));
  }

  /**
   * A sync update never flags a child an editor had already disabled.
   *
   * @covers ::syncNode
   */
  public function testUpdateOfAnEditorDisabledChildIsNotFlagged(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);
    $this->createPage('Beacon');
    $this->onlyChildOf($parent)->set('enabled', FALSE)->save();

    $this->actAs($this->restricted);
    $this->createPage('Atlas');

    $borealis = $this->childrenByTitle($parent)['Beacon'];
    $this->assertSame(1, (int) $borealis->getWeight(), 'The update ran.');
    $this->assertArrayNotHasKey('disabled_by_save', $this->dataOf($borealis));

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $this->assertFalse($this->childrenByTitle($parent)['Beacon']->isEnabled());
  }

  /**
   * Adopting an enabled hand-made link is checked like any other sync save.
   *
   * @covers ::syncParent
   */
  public function testAdoptionThatDisablesAnEnabledLinkIsFlagged(): void {
    $this->actAs($this->privileged);
    $node = $this->createPage('Atlas');
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();
    $hand_made = MenuLinkContent::create([
      'title' => 'Atlas',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $node->id()],
    ]);
    $hand_made->save();
    $this->assertTrue($hand_made->isEnabled());

    $this->actAs($this->restricted);
    $parent->set('menu_autopilot', [
      'source' => ['type' => 'bundle', 'bundle' => 'page', 'sort' => 'title_asc', 'limit' => 0],
    ]);
    $parent->save();

    $child = $this->onlyChildOf($parent);
    $this->assertSame((int) $hand_made->id(), (int) $child->id(), 'The hand-made link was adopted.');
    $this->assertFalse($child->isEnabled());
    $data = $this->dataOf($child);
    $this->assertTrue($data['managed']);
    $this->assertTrue($data['disabled_by_save']);
    $this->assertCount(1, $this->recordsAt(RfcLogLevel::WARNING));

    $this->actAs($this->privileged);
    $this->syncManager()->reconcile();
    $this->assertTrue($this->onlyChildOf($parent)->isEnabled());
  }

  /**
   * Disabled managed children are reported, with the reason.
   *
   * @covers ::disabledManagedChildren
   */
  public function testDisabledManagedChildrenAreReported(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);
    $by_editor = $this->createPage('Beacon');
    $this->createPage('Comet');
    $this->childrenByTitle($parent)['Beacon']->set('enabled', FALSE)->save();
    // Dune sorts last, so the restricted account saves no other child.
    $this->actAs($this->restricted);
    $by_hook = $this->createPage('Dune');

    $rows = $this->syncManager()->disabledManagedChildren();
    $this->assertCount(2, $rows, 'The enabled child is not listed.');
    $by_node = array_column($rows, NULL, 'node');
    $this->assertTrue($by_node[(int) $by_hook->id()]['disabled_by_save']);
    $this->assertFalse($by_node[(int) $by_editor->id()]['disabled_by_save']);
    $this->assertSame('Dune', $by_node[(int) $by_hook->id()]['title']);
    $this->assertSame('Platforms', $by_node[(int) $by_hook->id()]['parent_title']);
    $this->assertSame((int) $parent->id(), $by_node[(int) $by_hook->id()]['parent_id']);

    $this->assertCount(2, $this->syncManager()->disabledManagedChildren($parent));
    $other = MenuLinkContent::create([
      'title' => 'Other',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $other->save();
    $this->assertSame([], $this->syncManager()->disabledManagedChildren($other));

    // The parent's form names them.
    $form = $this->container->get('entity.form_builder')->getForm($this->reload($parent));
    $summary = (string) $this->container->get('renderer')->renderRoot($form['menu_autopilot']['disabled_children']);
    $this->assertStringContainsString('Dune', $summary);
    $this->assertStringContainsString('Beacon', $summary);
    $this->assertStringNotContainsString('Comet', $summary);
  }

  /**
   * A parent with no disabled children shows no summary.
   */
  public function testParentFormHasNoSummaryWhenNothingIsDisabled(): void {
    $parent = $this->createDynamicParent();
    $this->actAs($this->privileged);
    $this->createPage('Atlas');

    $form = $this->container->get('entity.form_builder')->getForm($this->reload($parent));
    $this->assertArrayNotHasKey('disabled_children', $form['menu_autopilot']);
  }

  /**
   * The sync manager.
   */
  private function syncManager(): NavSyncManager {
    return $this->container->get('menu_autopilot.sync_manager');
  }

  /**
   * Makes an account the acting account.
   */
  private function actAs(User $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Rendered messages logged at one level.
   *
   * @return string[]
   *   The messages.
   */
  private function recordsAt(int $level): array {
    $messages = [];
    foreach ($this->records as $record) {
      if ($record['level'] === $level) {
        $messages[] = $record['message'];
      }
    }
    return $messages;
  }

  /**
   * Menu link saves counted by the test module since the last reset.
   */
  private function saveCount(): int {
    return (int) $this->container->get('state')->get('menu_autopilot_disable_test.saves', 0);
  }

  /**
   * Resets the test module's save counter.
   */
  private function resetSaveCount(): void {
    $this->container->get('state')->set('menu_autopilot_disable_test.saves', 0);
  }

  /**
   * Creates a dynamic parent sourced from the page bundle.
   */
  private function createDynamicParent(): MenuLinkContentInterface {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => ['type' => 'bundle', 'bundle' => 'page', 'sort' => 'title_asc', 'limit' => 0],
      ],
    ]);
    $parent->save();
    return $parent;
  }

  /**
   * Creates a published page.
   */
  private function createPage(string $title): Node {
    $node = Node::create(['type' => 'page', 'title' => $title, 'status' => TRUE]);
    $node->save();
    return $node;
  }

  /**
   * Reloads a link from storage.
   */
  private function reload(MenuLinkContentInterface $link): MenuLinkContentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache([$link->id()]);
    $reloaded = $storage->load($link->id());
    $this->assertInstanceOf(MenuLinkContentInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * The children of a parent, fresh from storage.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links keyed by entity id.
   */
  private function childrenOf(MenuLinkContentInterface $parent): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    $storage->resetCache($ids);
    return $storage->loadMultiple($ids);
  }

  /**
   * The children of a parent, keyed by title.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links keyed by title.
   */
  private function childrenByTitle(MenuLinkContentInterface $parent): array {
    $children = [];
    foreach ($this->childrenOf($parent) as $link) {
      $children[$link->getTitle()] = $link;
    }
    return $children;
  }

  /**
   * The single child of a parent.
   */
  private function onlyChildOf(MenuLinkContentInterface $parent): MenuLinkContentInterface {
    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    return reset($children);
  }

  /**
   * The `menu_autopilot` map on a link.
   */
  private function dataOf(MenuLinkContentInterface $link): array {
    return $link->get('menu_autopilot')->isEmpty()
      ? []
      : $link->get('menu_autopilot')->first()->getValue();
  }

}
