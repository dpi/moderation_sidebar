<?php

namespace Drupal\Tests\moderation_sidebar\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Tests basic behaviour of Moderation Sidebar using a test entity.
 *
 * @group moderation_sidebar
 */
class ModerationSidebarTest extends BrowserTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'moderation_sidebar',
    'toolbar',
    'content_moderation',
    'workflows',
    'entity_test',
    'moderation_sidebar_access_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'entity_test_mulrevpub', 'entity_test_mulrevpub');

    $this->drupalLogin($this->createUser([
      'view test entity',
      'access toolbar',
      'access toolbar',
      'use ' . $workflow->id() . ' transition create_new_draft',
      'use ' . $workflow->id() . ' transition archive',
      'use ' . $workflow->id() . ' transition publish',
      'use moderation sidebar',
    ]));
  }

  /**
   * Test toolbar item appears.
   */
  public function testToolbarItem() {
    $entity = EntityTestMulRevPub::create([
      'name' => $this->randomMachineName(),
    ]);
    $entity->save();
    $this->drupalGet($entity->toUrl());

    // Make sure the button is where we expect it.
    $toolbarItem = $this->assertSession()->elementExists('css', '.moderation-sidebar-toolbar-tab a');
    // Make sure the button has the right attributes.
    $this->assertEquals(sprintf('/moderation-sidebar/entity_test_mulrevpub/%s/latest', $entity->id()), $toolbarItem->getAttribute('href'));
    $this->assertEquals('Tasks', $toolbarItem->getText());
  }

  /**
   * Tests altering entity operation affects visibility of toolbar item.
   */
  public function testToolbarItemAlterable() {
    $entity = EntityTestMulRevPub::create([
      'name' => $this->randomMachineName(),
    ]);
    $entity->save();

    $this->drupalGet($entity->toUrl());
    $this->assertSession()->pageTextContains($entity->label());
    // Set a baseline, make sure toolbar button exists.
    $this->assertSession()->elementExists('css', '.moderation-sidebar-toolbar-tab a');

    // There is no cache tag on State so invalidate the previously renderd page
    // manually:
    Cache::invalidateTags(['rendered']);

    // Alters operation to force forbidden, see
    // \moderation_sidebar_test_entity_access().
    $key = sprintf('moderation_sidebar_access_test_entity_access_forbidden.moderation-sidebar.%s', $entity->id());
    \Drupal::state()->set($key, TRUE);
    $this->drupalGet($entity->toUrl());
    // Assert something positive to make sure page loads.
    $this->assertSession()->pageTextContains($entity->label());
    $this->assertSession()->elementNotExists('css', '.moderation-sidebar-toolbar-tab a');
  }

}
