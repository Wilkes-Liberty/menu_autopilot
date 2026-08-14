<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Resolves an "automatic children" source descriptor to published node IDs.
 *
 * A source descriptor is a small array stored on a parent menu link:
 * @code
 *   [
 *     'type' => 'term'|'bundle'|'manual'|'none',
 *     'term' => (int) taxonomy term id,      // type = term
 *     'reference_field' => (string) node field that references the term,
 *     'bundle' => (string) content type,     // type = bundle (and optional term filter)
 *     'nodes' => (int[]) ordered node ids,   // type = manual
 *     'existing_children' => 'adopt'|'adopt_prune'|'add'|'replace',
 *                            // how to treat unmanaged children already under
 *                            // the parent. Consumed by NavSyncManager.
 *     'reparent_matches' => (bool) move unmanaged matches from elsewhere
 *                            in the same menu under this parent.
 *     'sort' => 'title_asc'|'title_desc'|'created_desc'|'created_asc'|'manual',
 *     'limit' => (int) 0 for unlimited,
 *     'title_pattern' => (string) optional token pattern for child link titles
 *                        (e.g. '[node:title]'); empty = use the node label.
 *                        Consumed by NavSyncManager, not the resolver.
 *   ]
 * @endcode
 */
final class NavSourceResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolve a source descriptor to an ordered list of published node IDs.
   *
   * @param array $source
   *   The source descriptor.
   *
   * @return int[]
   *   Ordered, de-duplicated, published node IDs.
   */
  public function resolve(array $source): array {
    return match ($source['type'] ?? 'none') {
      'manual' => $this->resolveManual($source['nodes'] ?? []),
      'term', 'bundle' => $this->resolveQuery($source),
      default => [],
    };
  }

  /**
   * Resolve an explicit, ordered node list to its published members.
   */
  private function resolveManual(array $nodeIds): array {
    $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
    if ($nodeIds === []) {
      return [];
    }
    $published = array_map('intval', $this->baseQuery()
      ->condition('nid', $nodeIds, 'IN')
      ->execute());
    // Preserve the editor's chosen order; keep only published nodes.
    return array_values(array_filter(
      $nodeIds,
      static fn (int $id): bool => in_array($id, $published, TRUE),
    ));
  }

  /**
   * Resolve a term- or bundle-based source.
   */
  private function resolveQuery(array $source): array {
    $query = $this->baseQuery();

    if (($source['type'] ?? '') === 'term') {
      $field = (string) ($source['reference_field'] ?? '');
      $term = (int) ($source['term'] ?? 0);
      if ($field === '' || $term === 0) {
        return [];
      }
      $query->condition($field, $term);
      if (!empty($source['bundle'])) {
        $query->condition('type', $source['bundle']);
      }
    }
    else {
      $bundle = (string) ($source['bundle'] ?? '');
      if ($bundle === '') {
        return [];
      }
      $query->condition('type', $bundle);
    }

    $this->applySort($query, (string) ($source['sort'] ?? 'title_asc'));
    $limit = (int) ($source['limit'] ?? 0);
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * A published-nodes entity query with access checks disabled.
   *
   * Menu visibility is governed by the generated links (and the menu tree's own
   * access checks), not by the current user; the resolver only ever considers
   * published content.
   */
  private function baseQuery(): QueryInterface {
    return $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE);
  }

  /**
   * Apply a sort key to the query.
   */
  private function applySort(QueryInterface $query, string $sort): void {
    [$field, $direction] = match ($sort) {
      'title_desc' => ['title', 'DESC'],
      'created_desc' => ['created', 'DESC'],
      'created_asc' => ['created', 'ASC'],
      default => ['title', 'ASC'],
    };
    $query->sort($field, $direction);
  }

}
