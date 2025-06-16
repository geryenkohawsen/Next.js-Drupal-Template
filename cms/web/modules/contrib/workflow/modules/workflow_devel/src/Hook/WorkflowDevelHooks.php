<?php

namespace Drupal\workflow_devel\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\UserInterface;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

require_once __DIR__ . '../../../../../workflow.api.php';
require_once __DIR__ . '../../../../../workflow.devel.inc';

/**
 * Contains example implementations of the hooks.
 */
class WorkflowDevelHooks {

  /**
   * Implements hook_workflow().
   */
  #[Hook('workflow')]
  public function workflowDevelWorkflow($op, WorkflowTransitionInterface $transition, UserInterface $user) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, $op, '');
    return hook_workflow($op, $transition, $user);
  }

  /**
   * Implements hook_workflow_comment_alter().
   */
  #[Hook('workflow_comment_alter')]
  public function workflowCommentAlter(&$comment, array &$context) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, $comment, '');
    hook_workflow_comment_alter($comment, $context);
  }

  /**
   * Implements hook_workflow_history_alter().
   */
  #[Hook('workflow_history_alter')]
  public function workflowHistoryAlter(array &$context) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__);
    hook_workflow_history_alter($context);
  }

  /**
   * Implements hook_workflow_operations().
   */
  #[Hook('workflow_operations')]
  public function workflowOperations($op, ?EntityInterface $entity = NULL) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, $op, '');
    return hook_workflow_operations($op, $entity);
  }

  /**
   * Implements hook_workflow_permitted_state_transitions_alter().
   */
  #[Hook('workflow_permitted_state_transitions_alter')]
  public function workflowPermittedStateTransitionsAlter(array &$transitions, array $context) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__);
    hook_workflow_permitted_state_transitions_alter($transitions, $context);
  }

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter() for 'workflow_default'.
   *
   * A hook for changing the 'workflow_default' widget.
   * hook_field_widget_form_alter() is deprecated.
   * hook_field_widget_complete_form_alter is added.
   *
   * @see https://www.drupal.org/node/3180429
   * @see https://www.drupal.org/node/2940780
   * @see https://api.drupal.org/api/drupal/core%21modules%21field%21field.api.php/function/hook_field_widget_complete_form_alter/9.3.x
   */
  #[Hook('field_widget_single_element_workflow_default_form_alter')]
  public function fieldWidgetSingleElementWorkflowDefaultFormAlter(&$element, &$form_state, $context) {
    hook_field_widget_single_element_workflow_default_form_alter($element, $form_state, $context);
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    if (substr($form_id, 0, 8) == 'workflow') {
      hook_form_alter($form, $form_state, $form_id);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for 'workflow_transition_form'.
   */
  #[Hook('form_workflow_transition_form_alter')]
  public function formWorkflowTransitionFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, $form_id, '');
    hook_form_workflow_transition_form_alter($form, $form_state, $form_id);
  }

  /**
   * Hooks defined by core: Change the operations column in an Entity list.
   *
   * @see EntityListBuilder::getOperations()
   *
   * @return array
   *   The list of additional operations.
   */
  #[Hook('entity_operation')]
  public function entityOperation($entities) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');
    $operations = [];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array $operations, EntityInterface $entity) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, $entity->getEntityTypeId(), $entity->id());
  }

  /*
   * Hooks defined by core: hook_entity_CRUD.
   *
   * @see hook_entity_create(), hook_entity_update(), etc.
   * @see hook_ENTITY_TYPE_create(), hook_ENTITY_TYPE_update(), etc.
   */

  /**
   * Implements hook_entity_create().
   */
  #[Hook('entity_create')]
  public function entityCreate(EntityInterface $entity) {
    // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'create', $entity->getEntityTypeId());
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'insert', $entity->getEntityTypeId());
  }

  /**
   * Implements hook_entity_presave().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'presave', $entity->getEntityTypeId());
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'update', $entity->getEntityTypeId());
  }

  /**
   * Implements hook_entity_predelete().
   */
  #[Hook('entity_predelete')]
  public function entityPredelete(EntityInterface $entity) {
    if (substr($entity->getEntityTypeId(), 0, 8) == 'workflow') {
      workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'predelete', $entity->getEntityTypeId());
    }
    hook_entity_predelete($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'delete', $entity->getEntityTypeId());
    hook_entity_delete($entity);
  }

}
