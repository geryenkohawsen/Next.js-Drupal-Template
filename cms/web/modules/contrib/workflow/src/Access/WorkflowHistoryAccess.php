<?php

namespace Drupal\workflow\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Entity\WorkflowRole;
use Symfony\Component\Routing\Route;

/**
 * Checks access to Workflow tab.
 */
class WorkflowHistoryAccess implements AccessInterface {

  /**
   * Check if the user has permissions to view this workflow.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current user account.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   Current routeMatch.
   * @param \Symfony\Component\Routing\Route $route
   *   Current route.
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden
   *   If the user can access to this workflow.
   */
  public function access(AccountInterface $account, RouteMatchInterface $routeMatch, Route $route) {
    static $access = [];

    $entity = workflow_url_get_entity(NULL, $routeMatch);
    if (!$entity) {
      return AccessResult::forbidden();
    }

    $entity_id = $entity->id();
    $entity_type = $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();
    // @todo Field name may be specified in URL.
    $field_name = workflow_url_get_parameter('field_name');

    $uid = ($account) ? $account->id() : -1;
    if (isset($access[$uid][$entity_type][$entity_id][$field_name ? $field_name : 'no_field'])) {
      return $access[$uid][$entity_type][$entity_id][$field_name ? $field_name : 'no_field'];
    }

    // When having multiple workflows per bundle,
    // use Views display 'Workflow history per entity' instead!
    $fields = _workflow_info_fields($entity, $entity_type, $entity_bundle, $field_name);
    if (!$fields) {
      return AccessResult::forbidden();
    }

    /*
     * Determine if user has Access. Fill the cache.
     */
    // Note: for multiple workflow_fields per bundle, use Views instead!
    // @todo Use proper 'WORKFLOW_TYPE' permissions for workflow_tab_access.
    // N.B. Keep aligned between WorkflowState, ~Transition, ~HistoryAccess.
    $access_result = AccessResult::forbidden();
    $is_owner = WorkflowRole::isOwner($account, $entity);
    foreach ($fields as $definition) {
      $type_id = $definition->getSetting('workflow_type');
      if ($account->hasPermission("access any $type_id workflow_transion overview")) {
        $access_result = AccessResult::allowed();
      }
      elseif ($is_owner && $account->hasPermission("access own $type_id workflow_transion overview")) {
        $access_result = AccessResult::allowed();
      }
      elseif ($account->hasPermission('administer nodes')) {
        $access_result = AccessResult::allowed();
      }

      $access[$uid][$entity_type][$entity_id][$field_name ? $field_name : 'no_field'] = $access_result;
    }

    return $access_result;
  }

}
