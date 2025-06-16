<?php

namespace Drupal\workflow_access\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\workflow\Entity\WorkflowInterface;
use Drupal\workflow\Entity\WorkflowRole;
use Drupal\workflow\Entity\WorkflowState;
use Drupal\workflow\Entity\WorkflowTargetEntity;
use Drupal\workflow_access\Entity\WorkflowAccessState;
use Drupal\workflow_access\Form\WorkflowAccessSettingsForm;

/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 *
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowAccessHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    $output = '';

    switch ($route_name) {
      case 'entity.workflow_type.access_form':
        $url = Url::fromRoute('workflow.access.settings');
        $output .= t(
          'This page lets you refine the permissions per role and per
         workflow state. Although the workflow module allows you to add multiple
         workflows to per entity type, Workflow Access supports only one
         workflow per entity type.'
        );
        $output .= "<br>";
        $output .= t(
          "WARNING: Use of the 'Edit any', 'Edit own', and even 'View
         published content' permissions for the content type may override these
         access settings. You may disable those permissions or
         <a href=':url'>alter the priority of
        the Workflow access module</a>.", [':url' => $url->toString()]
        );
        if (\Drupal::moduleHandler()->moduleExists('og')) {
          // @todo D8: FIXME when OG module is ported.
          $output .= '<br>';
          $output .= t(
            'WARNING: Organic Groups (OG) is present and may interfere
          with these settings.'
          );
          // $output .= ' ';
          // $url = Url::fromUri('admin/config/group/settings');
          // $output .= t("In particular, if <a href=':url'>Strict node access
          //  permissions</a> is enabled, since this may override Workflow access
          //  settings.", [':url' => $url]);
          $output .= t(
            'In particular, if <i>Strict node access permissions</i> is enabled,
           since this may override Workflow access settings.');
        }
        break;

      default:
        break;
    }
    return $output;
  }

  /**
   * Implements hook_node_access_explain().
   *
   * This is a Devel Node Access hook.
   */
  #[Hook('node_access_explain')]
  public function nodeAccessExplain($row) {
    static $interpretations = [];
    switch ($row->realm) {
      case 'workflow_access_owner':
        $interpretations[$row->gid] = t(
          'Workflow access: author of the content may access'
        );
        break;

      case 'workflow_access':
        $roles = WorkflowRole::loadMultiple();
        $interpretations[$row->gid] = t(
          'Workflow access: %role may access', ['%role' => $roles[$row->gid]]
        );
        break;
    }
    return (!empty($interpretations[$row->gid]) ? $interpretations[$row->gid] : NULL);
  }

  /**
   * Implements hook_node_access_records().
   *
   * Returns a list of grant records for the passed in node object.
   * Invoked by NodeAccessControlHandler->acquireGrants(), node_access_rebuild().
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node) {
    $grants = [];

    // Only relevant for content with Workflow.
    if (!$workflow_field_names = workflow_allowed_field_names($node)) {
      return $grants;
    }

    // Create grants for each translation of the node.
    $priority = WorkflowAccessSettingsForm::getSetting('workflow_access_priority');
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $translation = $node->getTranslation($langcode);

      // @todo How to handle not published entities?
      // if (!$translation->isPublished()) {
      //   return;
      // }

      // Get 'author' of this entity. Some entities (e.g., taxonomy_term)
      // do not have a uid. But then again: node_access is only for nodes...
      /** @var \Drupal\node\NodeInterface $translation */
      $uid = $translation->getOwnerId() ?? 0;

      foreach ($workflow_field_names as $field_name => $label) {
        if (!$current_sid = workflow_node_current_state($translation, $field_name)) {
          continue;
        }

        $access_state = new WorkflowAccessState(['id' => $current_sid]);
        foreach ($access_state->readAccess() as $rid => $grant) {
          // Anonymous ($uid == 0) author is not allowed for role 'Author' (== -1).
          // Both logically (Anonymous having more rights then authenticated)
          // and technically ($gid must be a positive integer).
          if ($uid == 0 && $rid == WorkflowRole::AUTHOR_RID) {
            continue;
          }

          $grants[] = [
            'realm' => ($uid > 0 && $rid == WorkflowRole::AUTHOR_RID)
              ? 'workflow_access_owner' : 'workflow_access',
            'gid' => ($uid > 0 && $rid == WorkflowRole::AUTHOR_RID)
              ? $uid : $this->getRoleGid($rid),
            'grant_view' => (int) $grant['grant_view'],
            'grant_update' => (int) $grant['grant_update'],
            'grant_delete' => (int) $grant['grant_delete'],
            'priority' => $priority,
            'langcode' => $langcode,
            'field_name' => $field_name, // Just for analysis and info.
          ];
        }
      }
    }

    return $grants;
  }

  /**
   * Implements hook_node_grants().
   *
   * Supply the workflow access grants. We are simply using
   * roles as access lists, so rids translate directly to gids.
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, $op) {
    $gids = [];
    $roles = $account->getRoles();
    foreach ($roles as $role) {
      $gids[] = $this->getRoleGid($role);
    }

    return [
      'workflow_access' => $gids,
      'workflow_access_owner' => [$account->id()],
    ];
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for 'user_role'.
   *
   * We use the Role weight as an id.
   * In contrary to content_access module, that uses a 'content_access_roles_gids'
   * config setting.
   *
   * @todo Determine the best way for D8. @see content_access.module.
   * The problem is that node_access table uses Int, whereas the Role Id is string.
   */
  #[Hook('ENTITY_TYPE_insert')]
  public function userRoleInsert(EntityInterface $entity) {
    // Attend user to Rebuild data, because the weight of a role
    // is the key for workflow_Access.
    /** @var \Drupal\user\RoleInterface $entity */
    node_access_needs_rebuild(TRUE);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for 'user_role'.
   */
  #[Hook('ENTITY_TYPE_update')]
  public function userRoleUpdate(EntityInterface $entity) {
    // Attend user to Rebuild data, because the weight of a role
    // is the key for workflow_Access.
    /** @var \Drupal\user\RoleInterface $entity */
    $original_entity = WorkflowTargetEntity::getOriginal($entity);
    if (!$original_entity) {
      return;
    }
    if ($entity->getWeight() != $original_entity->getWeight()) {
      // Role's weight has changed.
      node_access_needs_rebuild(TRUE);
    }
  }

  /**
   * Implements hook_workflow_operations().
   */
  #[Hook('workflow_operations')]
  public function workflowOperations($op, ?EntityInterface $entity = NULL) {
    // @todo Create action link for AccessRoleForm on WorkflowListBuilder.
    $operations = [];

    switch (TRUE) {
      case $entity instanceof WorkflowInterface:
        /** @var \Drupal\workflow\Entity\WorkflowInterface $entity */
        $wid = $entity->id();
        break;

      case $entity instanceof WorkflowState:
        /** @var \Drupal\workflow\Entity\WorkflowState $entity */
        $wid = $entity->getWorkflowId();
        break;
    }

    $operations['workflow_access'] = [
      'title' => t('Access'),
      // $alt = t('Control content access for @wf', ['@wf' => $label]);
      // $attributes = ['alt' => $alt, 'title' => $alt];
      'weight' => 50,
      'url' => Url::fromRoute('entity.workflow_type.access_form', ['workflow_type' => $wid]),
      'query' => \Drupal::destination()->getAsArray(),
    ];

    return $operations;
  }

  /**
   * Helper providing numeric id for role.
   *
   * Copied from contrib content_access.module.
   */
  private function getRoleGid($rid) {
    // @todo D11: move to WorkflowRole class.
    // @todo D8: compare with content_access module.
    //  $config = \Drupal::configFactory()->getEditable('content_access.settings');
    //  $roles_gids = $config->get('content_access_roles_gids');
    //  return $roles_gids[$role];
    //
    // Return a weight, avoiding negative values by starting with 100.
    // For 'Author', no role exists.
    /** @var \Drupal\user\RoleInterface $role */
    $role = WorkflowRole::load($rid);
    $weight = $role ? 100 + $role->getWeight() : 100 - 20;

    return $weight;
  }

}
