<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API clients cannot write or read the module's two internal fields.
 *
 * Each test sends a real request through the HTTP kernel, so routing, access
 * checking, the denormalizer and the resource's own field checks all run. The
 * account holds `administer menu`, the permission that lets it update a menu
 * link, because the question is whether that permission also reaches the
 * module-owned fields.
 *
 * @group menu_autopilot
 */
final class InternalFieldApiWriteTest extends KernelTestBase {

  use UserCreationTrait;

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
    'file',
    'serialization',
    'jsonapi',
    'rest',
    'menu_link_content',
    'menu_autopilot',
  ];

  /**
   * The link the requests target.
   */
  private MenuLinkContentInterface $link;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'jsonapi', 'menu_autopilot']);

    // JSON:API ships read-only. A site that accepts writes has turned this
    // off, and that is the site the issue is about.
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();

    // Expose menu links over core REST the way a site would.
    $this->container->get('entity_type.manager')
      ->getStorage('rest_resource_config')
      ->create([
        'id' => 'entity.menu_link_content',
        'plugin_id' => 'entity:menu_link_content',
        'granularity' => 'resource',
        'configuration' => [
          'methods' => ['GET', 'POST', 'PATCH'],
          'formats' => ['json'],
          'authentication' => ['cookie'],
        ],
      ])
      ->save();
    $this->container->get('router.builder')->rebuild();

    // Uid 1 bypasses every access check; burn it.
    $this->createUser();
    $this->setCurrentUser($this->createUser(['administer menu']));

    $this->link = MenuLinkContent::create([
      'title' => 'Plain link',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/'],
    ]);
    $this->link->save();
  }

  /**
   * A JSON:API PATCH of the map is refused and nothing is stored.
   */
  public function testJsonApiRefusesTheMap(): void {
    $response = $this->jsonApiPatch([
      'menu_autopilot' => ['managed' => TRUE, 'node' => 1],
    ]);

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], _menu_autopilot_link_data($this->reload()));
  }

  /**
   * A JSON:API PATCH of the dynamic-parent marker is refused.
   */
  public function testJsonApiRefusesTheDynamicMarker(): void {
    $response = $this->jsonApiPatch(['menu_autopilot_dynamic' => TRUE]);

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertFalse((bool) $this->reload()->get('menu_autopilot_dynamic')->value);
  }

  /**
   * A JSON:API POST that names the map creates nothing.
   */
  public function testJsonApiRefusesTheMapOnCreate(): void {
    $body = json_encode([
      'data' => [
        'type' => 'menu_link_content--menu_link_content',
        'attributes' => [
          'title' => 'Posted',
          'menu_name' => 'main',
          'link' => ['uri' => 'internal:/'],
          'menu_autopilot' => ['managed' => TRUE, 'node' => 1],
        ],
      ],
    ]);
    $response = $this->request('POST', '/jsonapi/menu_link_content/menu_link_content', $body, 'application/vnd.api+json');

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], $this->linksTitled('Posted'));
  }

  /**
   * A core REST POST that names the map creates nothing.
   */
  public function testRestRefusesTheMapOnCreate(): void {
    $body = json_encode([
      'bundle' => [['value' => 'menu_link_content']],
      'title' => [['value' => 'Posted']],
      'menu_name' => [['value' => 'main']],
      'link' => [['uri' => 'internal:/']],
      'menu_autopilot' => [['managed' => TRUE, 'node' => 1]],
    ]);
    $response = $this->request('POST', '/entity/menu_link_content?_format=json', $body, 'application/json');

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], $this->linksTitled('Posted'));
  }

  /**
   * The same account can still POST a link without the fields.
   *
   * Without this the two refusals above could come from a broken request.
   */
  public function testCreateWithoutTheFieldsStillWorks(): void {
    $body = json_encode([
      'data' => [
        'type' => 'menu_link_content--menu_link_content',
        'attributes' => [
          'title' => 'Posted',
          'menu_name' => 'main',
          'link' => ['uri' => 'internal:/'],
        ],
      ],
    ]);
    $response = $this->request('POST', '/jsonapi/menu_link_content/menu_link_content', $body, 'application/vnd.api+json');
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());

    $body = json_encode([
      'bundle' => [['value' => 'menu_link_content']],
      'title' => [['value' => 'Posted']],
      'menu_name' => [['value' => 'main']],
      'link' => [['uri' => 'internal:/']],
    ]);
    $response = $this->request('POST', '/entity/menu_link_content?_format=json', $body, 'application/json');
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());

    $this->assertCount(2, $this->linksTitled('Posted'));
  }

  /**
   * The same account can still PATCH an ordinary field over JSON:API.
   *
   * Without this the refusals above could come from a broken request rather
   * than from the field rule.
   */
  public function testJsonApiStillAcceptsAnOrdinaryField(): void {
    $response = $this->jsonApiPatch(['title' => 'Renamed']);

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('Renamed', $this->reload()->getTitle());
  }

  /**
   * A JSON:API GET does not return either field.
   */
  public function testJsonApiDoesNotReturnTheFields(): void {
    $this->link->set('menu_autopilot', ['source' => ['type' => 'bundle', 'bundle' => 'page']])->save();

    $response = $this->request('GET', '/jsonapi/menu_link_content/menu_link_content/' . $this->link->uuid(), NULL, 'application/vnd.api+json');

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $document = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('Plain link', $document['data']['attributes']['title']);
    $this->assertArrayNotHasKey('menu_autopilot', $document['data']['attributes']);
    $this->assertArrayNotHasKey('menu_autopilot_dynamic', $document['data']['attributes']);
  }

  /**
   * A core REST PATCH of either field is refused and nothing is stored.
   */
  public function testRestRefusesBothFields(): void {
    $response = $this->restPatch([
      'menu_autopilot' => [['managed' => TRUE, 'node' => 1]],
    ]);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], _menu_autopilot_link_data($this->reload()));

    $response = $this->restPatch(['menu_autopilot_dynamic' => [['value' => TRUE]]]);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertFalse((bool) $this->reload()->get('menu_autopilot_dynamic')->value);
  }

  /**
   * The same account can still PATCH an ordinary field over core REST.
   */
  public function testRestStillAcceptsAnOrdinaryField(): void {
    $response = $this->restPatch(['title' => [['value' => 'Renamed']]]);

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('Renamed', $this->reload()->getTitle());
  }

  /**
   * A core REST GET does not return either field.
   */
  public function testRestDoesNotReturnTheFields(): void {
    $this->link->set('menu_autopilot', ['source' => ['type' => 'bundle', 'bundle' => 'page']])->save();

    $response = $this->request('GET', '/admin/structure/menu/item/' . $this->link->id() . '/edit?_format=json', NULL, 'application/json');

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $document = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('Plain link', $document['title'][0]['value']);
    $this->assertArrayNotHasKey('menu_autopilot', $document);
    $this->assertArrayNotHasKey('menu_autopilot_dynamic', $document);
  }

  /**
   * Sends a JSON:API PATCH for the link with the given attributes.
   *
   * @param array $attributes
   *   The attributes member of the resource object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function jsonApiPatch(array $attributes): Response {
    $body = json_encode([
      'data' => [
        'type' => 'menu_link_content--menu_link_content',
        'id' => $this->link->uuid(),
        'attributes' => $attributes,
      ],
    ]);
    return $this->request('PATCH', '/jsonapi/menu_link_content/menu_link_content/' . $this->link->uuid(), $body, 'application/vnd.api+json');
  }

  /**
   * Sends a core REST PATCH for the link with the given fields.
   *
   * @param array $fields
   *   Field values in the serialization module's JSON shape.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function restPatch(array $fields): Response {
    $body = json_encode($fields + ['bundle' => [['value' => 'menu_link_content']]]);
    return $this->request('PATCH', '/admin/structure/menu/item/' . $this->link->id() . '/edit?_format=json', $body, 'application/json');
  }

  /**
   * Sends a request through the HTTP kernel as the current user.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   The path, with any query string.
   * @param string|null $body
   *   The request body, or NULL for none.
   * @param string $mime
   *   The media type sent as both Content-Type and Accept.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function request(string $method, string $uri, ?string $body, string $mime): Response {
    $request = Request::create($uri, $method, [], [], [], [
      'CONTENT_TYPE' => $mime,
      'HTTP_ACCEPT' => $mime,
    ], $body);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * The ids of the stored links with a given title.
   *
   * @param string $title
   *   The title to look for.
   *
   * @return array
   *   Link ids.
   */
  private function linksTitled(string $title): array {
    return array_values($this->container->get('entity_type.manager')
      ->getStorage('menu_link_content')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('title', $title)
      ->execute());
  }

  /**
   * Loads the link fresh from storage.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The stored link.
   */
  private function reload(): MenuLinkContentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache([$this->link->id()]);
    $link = $storage->load($this->link->id());
    $this->assertInstanceOf(MenuLinkContentInterface::class, $link);
    return $link;
  }

}
