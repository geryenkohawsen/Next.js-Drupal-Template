<?php

/**
 * @file
 * Defines a Workflow field, widget and formatter. (copied from list field).
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\workflow\Entity\WorkflowState;
use Drupal\workflow\Hook\WorkflowFieldHooks;

/**
 * Implements hook_form_FORM_ID_alter() for 'field_config_edit_form'.
 */
#[LegacyHook]
function workflow_form_field_config_edit_form_alter(&$form, FormStateInterface $form_state, $form_id) {
  \Drupal::service(WorkflowFieldHooks::class)->formFieldConfigEditFormAlter($form, $form_state, $form_id);
}

/**
 * Using some default formatters and widgets from the List and Options modules.
 */

/**
 * Implements hook_field_formatter_info_alter().
 */
#[LegacyHook]
function workflow_field_formatter_info_alter(&$info) {
  \Drupal::service(WorkflowFieldHooks::class)->fieldFormatterInfoAlter($info);
}

/**
 * Implements hook_field_widget_info_alter().
 */
#[LegacyHook]
function workflow_field_widget_info_alter(&$info) {
  \Drupal::service(WorkflowFieldHooks::class)->fieldWidgetInfoAlter($info);
}

/**
 * Creates a form element to show the current value of a Workflow state.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity this field is on.
 * @param string $field_name
 *   The field_name.
 * @param string $current_sid
 *   The current State Id.
 *
 * @return array
 *   Form element, resembling the formatter of List module.
 *   If state 0 is given, return an empty form element.
 *   It may be needed to add the following after calling this function:
 *   $widget['#label_display'] = 'before'; // 'above', 'hidden'.
 */

function workflow_state_formatter(EntityInterface $entity, $field_name, $current_sid = NULL) {
  $element = [];

  $current_sid ??= workflow_node_current_state($entity, $field_name);
  // Clone the entity and restore old value, in case you want to show an
  // executed transition.
  if ($entity->{$field_name}->value != $current_sid) {
    $entity = clone $entity;
    $entity->{$field_name}->value = $current_sid;
  }

  // Generate a renderable array for the field.
  // Use default language determination ($langcode = '').
  // First, add the 'current value' formatter for this field.
  // $list_display = $instance['display']['default']; .
  $list_display['type'] = 'list_default';
  $element = $entity->{$field_name}->view($list_display);
  // Remove label_display to let caller determine it.
  unset($element['#label_display']);

  // If user creates a node, and only 1 option is available, the formatter
  // is shown with key, not value, because creation state does not count.
  // In this case, hide the formatter.
  $element['#access'] = TRUE;
  if ($entity->isNew()) {
    /** @var \Drupal\workflow\Entity\WorkflowState $state */
    $state = WorkflowState::load($current_sid);
    if ($state && $state->isCreationState()) {
      $element['#access'] = FALSE;
    }
  }

  // Make sure the current value is before the form. (which has weight = 0.005)
  $element['#weight'] = -0.005;

  return $element;
}
