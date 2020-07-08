<?php

namespace Drupal\moderation_sidebar;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Entity related hooks for Moderation Sidebar module.
 */
class ModerationSidebarEntityHooks {

  /**
   * Implements hook_entity_access().
   *
   * @see \moderation_sidebar_entity_access()
   */
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account) {
    // Proxies an entitys 'moderation-sidebar' operation to 'view' operation.
    if ($operation === 'moderation-sidebar') {
      return $entity->access('view', $account, TRUE);
    }
    return AccessResult::neutral();
  }

}
