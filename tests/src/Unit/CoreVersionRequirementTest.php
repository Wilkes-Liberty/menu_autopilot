<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Unit;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The declared Drupal range must keep the supported floors and include 12.
 *
 * PR #8 narrowed the claim from ^10.3 || ^11 to ^10.6 || ^11.3 and added CI
 * that actually runs those floors. Adding || ^12 must not quietly reopen
 * 10.5 or 11.2, and the optional MCP submodule must not claim 12 until its
 * dependencies do.
 *
 * @group menu_autopilot
 */
final class CoreVersionRequirementTest extends TestCase {

  /**
   * Composer and the main info file must declare the same range.
   */
  public function testMainModuleDeclaresTheSupportedRange(): void {
    $expected = '^10.6 || ^11.3 || ^12';
    $this->assertSame($expected, $this->infoRequirement('menu_autopilot.info.yml'));
    $this->assertSame($expected, $this->composerCoreConstraint());
  }

  /**
   * Tool API declares ^10.5 || ^11, so the tools submodule cannot claim 12.
   */
  public function testMcpSubmoduleDoesNotClaimDrupal12(): void {
    $this->assertSame(
      '^10.6 || ^11.3',
      $this->infoRequirement('modules/menu_autopilot_mcp/menu_autopilot_mcp.info.yml'),
    );
  }

  /**
   * Supported floors and Drupal 12 satisfy the constraint; prior minors do not.
   */
  public function testConstraintKeepsFloorsAndIncludesDrupal12(): void {
    $constraint = $this->composerCoreConstraint();
    $this->assertTrue(Semver::satisfies('10.6.0', $constraint));
    $this->assertFalse(Semver::satisfies('10.5.99', $constraint));
    $this->assertTrue(Semver::satisfies('11.3.0', $constraint));
    $this->assertFalse(Semver::satisfies('11.2.99', $constraint));
    $this->assertTrue(Semver::satisfies('12.0.0', $constraint));
    $this->assertFalse(Semver::satisfies('13.0.0', $constraint));
  }

  /**
   * Reads core_version_requirement from an info.yml under the module root.
   */
  private function infoRequirement(string $relative): string {
    $path = $this->moduleRoot() . '/' . $relative;
    $this->assertFileExists($path);
    $info = Yaml::parseFile($path);
    $this->assertIsArray($info);
    $this->assertArrayHasKey('core_version_requirement', $info);
    $this->assertIsString($info['core_version_requirement']);
    return $info['core_version_requirement'];
  }

  /**
   * Reads the drupal/core Composer constraint.
   */
  private function composerCoreConstraint(): string {
    $path = $this->moduleRoot() . '/composer.json';
    $this->assertFileExists($path);
    $composer = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($composer);
    $this->assertIsString($composer['require']['drupal/core'] ?? NULL);
    return $composer['require']['drupal/core'];
  }

  /**
   * The module root, three directories above tests/src/Unit.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

}
