<?php

namespace Drupal\workflow\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\workflow\Entity\WorkflowState;


/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowMigrateHooks {

  /**
   * Implements hook_migrate_MIGRATION_ID_prepare_row().
   */
  #[Hook('migrate_workflow_state_prepare_row')]
  public function workflowStatePrepareRow(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
    if ('(creation)' == $row->getSourceProperty('name')) {
      $row->setSourceProperty('name', WorkflowState::CREATION_STATE_NAME);
    }
  }

  /**
   * Implements hook_migrate_MIGRATION_ID_prepare_row().
   */
  #[Hook('migrate_workflow_transition_prepare_row')]
  public function workflowTransitionPrepareRow(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
    static $sid2wid = [];
    static $migration = NULL;

    $sid = $row->getSourceProperty('sid');

    if (!isset($sid2wid[$sid])) {
      $migrations = \Drupal::service('plugin.manager.migration')
        ->createInstances('upgrade_d7_workflow_state');
      $migration ??= reset($migrations);
      if ($migration) {
        $sids = $migration
          ->getIdMap()
          ->lookupDestinationIds([$sid]);
        $new_sid = reset($sids);
        // Set source and target lookups.
        $sid2wid[$row->getSourceProperty('old_sid')] =
          $sid2wid[$sid] = WorkflowState::load($new_sid)->getWorkflowId();
      }
    }
    if (isset($sid2wid[$sid])) {
      $row->setSourceProperty('wid', $sid2wid[$sid]);
    }
  }

  /**
   * Implements hook_migrate_MIGRATION_ID_prepare_row().
   */
  #[Hook('migrate_workflow_scheduled_transition_prepare_row')]
  public function workflowScheduledTransitionPrepareRow(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
    $this->workflowTransitionPrepareRow($row, $source, $migration);
  }

}
