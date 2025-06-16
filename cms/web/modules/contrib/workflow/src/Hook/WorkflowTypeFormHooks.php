<?php

namespace Drupal\workflow\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workflow\Form\WorkflowTypeForm;

/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowTypeFormHooks {

  /**
   * Initializes the services required.
   */
  public function __construct() {
  }

  /**
   * Implements hook_form_FORM_ID_alter() for "entity_form_display_edit_form".
   *
   * Adds action/drop buttons next to the 'Save'/'Delete' buttons,
   * when the 'options' widget element is set to 'action buttons'.
   * Note: do not use with multiple workflows per entity: confusing UX.
   */
  #[Hook('form_F O R M _ I D _alter')]
  #[Hook('form_entity_form_display_edit_form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    if (!$workflow = workflow_url_get_workflow()) {
      return;
    }

    // Tweak the 'to_sid' widget.
    $widget_to_sid = &$form['fields']['to_sid'];
    if (!$widget_to_sid) {
      return;
    }

    // Cannot be dragged to 'disabled' region (when 'Hide row weights' enabled).
    $widget_to_sid['#attributes']['class'] = array_diff($widget_to_sid['#attributes']['class'], ['draggable']);
    $widget_to_sid['#attributes']['class'] = array_diff($widget_to_sid['#attributes']['class'], ['tabledrag-leaf']);
    // Cannot be set to 'disabled' region (when 'Show row weights' enabled).
    unset($widget_to_sid['region']['#options']['hidden']);

    // Copy settings from WorkflowTypeForm.
    // Keep aligned: workflow_form_alter(), WorkflowTransitionForm::actions().
    $options = WorkflowTypeForm::getStateWidgetOptions();
    $state_widget_type = $workflow->getSetting('options');
    // Avoid using autocomplete, since it will open Pandora's box.
    $current_options = $widget_to_sid['plugin']['type']['#options'];
    $widget_to_sid['plugin']['type']['#options'] = [
      'options_buttons' => $current_options['options_buttons'],
      'options_select' => $current_options['options_select'],
    ];
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for "entity_form_display" form.
   */
  #[Hook('entity_form_display_presave')]
  public function entityPresave(EntityInterface $entity) {
    if ($entity->getEntityTypeId() == 'entity_form_display') {
      /** @var \Drupal\Core\Entity\Entity\EntityFormDisplay $entity */
      if ($entity->getTargetEntityTypeId() == 'workflow_transition') {
        if (isset($entity->hidden['to_sid'])) {
          WorkflowTypeFormHooks::addPreSaveBasefieldWarning();
          $hidden = $entity->hidden;
          unset($hidden['to_sid']);
          $entity->hidden = $hidden;
        }
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for "entity_form_display" form.
   */
  #[Hook('entity_form_display_update')]
  public function entityUpdate(EntityInterface $entity) {
    if ($entity->getEntityTypeId() == 'entity_form_display') {
      /** @var \Drupal\Core\Entity\Entity\EntityFormDisplay $entity */
      if ($entity->getTargetEntityTypeId() == 'workflow_transition') {
        // @todo Save workflow settings.
      }
    }
  }

  /**
   * Generates a user message on Workflow 'Form display settings'.
   *
   * Ensures that the to_sid widget is not hidden by site builder.
   */
  public static function addPreSaveBasefieldWarning() {
    \Drupal::messenger()->addWarning("The 'To state' cannot be disabled on the Transition Form and is enabled again.");
  }

}
