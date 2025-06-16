<?php

namespace Drupal\workflow\Element;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Provides a form element for the WorkflowTransitionForm and ~Widget.
 *
 * Properties:
 * - #return_value: The value to return when the checkbox is checked.
 *
 * @see \Drupal\Core\Render\Element\FormElement
 * @see https://www.drupal.org/node/169815 "Creating Custom Elements"
 *
 * @FormElement("workflow_transition")
 */
class WorkflowTransitionElement extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      '#return_value' => 1,
      '#process' => [
        [$class, 'processTransition'],
        [$class, 'processAjaxForm'],
        // [$class, 'processGroup'],
      ],
      '#element_validate' => [
        [$class, 'validateTransition'],
      ],
      // @todo D11 removed #pre_render callback array{class-string<static(Drupal\workflow\Element\WorkflowTransitionElement)>, 'preRenderTransition'} at key '0' is not callable.
      // '#pre_render' => [
      // [$class, 'preRenderTransition'],
      // ],
      // '#theme' => 'input__checkbox',
      // '#theme' => 'input__textfield',
      '#theme_wrappers' => ['form_element'],
      // '#title_display' => 'after',
    ];
  }

  /**
   * Generate an element.
   *
   * This function is referenced in the Annotation for this class.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The form.
   *
   * @return array
   *   The Workflow element
   */
  public static function processTransition(array &$element, FormStateInterface $form_state, array &$complete_form) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__); // @todo D8:  test this snippet.
    return self::transitionElement($element, $form_state, $complete_form);
  }

  /**
   * Generate an element.
   *
   * This function is an internal function, to be reused in:
   * - TransitionElement,
   * - TransitionDefaultWidget.
   *
   * @param array $element
   *   Reference to the form element.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   The form state.
   * @param array $complete_form
   *   The form.
   *
   * @return array
   *   The form element $element.
   *
   * @usage:
   *   @example $element['#default_value'] = $transition;
   *   @example $element += WorkflowTransitionElement::transitionElement($element, $form_state, $form);
   */
  public static function transitionElement(array &$element, FormStateInterface|NULL $form_state, array &$complete_form) {

    /*
     * Input.
     */
    // A Transition object must have been set explicitly.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $element['#default_value'];

    /*
     * Derived input.
     */
    $field_name = $transition->getFieldName();
    // Workflow might be empty on Action/VBO configuration.
    $wid = $transition->getWorkflowId();
    $workflow = $transition->getWorkflow();
    $workflow_settings = $workflow ? $workflow->getSettings() : Workflow::defaultSettings();
    $label = $workflow ? $workflow->label() : '';
    $force = $transition->isForced();
    $entity = $transition->getTargetEntity();

    // The help text is not available for container. Let's add it to the
    // To State box. N.B. it is empty on Workflow Tab, Node View page.
    // @see www.drupal.org/project/workflow/issues/3217214
    $help_text = $element['#description'] ?? '';
    unset($element['#description']);

    $show_widget = WorkflowTransitionElement::showWidget($transition);
    // Avoid error with grouped options when workflow not set.
    $options_type = $wid ? $workflow_settings['options'] : 'select';
    $options = $transition->getFromState()->getOptions($entity, $field_name);

    /*
     * Output: generate the element.
     */

    // Save the current value of the entity in the form, for later Workflow-module specific references.
    // We add prefix, since #tree == FALSE.
    $element['_workflow_transition'] = [
      '#type' => 'value',
      '#value' => $transition,
    ];

    $element['#tree'] = TRUE;
    // Add class following node-form pattern (both on form and container).
    $element['#attributes']['class'][] = "workflow-transition-{$wid}-container";
    $element['#attributes']['class'][] = "workflow-transition-container";

    // Prepare a UI wrapper. This might be a fieldset.
    // Note: It will be overridden in WorkflowTransitionForm.
    // @todo Title is not displayed on Node Edit, either.
    $element = [
      '#type' => $workflow_settings['fieldset'] ? 'details' : 'container',
      '#collapsible' => ($workflow_settings['fieldset'] != 0),
      '#open' => ($workflow_settings['fieldset'] != 2),
    ] + $element;

    // Start overriding BaseFieldDefinitions.
    // @see WorkflowTransition::baseFieldDefinitions()
    $attribute_name = 'field_name';
    $attribute_key = 'widget';
    $widget = [
      // Only show field_name on VBO/Actions screen.
      '#access' => FALSE,
    ];
    self::updateWidget($element[$attribute_name], $attribute_key, $widget);

    $attribute_name = 'from_sid';
    $attribute_key = 'widget';
    $widget['#access'] = FALSE;
    // Decide if we show either a widget or a formatter.
    // Add a state formatter before the rest of the form,
    // when transition is scheduled or widget is hidden.
    // Also no widget if the only option is the current sid.
    if ($transition->isScheduled() || $transition->isExecuted() || !$show_widget) {
      $from_sid = $element[$attribute_name][$attribute_key]['#default_value'][0];
      $widget = workflow_state_formatter($entity, $field_name, $from_sid);
      $widget['#label_display'] = 'before'; // 'above', 'hidden'.
      $widget['#access'] = TRUE;
    }
    $element[$attribute_name] = $widget;

    // Add the 'options' widget.
    // It may be replaced later if 'Action buttons' are chosen.
    // @todo Repair $workflow->'name_as_title': no container if no details (schedule/comment).
    $attribute_name = 'to_sid';
    $attribute_key = 'widget';
    $default_value = NULL;
    // 'list_string'.
    $default_value ??= $element[$attribute_name][$attribute_key]['#default_value'];
    // radios vs. select vs. object.
    $default_value = is_array($default_value) ? reset($default_value) : $default_value;
    // 'entity_reference'.
    $default_value ??= $element[$attribute_name][$attribute_key][0]['target_id']['#default_value'];
    $widget = [
      '#type' => $show_widget ? $options_type : 'radios',
      '#title' => (!$workflow_settings['name_as_title'] && !$transition->isExecuted())
        ? t('Change @name state', ['@name' => $label])
        : t('Change state'),
      '#description' => $help_text,
      // When not $show_widget, the 'from_sid' is displayed.
      '#access' => $show_widget,
      // @todo This is only needed for 'radios'. Why?
      '#default_value' => $default_value,
      // @todo BaseField does not restrict the values to current workflow.
      '#options' => $options,
    ];
    // Subfield is NEVER disabled in Workflow 'Manage form display' settings.
    // @see WorkflowTypeFormHooks class.
    self::updateWidget($element[$attribute_name], $attribute_key, $widget);

    // Note: we SET the button type here in a static variable.
    if (WorkflowTransitionButtons::useActionButtons($options_type)) {
      // In WorkflowTransitionForm, a default 'Submit' button is added there.
      // In Entity Form, workflow_form_alter() adds button per permitted state.
      // Performance: inform workflow_form_alter() to do its job.
      //
      // Make sure the '#type' is not set to the invalid 'buttons' value.
      // It will be replaced by action buttons, but sometimes, the select box
      // is still shown.
      // @see workflow_form_alter().
      $widget = [
        '#type' => 'select',
        '#access' => FALSE,
      ];
      // Subfield is NEVER disabled in Workflow 'Manage form display' settings.
      self::updateWidget($element[$attribute_name], $attribute_key, $widget);
    }

    // Display scheduling form under certain conditions.
    $attribute_name = 'timestamp';
    $attribute_key = 'value';
    // @todo Use properties from BaseField settings.
    $widget = [
      '#type' => 'workflow_transition_timestamp',
      '#access' => $show_widget && !$transition->isExecuted(),
      '#default_value' => $transition,
    ];
    // Subfield may be disabled in Workflow 'Manage form display' settings.
    if (isset($element[$attribute_name])) {
      self::updateWidget($element[$attribute_name]['widget'], $attribute_key, $widget);
    }

    // Show comment, when both Field and Instance allow this.
    // This overrides BaseFieldDefinition.
    // @todo Use all settings in Workflow 'Manage form display' settings.
    // @see https://www.drupal.org/node/2100015 'Comment settings are a field'.
    $attribute_name = 'comment';
    $attribute_key = 'value';
    // @todo Use properties from BaseField settings.
    $widget = [
      '#type' => 'textarea',
      '#access' => ($workflow_settings['comment_log_node'] != '0'),
      '#default_value' => $transition->getComment(),
      '#required' => $workflow_settings['comment_log_node'] == '2',
    ];
    // Subfield may be disabled in Workflow 'Manage form display' settings.
    if (isset($element[$attribute_name])) {
      self::updateWidget($element[$attribute_name]['widget'], $attribute_key, $widget);
    }

    // There is no standard widget for 'force' (yet).
    $element['force'] = [
      '#type' => 'checkbox',
      '#title' => t('Force transition'),
      '#description' => t('If this box is checked, the new state will be
        assigned even if workflow permissions disallow it.'),
      '#access' => FALSE, // Only show on VBO/Actions screen.
      '#default_value' => $force,
      '#weight' => 10,
    ];

    return $element;
  }

  /**
   * Adds the workflow attributes to the standard attribute of each widget.
   *
   * For some reason, the widgets are in another level when the entity form page
   * is presented, then when the entity form page is submitted.
   *
   * @param array $haystack
   *   The array in which the widget is hidden.
   * @param string $attribute_key
   *   The widget key.
   * @param array $data
   *   The additional workflow data for the widget.
   */
  protected static function updateWidget(array &$haystack, string $attribute_key, array $data) {
    if (isset($haystack[0][$attribute_key])) {
      $haystack[0][$attribute_key] = $data + $haystack[0][$attribute_key];
    }
    elseif (!empty($haystack[$attribute_key])) {
      $haystack[$attribute_key] = $data + $haystack[$attribute_key];
    }
    else {
      // Subfield is disabled in Workflow 'Manage form display' settings.
      // Do not add our data.
    }
  }

  /**
   * Determines if the Workflow Transition Form must be shown.
   *
   * If not, a formatter must be shown, since there are no valid options.
   * Only the comment field may be displayed.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   The transition at hand.
   *
   * @return bool
   *   A boolean indicator to display a widget or formatter.
   *   TRUE = a form (a.k.a. widget) must be shown;
   *   FALSE = no form, a formatter must be shown instead.
   */
  public static function showWidget(WorkflowTransitionInterface $transition) {

    $entity = $transition->getTargetEntity();
    $field_name = $transition->getFieldName();
    $account = $transition->getOwner();

    if ($transition->getTargetEntity()->in_preview) {
      // Avoid having the form in preview, since it has action buttons.
      // In preview, you can only go back to original, user cannot save data.
      return FALSE;
    }

    if ($transition->isExecuted()) {
      // We are editing an existing/executed/not-scheduled transition.
      // We need the widget to edit the comment.
      // Only the comments may be changed!
      // The states may not be changed anymore.
      return TRUE;
    }

    if (!$transition->getFromSid()) {
      // On Actions, where no entity exists.
      return TRUE;
    }

    $options = $transition->getFromState()->getOptions($entity, $field_name, $account);
    $count = count($options);
    // The easiest case first: more then one option: always show form.
    if ($count > 1) {
      return TRUE;
    }

    // #2226451: Even in Creation state, we must have 2 visible states to show the widget.
    // // Only when in creation phase, one option is sufficient,
    // // since the '(creation)' option is not included in $options.
    // // When in creation state,
    // if ($this->isCreationState()) {
    // return TRUE;
    // }
    return FALSE;
  }

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The form ID.
   */
  public static function getFormId() {
    return 'workflow_transition_form';
  }

  /**
   * Implements ContentEntityForm::copyFormValuesToEntity().
   *
   * This is called from:
   * - WorkflowTransitionForm::copyFormValuesToEntity(),
   * - WorkflowDefaultWidget.
   *
   * N.B. in contrary to ContentEntityForm::copyFormValuesToEntity(),
   * - param 1 is returned as result, for creating a new Transition object.
   * - param 3 is not $form_state (from Form), but $item array (from Widget).
   *
   * @param \Drupal\Core\Entity\EntityInterface $transition
   *   The transition object.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $values
   *   The field item.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   A new Transition object.
   */
  public static function copyFormValuesToTransition(EntityInterface $transition, array $form, FormStateInterface $form_state, array $values) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */

    // Read value from form input, else widget values.
    // May return NULL's upon hitting a 3rd party button (e.g., file upload)
    $action_values = WorkflowTransitionButtons::getTriggeringButton($transition, $form_state, $values);
    $to_sid = $action_values['to_sid'];

    // Get user input from element.
    $force = FALSE;

    // Note: when editing existing Transition, user may still change comments.
    // Note: subfields might be disabled, and not exist in formState.
    // Note:subfields are already set by core.
    // This is only needed on Node edit widget, not on Node view/history page.
    $comment = $values['comment'][0]['value'] ?? '';
    $timestamp_input = $form_state->getUserInput()['timestamp'][0]['value'] ?? FALSE;
    $timestamp = WorkflowTransitionTimestamp::valueCallback($values, $timestamp_input, $form_state);

    /*
     * Process.
     */

    $transition->setValues($to_sid);
    $transition->setTimestamp($timestamp);
    $transition->setComment($comment);

    if (!$transition->isExecuted()) {
      $transition->schedule(($transition->getTimestamp() - 60) > \Drupal::time()->getRequestTime());
      $transition->force($force);
    }

    $transition = self::copyAttachedFields($transition, $form, $form_state, $values);

    // Update targetEntity's itemList with the workflow field in two formats.
    $transition->setEntityWorkflowField();

    return $transition;
  }

  /**
   * Adds the attached fields from the element to the transition.
   *
   * Caveat: This works automatically on a Workflow Form,
   * but only with a hack on a widget.
   *
   * @todo This line seems necessary for node edit, not for node view.
   * @todo Support 'attached fields' in ScheduledTransition.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transition
   *   The transition object.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $values
   *   The field item.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The updated Transition object.
   */
  public static function copyAttachedFields(EntityInterface $transition, array $form, FormStateInterface $form_state, array $values) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $attached_fields = $transition->getAttachedFields();
    /** @var \Drupal\Core\Field\Entity\BaseFieldOverride $field */
    foreach ($attached_fields as $field_name => $field) {
      if (isset($values[$field_name])) {
        $transition->{$field_name} = $values[$field_name];
      }

      // #2899025 For each field, let other modules modify the copied values,
      // as a workaround for not-supported field types.
      $input ??= $form_state->getUserInput();
      $context = [
        'field' => $field,
        'field_name' => $field_name,
        'user_input' => $input[$field_name] ?? [],
        'item' => $values,
      ];
      \Drupal::moduleHandler()->alter('copy_form_values_to_transition_field', $transition, $context);
    }
    return $transition;
  }

}
