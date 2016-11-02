<?php

namespace Drupal\Tests\moderation_sidebar\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JavascriptTestBase;

/**
 * Contains Moderation Sidebar integration tests.
 *
 * @group moderation_sidebar
 */
class ModerationSidebarTest extends JavascriptTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
  ];

  /**
   * Defines the moderation module used for this test.
   *
   * @var string
   */
  protected static $moderation_module = 'content_moderation';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->container->get('module_installer')->install([self::$moderation_module, 'moderation_sidebar'], TRUE);

    // Create a Content Type with moderation enabled.
    $node_type = $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $node_type->setThirdPartySetting(self::$moderation_module, 'enabled', TRUE);
    $node_type->setThirdPartySetting(self::$moderation_module, 'allowed_moderation_states', [
      'published',
      'draft',
      'archived',
    ]);
    $node_type->setThirdPartySetting(self::$moderation_module, 'default_moderation_state', 'published');
    $node_type->setNewRevision(TRUE);
    $node_type->save();

    // Create a user who can use the Moderation Sidebar.
    $user = $this->drupalCreateUser([
      'access toolbar',
      'use moderation sidebar',
      'access content',
      'create article content',
      'edit any article content',
      'delete any article content',
      'view any unpublished content',
      'view latest version',
      'use draft_draft transition',
      'use published_published transition',
      'use draft_published transition',
      'use published_draft transition',
    ]);
    $this->drupalLogin($user);

    drupal_flush_all_caches();
  }

  /**
   * Tests that the Moderation Sidebar is working as expected.
   */
  public function testModerationSidebar() {
    // Create a new article.
    $node = $this->createNode(['type' => 'article']);
    $this->drupalGet('node/' . $node->id());

    // Open the moderation sidebar.
    $this->clickLink('Moderate');
    $this->assertSession()->assertWaitOnAjaxRequest();
    // Archived transitions should not be visible based on our permissions.
    $this->assertSession()->elementNotExists('css', '.moderation-sidebar-link#published_archived');
    // Create a draft of the article.
    $this->click('.moderation-sidebar-link#published_draft');
    $this->assertSession()->addressEquals('node/' . $node->id() . '/latest');

    // Publish the draft.
    $this->clickLink('Moderate');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextNotContains('View existing draft');
    $this->click('.moderation-sidebar-link#draft_published');
    $this->assertSession()->addressEquals('node/' . $node->id());

    // Create another draft, then discard it.
    $this->clickLink('Moderate');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->click('.moderation-sidebar-link#published_draft');
    $this->assertSession()->addressEquals('node/' . $node->id() . '/latest');
    $this->clickLink('Moderate');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->click('#moderation-sidebar-discard-draft');
    $this->assertSession()->pageTextContains('The draft has been discarded successfully');
  }

}
