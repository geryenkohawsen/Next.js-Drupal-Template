<?php

namespace Drupal\workflow\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Controller\WorkflowTransitionListController;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowScheduledTransition;
use Drupal\workflow\Entity\WorkflowTargetEntity;
use Drupal\workflow\Entity\WorkflowTransition;
use Drupal\workflow\WorkflowPermissions;

/**
 * Contains Entity hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowEntityHooks {

  /**
   * The datetime.time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $timeService;

  /**
   * Initializes the services required.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time_service
   *   The datetime.time service.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_plugin_manager
   *   The migration manager.
   */
  public function __construct(TimeInterface $time_service) {
    $this->timeService = $time_service;
  }

  /**
   * Implements hook_cron().
   *
   * Given a time frame, execute all scheduled transitions.
   */
  #[Hook('cron')]
  public function cron() {
    $this->executeScheduledTransitionsBetween(0, $this->timeService->getRequestTime());
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity) {
    // Execute/save the transitions from the widgets in the entity form.
    $this->executeTransitionsOfEntity($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity) {
    // Execute/save the transitions from the widgets in the entity form.
    $this->executeTransitionsOfEntity($entity);
  }

  /**
   * Implements hook_entity_delete().
   *
   * Deletes the corresponding workflow table records.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity) {
    // @todo Test with multiple workflows.
    switch (TRUE) {
      case $entity::class == 'Drupal\field\Entity\FieldConfig':
      case $entity::class == 'Drupal\field\Entity\FieldStorageConfig':
        // A Workflow field is removed from an entity.
        $field_config = $entity;
        /** @var \Drupal\Core\Entity\ContentEntityBase $field_config */
        $entity_type_id = (string) $field_config->get('entity_type');
        $field_name = (string) $field_config->get('field_name');
        /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
        foreach (WorkflowScheduledTransition::loadMultipleByProperties($entity_type_id, [], [], $field_name) as $transition) {
          $transition->delete();
        }
        $this->deleteTransitionsOfEntity($entity, 'workflow_transition', $field_name);
        foreach (WorkflowTransition::loadMultipleByProperties($entity_type_id, [], [], $field_name) as $transition) {
          $transition->delete();
        }
        break;

      case WorkflowTargetEntity::isWorkflowEntityType($entity->getEntityTypeId()):
        // A Workflow entity.
        break;

      default:
        // A 'normal' entity is deleted.
        foreach ($fields = _workflow_info_fields($entity) as $field_id => $field_storage) {
          $field_name = $field_storage->getName();
          /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
          $this->deleteTransitionsOfEntity($entity, 'workflow_scheduled_transition', $field_name);
          $this->deleteTransitionsOfEntity($entity, 'workflow_transition', $field_name);
        }
        break;
    }
  }

  /**
   * Implements hook_entity_operation for workflow_transition.
   *
   * Core hooks: Change the operations column in a Entity list.
   * Adds a 'revert' operation.
   *
   * @see EntityListBuilder::getOperations()
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity) {
    $operations = [];

    // Check correct entity type.
    if (in_array($entity->getEntityTypeId(), ['workflow_transition'])) {
      /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $entity */
      $operations = WorkflowTransitionListController::addRevertOperation($entity);
    }

    return $operations;
  }

  /**
   * Implements hook_user_cancel().
   *
   * Implements deprecated workflow_update_workflow_transition_history_uid().
   *
   * " When cancelling the account
   * " - Disable the account and keep its content.
   * " - Disable the account and unpublish its content.
   * " - Delete the account and make its content belong to the Anonymous user.
   * " - Delete the account and its content.
   * "This action cannot be undone.
   *
   * @param $edit
   *   Not used.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param string $method
   *   The cancellation method.
   *
   * Updates tables for deleted account, move account to user 0 (anon.)
   * ALERT: This may cause previously non-Anonymous posts to suddenly
   * be accessible to Anonymous.
   *
   * @see hook_user_cancel()
   */
  #[Hook('user_cancel')]
  public function userCancel($edit, AccountInterface $account, $method) {
    switch ($method) {
      case 'user_cancel_block':
      // Disable the account and keep its content.
      case 'user_cancel_block_unpublish':
        // Disable the account and unpublish its content.
        // Do nothing.
        break;

      case 'user_cancel_reassign':
      // Delete the account and make its content belong to the Anonymous user.
      case 'user_cancel_delete':
        // Delete the account and its content.
        /*
         * Update tables for deleted account, move account to user 0 (anon.)
         * ALERT: This may cause previously non-Anonymous posts to suddenly
         * be accessible to Anonymous.
         */

        /*
         * Given a user id, re-assign history to the new user account.
         * Called by user_delete().
         */
        $uid = $account->id();
        $new_uid = 0;
        $database = \Drupal::database();
        $database->update('workflow_transition_history')
          ->fields(['uid' => $new_uid])
          ->condition('uid', $uid, '=')
          ->execute();
        $database->update('workflow_transition_schedule')
          ->fields(['uid' => $new_uid])
          ->condition('uid', $uid, '=')
          ->execute();
        break;
    }
  }

  /**
   * Implements hook_user_delete().
   *
   * @todo Hook hook_user_delete does not exist. hook_ENTITY_TYPE_delete?
   */
  #[Hook('user_delete')]
  public function userDelete($account) {
    $this->userCancel([], $account, 'user_cancel_delete');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for 'workflow_type'.
   *
   * Is called when adding a new Workflow type.
   */
  #[Hook('workflow_type_insert')]
  public function workflowTypeInsert(EntityInterface $entity) {
    $permissions_manager = new WorkflowPermissions();
    $permissions_manager->changeRolePermissions($entity, TRUE);
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for 'workflow_type'.
   *
   * Is called when deleting a new Workflow type.
   */
  #[Hook('workflow_type_predelete')]
  public function workflowTypePredelete(EntityInterface $entity) {
    $permissions_manager = new WorkflowPermissions();
    $permissions_manager->changeRolePermissions($entity, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public static function deleteTransitionsOfEntity(EntityInterface $entity, $transition_type, $field_name, $langcode = '') {
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = $entity->id();

    switch ($transition_type) {
      case 'workflow_transition':
        foreach (WorkflowTransition::loadMultipleByProperties($entity_type_id, [$entity_id], [], $field_name, $langcode, NULL, 'ASC', $transition_type) as $transition) {
          $transition->delete();
        }
        break;

      case 'workflow_scheduled_transition':
        foreach (WorkflowScheduledTransition::loadMultipleByProperties($entity_type_id, [$entity_id], [], $field_name, $langcode, NULL, 'ASC', $transition_type) as $transition) {
          $transition->delete();
        }
        break;
    }
  }

  /**
   * Given a time frame, execute all scheduled transitions.
   *
   * Called by hook_cron().
   *
   * @param int $start
   *   The start time in unix timestamp.
   * @param int $end
   *   The end time in unix timestamp.
   */
  public static function executeScheduledTransitionsBetween($start = 0, $end = 0) {
    $clear_cache = FALSE;

    // If the time now is greater than the time to execute a transition, do it.
    foreach (WorkflowScheduledTransition::loadBetween($start, $end) as $scheduled_transition) {
      $entity = $scheduled_transition->getTargetEntity();
      // Make sure transition is still valid: the entity must still be in
      // the state it was in, when the transition was scheduled.
      if (!$entity) {
        continue;
      }

      $field_name = $scheduled_transition->getFieldName();
      $from_sid = $scheduled_transition->getFromSid();
      $current_sid = workflow_node_current_state($entity, $field_name);
      if (!$current_sid || ($current_sid != $from_sid)) {
        // Entity is not in the same state it was when the transition
        // was scheduled. Defer to the entity's current state and
        // abandon the scheduled transition.
        $message = t('Scheduled Transition is discarded, since Entity has state ID %sid1, instead of expected ID %sid2.');
        $scheduled_transition->logError($message, 'error', $current_sid, $from_sid);
        $scheduled_transition->delete();
        continue;
      }

      // If user didn't give a comment, create one.
      $comment = $scheduled_transition->getComment();
      if (empty($comment)) {
        $scheduled_transition->addDefaultComment();
      }

      // Do transition. Force it because user who scheduled was checked.
      // The scheduled transition is not scheduled anymore, and is also deleted from DB.
      // A watchdog message is created with the result.
      $scheduled_transition->schedule(FALSE);
      $scheduled_transition->executeAndUpdateEntity(TRUE);

      if (!$field_name) {
        $clear_cache = TRUE;
      }
    }

    if ($clear_cache) {
      // Clear the cache so that if the transition resulted in a entity
      // being published, the anonymous user can see it.
      Cache::invalidateTags(['rendered']);
    }
  }

  /**
   * Execute a single transition for the given entity.
   *
   * Called by hook_entity insert(), hook_entity_update().
   *
   * When inserting an entity with workflow field, the initial Transition is
   * saved without reference to the proper entity, since Id is not yet known.
   * So, we cannot save Transition in the Widget, but only(?) in a hook.
   * To keep things simple, this is done for both insert() and update().
   *
   * This is referenced in from WorkflowDefaultWidget::massageFormValues().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  private function executeTransitionsOfEntity(EntityInterface $entity) {

    // Avoid this hook on workflow objects.
    if (WorkflowTargetEntity::isWorkflowEntityType($entity->getEntityTypeId())) {
      return;
    }

    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }

    if (!$field_names = workflow_allowed_field_names($entity)) {
      return;
    }

    $original_entity = WorkflowTargetEntity::getOriginal($entity);
    foreach ($field_names as $field_name => $label) {
      /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
      // @todo Transition is created in widget or WorkflowTransitionForm.
      // @todo $transition = workflow_get_transition($entity, $field_name);
      $transition = $entity->{$field_name}->first()?->getTransition();
      if (!$transition) {
        // We come from creating/editing an entity via entity_form,
        // with core widget or hidden Workflow widget.
        // @todo D8: From an Edit form with hidden widget.
        /* @noinspection PhpUndefinedFieldInspection */
        if ($original_entity) {
          // Editing a Node with hidden Widget. State change not possible, so bail out.
          // $entity->{$field_name}->value = $original_entity->{$field_name}->value;
          // continue;
        }

        // Creating a Node with hidden Workflow Widget. Generate valid first transition.
        $old_sid = WorkflowTargetEntity::getPreviousStateId($entity, $field_name);
        $new_sid = $entity->{$field_name}->value;
        if ((!$new_sid) && $wid = $entity->{$field_name}->getSetting('workflow_type')) {
          $user = workflow_current_user();
          /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
          $workflow = Workflow::load($wid);
          $new_sid = $workflow->getFirstSid($entity, $field_name, $user);
        }
        $transition = WorkflowTransition::create([$old_sid, 'field_name' => $field_name])
          ->setValues($new_sid, NULL, NULL, NULL, TRUE);
      }

      // We come from Content/Comment edit page, from widget.
      // Set the just-saved entity explicitly.
      // Upon insert, the old version didn't have an ID, yet.
      // Upon update, the new revision_id was not set, yet.
      $transition->setTargetEntity($entity);

      $to_sid = $transition->getToSid();
      $executed = match (TRUE) {
        // Sometimes (due to Rules, or extra programming) it can happen that
        // a Transition is executed twice in a call. This check avoids that
        // situation, that generates message "Transition is executed twice".
        $transition->isExecuted()
        => TRUE,
        // Scheduled transitions must be saved, without updating the entity.
        $transition->isScheduled()
        => $transition->save(),
        // If Transition is added via CommentForm, save Comment AND Entity.
        // Execute and check the result.
        // @todo Add setEntityChangedTime() on node (not on comment).
        $entity->getEntityTypeId() == 'comment'
        => ($to_sid === $transition->setEntityChangedTime()->executeAndUpdateEntity()),
        // Execute and check the result.
        default
        => ($to_sid === $transition->setEntityChangedTime()->execute()),
      };

      // If the transition failed, revert the entity workflow status.
      // For new entities, we do nothing: it has no original.
      if (!$executed && $original_entity) {
        $original_value = $original_entity->{$field_name}->value;
         $entity->{$field_name}->setValue($original_value);
      }
    }

    // Invalidate cache tags for entity so that local tasks rebuild,
    // when Workflow is a base field.
    if ($field_names) {
      $entity->getCacheTagsToInvalidate();
    }
  }

}
