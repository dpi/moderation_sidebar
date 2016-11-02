<?php

namespace Drupal\Tests\moderation_sidebar\FunctionalJavascript;

/**
 * Contains Moderation Sidebar integration tests for Workbench Moderation.
 *
 * This is done in a separate test class so that Workbench Moderation errors
 * can be easily identified during test runs.
 *
 * @group moderation_sidebar
 */
class WorkbenchModerationSidebarTest extends ModerationSidebarTest {

  /**
   * {@inheritdoc}
   */
  protected static $moderation_module = 'workbench_moderation';

}
