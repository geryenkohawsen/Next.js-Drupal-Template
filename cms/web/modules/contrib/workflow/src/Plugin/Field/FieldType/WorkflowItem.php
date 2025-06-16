<?php

namespace Drupal\workflow\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\options\Plugin\Field\FieldType\ListItemBase;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowState;
use Drupal\workflow\Entity\WorkflowTargetEntity;
use Drupal\workflow\Entity\WorkflowTransition;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Plugin implementation of the 'workflow' field type.
 *
 * @FieldType(
 *   id = "workflow",
 *   label = @Translation("Workflow state"),
 *   description = @Translation("This field stores Workflow values for a certain Workflow type from a list of allowed 'value => label' pairs, i.e. 'Publishing': 1 => unpublished, 2 => draft, 3 => published."),
 *   category = "workflow",
 *   default_widget = "workflow_default",
 *   default_formatter = "list_default",
 *   constraints = {
 *     "WorkflowField" = {}
 *   },
 *   cardinality = 1,
 * )
 */
class WorkflowItem extends ListItemBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {

    /**
     * Property definitions of the contained properties.
     *
     * @see FileItem::getPropertyDefinitions()
     *
     * @var array
     */
    static $propertyDefinitions;
    // Use underscore to avoid confusion with EntityTypeId.
    // @todo Better use 'target_transition'.
    $target_type = '_workflow_transition';
    $definition['settings']['target_type'] = $target_type;
    // Definitions vary by entity type and bundle, so key them accordingly.
    $key = "{$target_type}:" . ($definition['settings']['target_bundle'] ?? '');

    if (!isset($propertyDefinitions[$key])) {
      $propertyDefinitions[$key]['value'] = DataDefinition::create('string')
        ->setLabel(t('Workflow state'))
        ->addConstraint('Length', ['max' => 128])
        ->setRequired(TRUE);

      $propertyDefinitions[$key][$target_type] = DataDefinition::create('any')
        // = DataDefinition::create('WorkflowTransition')
        ->setLabel(t('Transition'))
        ->setDescription(t('The WorkflowTransition setting the Workflow state.'))
        ->setComputed(TRUE)
        // ->setClass('\Drupal\workflow\Entity\WorkflowTransition')
        // ->setSetting('date source', 'value')
      ;
    }
    return $propertyDefinitions[$key];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'value' => [
          'description' => 'The {workflow_states}.sid that this entity is currently in.',
          'type' => 'varchar',
          'length' => 128,
        ],
      ],
      'indexes' => [
        'value' => ['value'],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    $settings = [
      'workflow_type' => '',
      // Do not change. @see https://www.drupal.org/project/drupal/issues/2643308
      'allowed_values_function' => 'workflow_state_allowed_values',
    ] + parent::defaultStorageSettings();
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $element = parent::storageSettingsForm($form, $form_state, $has_data);

    $this->validateStorageSettingsForm($form, $form_state, $has_data);
    // Create list of all Workflow types. Include an initial empty value.
    $workflow_options = workflow_allowed_workflow_names(FALSE);
    $field_storage = $this->getFieldDefinition()->getFieldStorageDefinition();
    $wid = $this->getSetting('workflow_type');

    // Set the required workflow_type on 'comment' fields.
    /** @var \Drupal\Core\Field\FieldStorageDefinitionInterface $field_storage */
    if (!$wid && $field_storage->getTargetEntityTypeId() == 'comment') {
      $field_name = $field_storage->get('field_name');
      $workflow_options = [];
      foreach (_workflow_info_fields(NULL, '', '', $field_name) as $key => $info) {
        if ($info->getName() == $field_name && ($info->getTargetEntityTypeId() !== 'comment')) {
          $wid = $info->getSetting('workflow_type');
          $workflow = Workflow::load($wid);
          $workflow_options[$wid] = $workflow->label();
        }
      }
    }

    // Let the user choose between the available workflow types.
    $url = Url::fromRoute('entity.workflow_type.collection')->toString();
    $element['workflow_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Workflow type'),
      '#options' => $workflow_options,
      '#default_value' => $wid,
      '#required' => TRUE,
      '#disabled' => $has_data,
      '#description' => $this->t('Choose the Workflow type. Maintain workflows
         <a href=":url">here</a>.', [':url' => $url]),
    ];

    // Overwrite ListItemBase::storageSettingsForm().
    // First, remove 'allowed values' list, due to restructured form in D10.2.
    unset($element['allowed_values']);
    // @todo Set 'allowed_values_function' properly in storage,
    // so default parent code can be used.
    // Do not change. @see https://www.drupal.org/project/drupal/issues/2643308
    $allowed_values_function = $this->defaultStorageSettings()['allowed_values_function'];
    $element['allowed_values_function'] = [
      '#type' => 'item',
      '#title' => $this->t('Allowed values list'),
      '#markup' => $this->t('The value of this field is being determined by the %function function and may not be changed.', ['%function' => $allowed_values_function]),
      '#access' => !empty($allowed_values_function),
      '#value' => $allowed_values_function,
    ];

    return $element;
  }

  /**
   * Generate messages on ConfigFieldItemInterface::settingsForm().
   */
  protected function validateStorageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    // Validate each workflow, and generate a message if not complete.
    // Create list of all Workflow types. Include an initial empty value.
    $workflow_options = workflow_allowed_workflow_names(FALSE);
    // @todo D8: add this to WorkflowFieldConstraintValidator.
    // Set message, if no 'validated' workflows exist.
    if (count($workflow_options) == 1) {
      $this->messenger()->addWarning(
        $this->t('You must <a href=":create">create at least one workflow</a>
          before content can be assigned to a workflow.',
          [':create' => Url::fromRoute('entity.workflow_type.collection')->toString()]
        ));
    }

    // Validate via annotation WorkflowFieldConstraint.
    // Show a message for each error.
    $violation_list = $this->validate();
    /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
    foreach ($violation_list->getIterator() as $violation) {
      switch ($violation->getPropertyPath()) {
        case 'fieldnameOnComment':
          // @todo D8: CommentForm & constraints on storageSettingsForm().
          // A 'comment' field name MUST be equal to content field name.
          // @todo Fix fields on a non-relevant entity_type.
          $this->messenger()->addError($violation->getMessage());
          $workflow_options = [];
          break;

        default:
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity() {
    $entity = parent::getEntity();

    // For Workflow on CommentForm, get the CommentedEntity.
    if ($entity->getEntityTypeId() == 'comment') {
      /** @var \Drupal\comment\CommentInterface $entity */
      $entity = $entity->getCommentedEntity();
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $is_empty = empty($this->value);
    return $is_empty;
  }

  /**
   * {@inheritdoc}
   *
   * Set both the Transition property AND the to_sid value.
   */
  public function setValue($values, $notify = TRUE) {
    if ($values instanceof WorkflowTransitionInterface) {
      /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $values */
      $to_sid = $values->getToSid();
      $keys = array_keys($this->definition->getPropertyDefinitions());
      $values = [
        $keys[0] => $to_sid, // 'value'.
        $keys[1] => $values, // '_workflow_transition'.
      ];
    }
    parent::setValue($values, $notify);
  }

  /**
   * Gets the item's WorkflowState (of the first item in ItemList).
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The Transition object.
   */
  public function getState() {
    $sid = $this->getStateId();
    $state = WorkflowState::load($sid)
      ?? WorkflowState::create([
        'id' => $sid,
        'wid' => $this->getFieldDefinition()->getSetting('workflow_type'),
      ]);
    return $state;
  }

  /**
   * Gets the item's WorkflowState ID (of the first item in ItemList).
   *
   * @return string
   *   The Workflow State ID.
   */
  public function getStateId() {
    $sid = $this->value;
    $sid ??= $this->getParent()->value;
    return $sid;
  }

  /**
   * Gets the item's '_workflow_transition' (of the first item in ItemList).
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition object, or NULL of not set.
   */
  public function getTransition() {
    $transition = NULL;

    // Implements $transition = $entity->{$field_name}->__get('_workflow_transition') ?? NULL;
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $property = $this->get('_workflow_transition');
    $transition = $property->getValue();
    if ($transition) {
      return $transition;
    }

    // Create a transition, to pass to the form.
    // @todo $this->set('_workflow_transition'); (?)
    $entity = $this->getEntity();
    $field_name = $this->getParent()->getName();
    $transition = WorkflowTransition::create(['entity' => $entity, 'field_name' => $field_name]);
    return $transition;
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($property_name, $notify = TRUE) {
    // workflow_debug(__FILE__, __FUNCTION__, __LINE__);  // @todo D8: test this snippet.

    // @todo D8: use this function onChange for adding a line in table workflow_transition_*
    // Enforce that the computed date is recalculated.
    // if ($property_name == 'value') {
    //   $this->date = NULL;
    // }
    parent::onChange($property_name, $notify);
  }

  /**
   * {@inheritdoc}
   */
  protected function allowedValuesDescription() {
    return '';
  }

  /**
   * Generates a string representation of an array of 'allowed values'.
   *
   * This string format is suitable for edition in a textarea.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $states
   *   An array of WorkflowStates, where array keys are values and array values are
   *   labels.
   * @param string $wid
   *   The requested Workflow ID.
   *
   * @return string
   *   The string representation of the $states array:
   *    - Values are separated by a carriage return.
   *    - Each value is in the format "value|label" or "value".
   */
  protected function allowedValuesString($states) {
    $lines = [];

    $wid = $this->getSetting('workflow_type');

    $previous_wid = -1;
    /** @var \Drupal\workflow\Entity\WorkflowState $state */
    foreach ($states as $key => $state) {
      // Only show enabled states.
      if ($state->isActive()) {
        // Show a Workflow name between Workflows, if more then 1 in the list.
        if ((!$wid) && ($previous_wid <> $state->getWorkflowId())) {
          $previous_wid = $state->getWorkflowId();
          $workflow_label = $state->getWorkflow()->label();
          $lines[] = "$workflow_label's states: ";
        }
        $label = $this->t('@label', ['@label' => $state->label()]);

        $lines[] = "   $key|$label";
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Implementation of TypedDataInterface.
   *
   * @see folder \workflow\src\Plugin\Validation\Constraint
   */

  /**
   * Implementation of OptionsProviderInterface.
   *
   *   An array of settable options for the object that may be used in an
   *   Options widget, usually when new data should be entered. It may either be
   *   a flat array of option labels keyed by values, or a two-dimensional array
   *   of option groups (array of flat option arrays, keyed by option group
   *   label). Note that labels should NOT be sanitized.
   */

  /**
   * {@inheritdoc}
   */
  public function getPossibleValues(?AccountInterface $account = NULL) {
    // Flatten options first, because SettableOptions may have 'group' arrays.
    $flatten_options = OptGroup::flattenOptions($this->getPossibleOptions($account));
    return array_keys($flatten_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleOptions(?AccountInterface $account = NULL) {
    $allowed_options = [];

    // When we are initially on the Storage settings form, no wid is set, yet.
    if (!$wid = $this->getSetting('workflow_type')) {
      return $allowed_options;
    }

    // Create an empty State. This triggers to show all possible states for the Workflow.
    if ($workflow = Workflow::load($wid)) {
      // Entity may be empty, E.g., on the Rules action "Set a data value".
      $entity = $this->getEntity();
      $field_name = $this->getParent()->getName();
      $user = workflow_current_user();

      $field_config = $entity->get($field_name)->getFieldDefinition();
      $field_storage = $field_config->getFieldStorageDefinition();
      $allowed_options = workflow_state_allowed_values($field_storage, $entity);
    }

    return $allowed_options;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableValues(?AccountInterface $account = NULL) {
    // Flatten options first, because SettableOptions may have 'group' arrays.
    $flatten_options = OptGroup::flattenOptions($this->getSettableOptions($account));
    return array_keys($flatten_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableOptions(?AccountInterface $account = NULL) {
    $allowed_options = [];

    // When we are initially on the Storage settings form, no wid is set, yet.
    if (!$wid = $this->getSetting('workflow_type')) {
      return $allowed_options;
    }

    // On Field settings page, no entity is set.
    if (!$entity = $this->getEntity()) {
      return $allowed_options;
    }

    $definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    $field_name = $definition->getName();
    $user = workflow_current_user($account); // @todo #2287057: OK?
    $state = $this->getState();
    // Get the allowed new states for the entity's current state.
    $allowed_options = ($state)
      ? $state->getOptions($entity, $field_name, $user, FALSE)
      : [];

    return $allowed_options;
  }

}
