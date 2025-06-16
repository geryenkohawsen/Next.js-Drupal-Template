<?php

namespace Drupal\workflow\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Provides a form element replacement for the Action Buttons/Dropbutton.
 *
 * Properties:
 * - #return_value: The value to return when the checkbox is checked.
 *
 * @see \Drupal\Core\Render\Element\FormElement
 * @see https://www.drupal.org/node/169815 "Creating Custom Elements"
 *
 * @ F o r m Element("workflow_transition_buttons")
 */
class WorkflowTransitionButtons {
  // @todo extends FormElement

  /**
   * Fetches the first workflow_element from one of the Workflow fields.
   *
   * @param array $form
   *   The form.
   *
   * @return array
   *   The workflow element, or empty array.
   */
  private static function getFirstWorkflowElement(&$form) {

    // Find the first workflow.
    // (So this won't work with multiple workflows per entity.)
    $transition = $form['#default_value'] ?? NULL;
    if ($transition instanceof WorkflowTransitionInterface) {
      // We are on the workflow_transition_form.
      return $form;
    }

    // We are on node edit page. First fetch the field.
    $workflow_element = [];
    foreach (Element::children($form) as $key) {
      $transition = $form[$key]['widget'][0]['#default_value'] ?? NULL;
      if ($transition instanceof WorkflowTransitionInterface) {
        $workflow_element = $form[$key]['widget'][0];
        break;
      }
    }
    return $workflow_element;
  }

  /**
   * Get the Workflow parameter from the button, pressed by the user.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   The workflow transition.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $values
   *   A list of values.
   *
   * @return array
   *   A $field_name => $to_sid array.
   */
  public static function getTriggeringButton(WorkflowTransitionInterface $transition, FormStateInterface $form_state, array $values) {
    $result = ['field_name' => NULL, 'to_sid' => NULL];

    if (WorkflowTransitionButtons::useActionButtons()) {
      // Add this to avoid error in edge case in Formbuilder::doBuildForm().
      // @see https://www.drupal.org/project/workflow/issues/3513418#comment-16049435
//      $form_state->setProgrammed(TRUE);
      $buttons = $form_state->getButtons();
      if (!$form_state->isProgrammed() && !$form_state->getTriggeringElement() && !empty($buttons)) {
        $jvo = 3;
  //      $form_state->setTriggeringElement($buttons[0]);
      }
    }

    $triggering_element = $form_state->getTriggeringElement();
    if (isset($triggering_element['#workflow'])) {
      // This is a Workflow action button/dropbutton.
      $result['field_name'] = $triggering_element['#workflow']['field_name'];
      $result['to_sid'] = $triggering_element['#workflow']['to_sid'];
    }
    else {
      // This is a normal Save button or another button like 'File upload'.
      $input = $form_state->getUserInput();
      // Field_name may not exist due to '#access' = FALSE.
      $result['field_name'] = $input['field_name'] ?? NULL;
      // To_sid is taken from the Workflow widget, not from the button.
      $result['to_sid'] ??= $input['to_sid'] ?? NULL;
    }
    // Try to get new State ID from a value. (@todo When is this?)
    $result['to_sid'] ??= $values['to_sid'] ?? NULL;

    // A 3rd party button is hit (e.g., File upload field), get default value.
    $result['field_name'] ??= $transition->getFieldName();
    $result['to_sid'] ??= $transition->getToSid();

    return $result;
  }

  /**
   * Returns the action buttons from the options widget.
   *
   * @param array $form
   *   The form. The list of buttons is updated upon return.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function addActionButtons(array &$form, FormStateInterface $form_state) {

    // Get the list of default buttons.
    $actions = &$form['actions'];
    if (!$actions) {
      // Sometimes, no actions are defined. Discard this form.
      return $actions;
    }

    // Use a fast, custom way to check if we need to do this.
    // @todo Make this work with multiple workflows per entity.
    if (!WorkflowTransitionButtons::useActionButtons()) {
      // Change the default submit button on the Workflow History tab.
      return $actions;
    }

    // Find the first workflow. Quit if there is no Workflow on this page.
    // @todo Support multiple workflows per entity.
    $workflow_element = self::getFirstWorkflowElement($form);
    if (!$workflow_element) {
      return $actions;
    }

    // Get the options. They will be converted to buttons.
    // Quit if there are no options / Workflow Action buttons.
    // (If user has only 1 workflow option, there are no Action buttons.)
    $to_sid_widget = $workflow_element['to_sid']['widget'];
    $options = $to_sid_widget['#options'] ?? [];
    if (count($options) <= 1) {
      return $actions;
    }

    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $workflow_element['#default_value'];
    $field_name = $transition->getFieldName();

    // The structure is different on displaying/saving scheduled transitions.
    $current_sid = $to_sid_widget['#default_value'];
    if (is_array($current_sid)) {
      $current_sid = $to_sid_widget['#default_value'][0];
    }

    // Find the default submit button and replace with our own action buttons.
    $default_submit_action = [];
    $default_submit_action = $actions['save'] ?? $default_submit_action;
    $default_submit_action = $actions['submit'] ?? $default_submit_action;

    // Find the default submit button and add our action buttons before it.
    // Get the min weight for our buttons.
    $option_weight = $default_submit_action['#weight'] ?? 0;
    $option_weight -= count($options);
    $min_weight = $option_weight;

    // Add the new submit buttons next to/below the default submit buttons.
    foreach ($options as $sid => $option_name) {
      // Make the workflow button act exactly like the original submit button.
      $same_state_button = ($sid == $current_sid);

      $workflow_submit_action = $default_submit_action;
      // Add target State ID and Field name,
      // to set correct value in validate_buttons callback.
      $workflow_submit_action['#workflow'] = [
        'field_name' => $field_name,
        'to_sid' => $sid,
      ];
      // Keep option order. Put current state first.
      $workflow_submit_action['#weight'] = ($same_state_button) ? $min_weight : ++$option_weight;

      // Add/Overwrite some other settings.
      $workflow_submit_action['#value'] = $option_name;
      $workflow_submit_action['#access'] = TRUE;
      $workflow_submit_action['#button_type'] = '';
      $workflow_submit_action['#attributes'] = ($same_state_button) ? ['class' => ['form-save-default-button']] : [];
      $workflow_submit_action['#attributes']['class'][] = Html::getClass("workflow_button_$option_name");
      // $workflow_submit_action['#executes_submit_callback'] = TRUE;

      // Append the form's #validate function, or it won't be called upon submit,
      // because the workflow buttons have its own #validate.
      $workflow_submit_action['#validate'] ??= $form['#validate'] ?? NULL;

      // Append the submit-buttons's #submit function,
      // or it won't be called upon submit.
      $workflow_submit_action['#submit'] ??= $form['#submit'] ?? NULL;
      // #3458569 Disable Gin Admin theme's 'More actions' button.
      $workflow_submit_action['#gin_action_item'] = TRUE;

      // Use one drop button, instead of several action buttons.
      if ('dropbutton' == WorkflowTransitionButtons::useActionButtons()) {
        $workflow_submit_action['#dropbutton'] = 'save';
        $workflow_submit_action['#button_type'] = '';
      }

      // Add the same-state button, hide in some cases.
      if ($same_state_button) {
        $workflow_submit_action['#button_type'] = ($same_state_button) ? 'primary' : '';
        $workflow_submit_action['#attributes'] = ($same_state_button) ? ['class' => ['form-save-default-button']] : [];
        $workflow_submit_action['#attributes']['class'][] = Html::getClass("workflow_button_$option_name");
        $actions["workflow_$sid"] = $workflow_submit_action;
      }
      else {
        // Add the new state button.
        $actions["workflow_$sid"] = $workflow_submit_action;
      }
    }
    unset($actions['submit']);
    unset($actions['save']);

    return $actions;
  }

  /**
   * Getter/Setter to tell if/which action buttons are used.
   *
   * @param string $button_type
   *   If empty, the current button type is returned,
   *   if not empty, the button type is set to input value.
   *
   * @return string
   *   Previous value. If 'dropbutton'||'buttons', action buttons must be created.
   *
   * @see workflow_form_alter()
   * @see WorkflowDefaultWidget::formElement()
   *
   * Used to save some expensive operations on every form.
   */
  public static function useActionButtons($button_type = '') {
    global $_workflow_action_button_type;

    $_workflow_action_button_type = match ($button_type) {
      // Getting, not setting.
      '' => $_workflow_action_button_type,
      // Setting button type.
      'dropbutton' => $button_type,
      'buttons' => $button_type,
      // Setting any other (non-button) type.
      default => '',
    };

    return $_workflow_action_button_type;
  }

}
