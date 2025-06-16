<?php

namespace Drupal\workflow\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\workflow\Entity\Workflow;

/**
 * Provides a form element for the WorkflowTransitionForm and ~Widget.
 *
 * @see \Drupal\Core\Render\Element\FormElement
 * @see https://www.drupal.org/node/169815 "Creating Custom Elements"
 *
 * @FormElement("workflow_transition_timestamp")
 */
class WorkflowTransitionTimestamp extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      '#process' => [
        [$class, 'processTimestamp'],
        [$class, 'processAjaxForm'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    $transition = $element['#default_value'] ?? NULL;
    // When saving a scheduled transition. (@todo Why is default value empty?).
    $transition ??= $element['_workflow_transition'];

    // Fetch from editing an existing Transition on Workflow History page.
    if ($transition->isExecuted()) {
      if (!$input) {
        // Massage, normalize value after pressing Form button.
        // $element is also updated via reference.
        // Get the time from the default transition data.
        $transition = $element['#default_value'] ?? NULL;
        if ($transition) {
          $timestamp = $transition->getTimestamp();
          return $timestamp;
        }

        // Continue with parsing element values when saving
        // a scheduled transition. (@todo Why is default value empty?).
        $input = $form_state->getValue(['timestamp', 0, 'value']);
        if (!is_array($input)) {
          // Editing the comments of an executed transition.
          $timestamp = $input;
          return $timestamp;
        }
        else {
          // Continue with parsing element values when saving
          // a scheduled transition. (@todo Why is default value empty?).
        }

      }
      else {
        // This should never happen.
        workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'Input is not expected.');
        // Continue with parsing element values.
        $input = $form_state->getValue(['timestamp'[0]['value']]);
      }
    }
    elseif (!$input) {
      // Massage, normalize value after pressing Form button.
      // $element is also updated via reference.
      // Get the time from the default transition data.
      $timestamp = $transition->getTimestamp();
      return $timestamp;
    }
    elseif (!$input_is_scheduled = (bool) $input['scheduled'] ?? FALSE) {
      // Fetch $timestamp from widget for scheduled transitions.
      $timestamp = \Drupal::time()->getRequestTime();
      return $timestamp;
    }

    // Fetch the (scheduled) timestamp to change the state.
    $timestamp = $input['datetime']['workflow_scheduled_datetime'];
    if (is_array($timestamp)) {
      $timestamp = implode(' ', $timestamp);
      $timestamp = strtotime($timestamp);
    }
    elseif ($timestamp instanceof DrupalDateTime) {
      // Field was hidden on widget.
      $timestamp = strtotime($timestamp);
    }

    $new_timezone = $input['datetime']['workflow_scheduled_timezone'];
    $new_timezone = is_array($new_timezone) ? reset($new_timezone) : $new_timezone;
    $old_timezone = date_default_timezone_get();
    if ($new_timezone === $old_timezone) {
      return $timestamp;
    }

    // Convert between timezones. @todo Use OOP.
    // $date = new DrupalDateTime($timestamp, new DateTimeZone($new_timezone));
    // $date = new DrupalDateTime($timestamp, $new_timezone);
    // $text = $date->format('Y-m-d H:i:s');
    // $date->setTimezone(new DateTimeZone($old_timezone));
    // $text = $date->format('Y-m-d H:i:s');
    // $timestamp = $date->getTimestamp();
    date_default_timezone_set($new_timezone);
    $timestamp = strtotime($timestamp);
    date_default_timezone_set($old_timezone);
    if (!$timestamp) {
      // Time should have been validated in form/widget.
      $timestamp = \Drupal::time()->getRequestTime();
    }

    return $timestamp;
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
   *   The Workflow element.
   */
  public static function processTimestamp(array &$element, FormStateInterface $form_state, array &$complete_form) {

    /*
     * Input.
     */

    // A Transition object must have been set explicitly.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $element['#default_value'];
    $user = $transition->getOwner();

    /*
     * Derived input.
     */
    $field_name = $transition->getFieldName();
    // Workflow might be empty on Action/VBO configuration.
    $wid = $transition->getWorkflowId();
    $workflow = $transition->getWorkflow();
    $workflow_settings = $workflow ? $workflow->getSettings() : Workflow::defaultSettings();

    // Display scheduling form if user has permission.
    // Not shown on new entity (not supported by workflow module, because that
    // leaves the entity in the (creation) state until scheduling time.)
    // Not shown when editing existing transition.
    $add_schedule = $workflow_settings['schedule_enable'];
    $add_timezone = $workflow_settings['schedule_timezone'];
    if ($add_schedule
      && !$transition->isExecuted()
      && $user->hasPermission("schedule $wid workflow_transition")
    ) {
      // @todo D8: check below code: form on VBO.
      // workflow_debug(__FILE__, __FUNCTION__, __LINE__);
      switch ($form_state ? $form_state->getValue('step') : NULL) {
        case 'views_bulk_operations_config_form':
          // @todo test D8: On VBO Bulk 'modify entity values' form,
          // Leave field settings.
          $add_schedule = TRUE;
          break;

        default:
          // ... and cannot be shown on a Content add page (no $entity_id),
          // ... but can be shown on a VBO 'set workflow state to..' page (no entity).
          $entity = $transition->getTargetEntity();
          $add_schedule = !($entity && !$entity->id());
          break;
      }
    }

    /*
     * Output: generate the element.
     */

    // Display scheduling form under certain conditions.
    if ($add_schedule) {
      $timezone = $user->getTimeZone();
      if (empty($timezone)) {
        $timezone = \Drupal::config('system.date')->get('timezone.default');
      }

      $timezone_options = array_combine(timezone_identifiers_list(), timezone_identifiers_list());
      $is_scheduled = $transition->isScheduled();
      $timestamp = $transition->getTimestamp();

      // Define class for '#states' behaviour.
      // Fetch the form ID. This is unique for each entity,
      // to allow multiple form per page (Views, etc.).
      // Make it uniquer by adding the field name, or else the scheduling of
      // multiple workflow_fields is not independent of each other.
      // If we are indeed on a Transition form (so, not a Node Form with widget)
      // then change the form id, too.
      $form_id = $form_state
        ? $form_state->getFormObject()->getFormId()
        : WorkflowTransitionElement::getFormId();
      $form_uid = Html::getUniqueId($form_id);
      // @todo Align with WorkflowTransitionForm->getFormId().
      $class_identifier = Html::getClass("scheduled_{$form_uid}-{$field_name}");
      $element['scheduled'] = [
        '#type' => 'radios',
        '#title' => t('Schedule'),
        '#options' => [
          '0' => t('Immediately'),
          '1' => t('Schedule for state change'),
        ],
        '#default_value' => (string) $is_scheduled,
        '#attributes' => [
          // 'id' => "scheduled_{$form_id}",
          'class' => [$class_identifier],
        ],
      ];
      $element['datetime'] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#attributes' => ['class' => ['container-inline']],
        '#states' => [
          'visible' => ["input.$class_identifier" => ['value' => '1']],
        ],
      ];
      $element['datetime']['workflow_scheduled_datetime'] = [
        '#type' => 'datetime',
        '#prefix' => t('At') . ' ',
        // Round timestamp to previous minute.
        '#default_value' => DrupalDateTime::createFromTimestamp(round($timestamp / 60) * 60),
      ];
      $element['datetime']['workflow_scheduled_timezone'] = [
        '#type' => $add_timezone ? 'select' : 'hidden',
        '#options' => $timezone_options,
        '#default_value' => [$timezone => $timezone],
      ];
    }

    return $element;
  }

}
