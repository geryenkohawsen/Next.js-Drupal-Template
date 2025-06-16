<?php

/**
 * @file
 * Support migration of workflows from older versions.
 */

use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\workflow\Hook\WorkflowMigrateHooks;

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
#[LegacyHook]
function workflow_migrate_upgrade_d7_workflow_state_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  \Drupal::service(WorkflowMigrateHooks::class)->workflowStatePrepareRow($row, $source, $migration);
}

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
#[LegacyHook]
function workflow_migrate_upgrade_d7_workflow_transition_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  \Drupal::service(WorkflowMigrateHooks::class)->workflowTransitionPrepareRow($row, $source, $migration);
}

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
#[LegacyHook]
function workflow_migrate_upgrade_d7_workflow_scheduled_transition_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  \Drupal::service(WorkflowMigrateHooks::class)->workflowScheduledTransitionPrepareRow($row, $source, $migration);
}
