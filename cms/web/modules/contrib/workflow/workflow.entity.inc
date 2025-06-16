<?php

/**
 * @file
 * Implements entity hooks.
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Hook\WorkflowEntityHooks;
use Drupal\workflow\Hook\WorkflowTypeFormHooks;

/**********************************************************************
 * CRUD hooks.
 */

/**
 * Implements hook_entity_insert().
 */
#[LegacyHook]
function workflow_entity_insert(EntityInterface $entity) {
  \Drupal::service(WorkflowEntityHooks::class)->entityInsert($entity);
}

/**
 * Implements hook_entity_update().
 */
#[LegacyHook]
function workflow_entity_update(EntityInterface $entity) {
  \Drupal::service(WorkflowEntityHooks::class)->entityUpdate($entity);
}

/**
 * Implements hook_entity_delete().
 *
 * Deletes the corresponding workflow table records.
 */
#[LegacyHook]
function workflow_entity_delete(EntityInterface $entity) {
  \Drupal::service(WorkflowEntityHooks::class)->entityDelete($entity);
}

/**
 * Implements hook_form_FORM_ID_alter() for "entity_form_display_edit_form".
 *
 * Adds workflow settings to the form.
 */
#[LegacyHook]
function workflow_form_entity_form_display_edit_form_alter(&$form, FormStateInterface $form_state, $form_id) {
  \Drupal::service(WorkflowTypeFormHooks::class)->formAlter($form, $form_state, $form_id);
}

/**
 * Implements hook_ENTITY_TYPE_presave() for "entity_form_display" form.
 *
 * Ensures that the to_sid widget is not hidden by site builder.
 */
#[LegacyHook]
function workflow_entity_form_display_presave(EntityInterface $entity) {
  \Drupal::service(WorkflowTypeFormHooks::class)->entityPresave($entity);
}

/**
 * Implements hook_ENTITY_TYPE_update() for "entity_form_display" form.
 *
 * Saves workflow settings.
 */
#[LegacyHook]
function workflow_entity_form_display_update(EntityInterface $entity) {
  \Drupal::service(WorkflowTypeFormHooks::class)->entityUpdate($entity);
}

/**
 * Implements hook_entity_operation() for workflow_transition.
 *
 * Core hooks: Change the operations column in a Entity list.
 * Adds a 'revert' operation.
 *
 * @see EntityListBuilder::getOperations()
 */
#[LegacyHook]
function workflow_entity_operation(EntityInterface $entity) {
  return \Drupal::service(WorkflowEntityHooks::class)->entityOperation($entity);
}

/**
 * Implements hook_user_cancel().
 *
 * Update tables for deleted account, move account to user 0 (anon.)
 * ALERT: This may cause previously non-Anonymous posts to suddenly
 * be accessible to Anonymous.
 *
 * @see hook_user_cancel()
 */
#[LegacyHook]
function workflow_user_cancel($edit, AccountInterface $account, $method) {
  \Drupal::service(WorkflowEntityHooks::class)->userDelete($account);
}

/**
 * Implements hook_user_delete().
 *
 * @todo Hook hook_user_delete does not exist. hook_ENTITY_TYPE_delete?
 */
#[LegacyHook]
function workflow_user_delete($account) {
  \Drupal::service(WorkflowEntityHooks::class)->userDelete($account);
}

/**
 * Implements hook_ENTITY_TYPE_insert() for 'workflow_type'.
 *
 * Is called when adding a new Workflow type.
 */
#[LegacyHook]
function workflow_workflow_type_insert(EntityInterface $entity) {
  \Drupal::service(WorkflowEntityHooks::class)->workflowTypeInsert($entity);
}

/**
 * Implements hook_ENTITY_TYPE_predelete() for 'workflow_type'.
 *
 * Is called when deleting a new Workflow type.
 */
#[LegacyHook]
function workflow_workflow_type_predelete(EntityInterface $entity) {
  \Drupal::service(WorkflowEntityHooks::class)->workflowTypePredelete($entity);
}
