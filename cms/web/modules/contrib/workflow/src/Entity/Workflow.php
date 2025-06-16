<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workflow\WorkflowURLRouteParametersTrait;

/**
 * Workflow configuration entity to persistently store configuration.
 *
 * @ConfigEntityType(
 *   id = "workflow_type",
 *   label = @Translation("Workflow type"),
 *   label_singular = @Translation("Workflow type"),
 *   label_plural = @Translation("Workflow types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Workflow type",
 *     plural = "@count Workflow types",
 *   ),
 *   module = "workflow",
 *   static_cache = TRUE,
 *   translatable = TRUE,
 *   handlers = {
 *     "storage" = "Drupal\workflow\Entity\WorkflowStorage",
 *     "list_builder" = "Drupal\workflow\WorkflowListBuilder",
 *     "form" = {
 *        "add" = "Drupal\workflow\Form\WorkflowTypeForm",
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *        "edit" = "Drupal\workflow\Form\WorkflowTypeForm",
 *      }
 *   },
 *   admin_permission = "administer workflow",
 *   config_prefix = "workflow",
 *   bundle_of = "workflow_transition",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "module",
 *     "status",
 *     "options",
 *   },
 *   links = {
 *     "canonical" = "/admin/config/workflow/workflow/{workflow_type}",
 *     "collection" = "/admin/config/workflow/workflow",
 *     "delete-form" = "/admin/config/workflow/workflow/{workflow_type}/delete",
 *     "edit-form" = "/admin/config/workflow/workflow/{workflow_type}",
 *   },
 * )
 */
class Workflow extends ConfigEntityBase implements WorkflowInterface {

  /*
   * Provide URL route parameters for entity links.
   */
  use MessengerTrait;
  use StringTranslationTrait;
  use WorkflowURLRouteParametersTrait;

  /**
   * The machine name.
   *
   * @var string
   */
  public $id;
  /**
   * The human readable name.
   *
   * @var string
   */
  public $label;
  /**
   * The Workflow settings (which would be a better name).
   *
   * @var array
   */
  public $options = [];
  /**
   * The workflow-specific creation state.
   *
   * @var \Drupal\workflow\Entity\WorkflowState
   */
  private $creation_state = NULL;
  /**
   * The workflow-specific creation state ID.
   *
   * @var string
   */
  private $creation_sid = '';
  /**
   * Attached States.
   *
   * @var \Drupal\workflow\Entity\WorkflowState[]
   */
  public $states = [];
  /**
   * Attached Transitions.
   *
   * @var \Drupal\workflow\Entity\WorkflowConfigTransitionInterface[]
   */
  public $transitions = [];
  /**
   * The module implementing this object, for config_export.
   *
   * @var string
   */
  protected $module = 'workflow';

  /**
   * CRUD functions.
   */

  /**
   * {@inheritdoc}
   */
  public static function load($id) {
    // Store data in static cache, (overriding parent cache)
    // avoiding multiple loads of states and transitions.
    static $workflows;
    return $workflows[$id] ??= parent::load($id);
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) {
    /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
    foreach ($entities as &$workflow) {
      // Better performance, together with Annotation static_cache = TRUE.
      // Load the states, and set the creation state.
      $workflow->getStates();
      $workflow->getTransitions();
    }
  }

  /**
   * Given information, update or insert a new workflow.
   *
   * This also handles importing, rebuilding, reverting from Features,
   * as defined in workflow.features.inc.
   *
   * @todo D8: Clean up this function, since we are config entity now.
   *
   * When changing this function, test with the following situations:
   * - maintain Workflow in Admin UI;
   * - clone Workflow in Admin UI;
   * - create/revert/rebuild Workflow with Features; @see workflow.features.inc
   * - save Workflow programmatically;
   *
   * {@inheritdoc}
   */
  public function save() {
    $status = parent::save();
    // Make sure a Creation state exists, when saving a Workflow.
    if ($status == SAVED_NEW) {
      $this->createCreationState();
    }
    return $status;
  }

  /**
   * Given a wid, delete the workflow and its data.
   */
  public function delete() {
    if (!$this->isDeletable()) {
      $message = $this->t('Workflow %workflow is not Deletable. Please delete the field where this workflow type is referred',
        ['%workflow' => $this->label()]);
      $this->messenger()->addError($message);
      return;
    }
    else {
      // Delete associated state (also deletes any associated transitions).
      foreach ($this->getStates(WorkflowInterface::ALL_STATES) as $state) {
        $state->deactivate('');
        $state->delete();
      }

      // Delete the workflow.
      parent::delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isValid() {
    $is_valid = TRUE;

    // Don't allow Workflow without states.
    // There should always be a creation state.
    $states = $this->getStates();
    if (count($states) < 1) {
      // That's all, so let's remind them to create some states.
      $message = $this->t('Workflow %workflow has no states defined, so it cannot be assigned to content yet.',
        ['%workflow' => $this->label()]);
      $this->messenger()->addWarning($message);

      // Skip allowing this workflow.
      $is_valid = FALSE;
    }

    // Also check for transitions, at least out of the creation state.
    // Don't filter for roles.
    $transitions = $this->getTransitionsByStateId($this->getCreationSid(), '');
    if (count($transitions) < 1) {
      // That's all, so let's remind them to create some transitions.
      $message = $this->t('Workflow %workflow has no transitions defined, so it cannot be assigned to content yet.',
        ['%workflow' => $this->label()]);
      $this->messenger()->addWarning($message);

      // Skip allowing this workflow.
      $is_valid = FALSE;
    }

    return $is_valid;
  }

  /**
   * {@inheritdoc}
   */
  public function isDeletable() {
    // May not be deleted if assigned to a Field.
    foreach (_workflow_info_fields() as $field_info) {
      if ($field_info->getSetting('workflow_type') == $this->id()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Property functions.
   */

  /**
   * {@inheritdoc}
   */
  public function getWorkflowId() {
    return $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function createCreationState() {
    if (\Drupal::isConfigSyncing()) {
      // Do not create the default state while configuration are importing.
      return $this->creation_state;
    }

    if (!$this->creation_state) {
      $state = $this->createState(WorkflowState::CREATION_STATE_NAME);
      $this->creation_state = $state;
      $this->creation_sid = $state->id();
    }
    return $this->creation_state;
  }

  /**
   * {@inheritdoc}
   */
  public function createState($sid, $save = TRUE) {
    $state = $this->getState($sid);
    $wid = $this->id();
    if (!$state || $wid != $state->getWorkflowId()) {
      $values = ['id' => $sid, 'wid' => $wid];
      /** @var \Drupal\workflow\Entity\WorkflowState $state */
      $state = WorkflowState::create($values);
      if ($save) {
        $state->save();
      }
    }

    // Maintain the new object in the workflow.
    $this->states[$state->id()] = $state;

    return $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreationState() {

    // First, find it.
    if (!$this->creation_state) {
      foreach ($this->getStates(WorkflowInterface::ALL_STATES) as $state) {
        if ($state->isCreationState()) {
          $this->creation_state = $state;
          $this->creation_sid = $state->id();
        }
      }
    }
    // If not found, create it.
    $this->createCreationState();
    return $this->creation_state;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreationSid() {
    if (!$this->creation_sid) {
      $this->getCreationState();
    }
    return $this->creation_sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getFirstSid(EntityInterface $entity, $field_name, AccountInterface $user, $force = FALSE) {
    $creation_state = $this->getCreationState();
    $options = $creation_state->getOptions($entity, $field_name, $user, $force);
    if ($options) {
      $sid = array_key_first($options);
    }
    else {
      // This should never happen, but it did during testing.
      $this->messenger()->addError($this->t('There are no workflow states available. Please notify your site administrator.'));
      $sid = '';
    }
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextSid(EntityInterface $entity, $field_name, AccountInterface $user, $force = FALSE) {
    $current_state = WorkflowTargetEntity::getCurrentState($entity, $field_name);
    $options = $current_state->getOptions($entity, $field_name, $user, $force);
    $new_sid = $current_state->id();

    // Loop over every option to find the next one.
    $current_found = FALSE;
    foreach ($options as $sid => $state) {
      // If Creation state is not in options list, first option is next one.
      $current_found |= $current_state->isCreationState() && $sid !== $current_state->id();
      if ($current_found) {
        $new_sid = $sid;
        break;
      }
      $current_found = ($sid == $current_state->id());
    }

    return $new_sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getStates($all = FALSE, bool $reset = FALSE) {
    $states = [];

    // Initial population.
    if ($reset || empty($this->states)) {
      $wid = $this->id();
      $this->states = $wid ? WorkflowState::loadMultiple(NULL, $wid, $reset) : [];
    }

    // Now filter.
    // Do not unset, but add to array - you'll remove global objects otherwise.
    foreach ($this->states as $sid => $state) {
      if ($all === TRUE) {
        $states[$sid] = $state;
      }
      elseif (($all === FALSE) && ($state->isActive() && !$state->isCreationState())) {
        $states[$sid] = $state;
      }
      elseif (($all == WorkflowInterface::ACTIVE_CREATION_STATES) && ($state->isActive() || $state->isCreationState())) {
        $states[$sid] = $state;
      }
      else {
        // Do not add state.
      }
    }
    return $states;
  }

  /**
   * {@inheritdoc}
   */
  public function getState($sid) {
    $wid = $this->id();
    $state = $this->states[$sid] ?? NULL;
    if (!$wid || ($state && $wid == $state->getWorkflowId())) {
      return $state;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createTransition($from_sid, $to_sid, array $values = []) {
    $config_transition = NULL;

    // First check if this transition already exists.
    $transitions = $this->getTransitionsByStateId($from_sid, $to_sid);
    if ($transitions) {
      $config_transition = reset($transitions);
    }
    else {
      $values['wid'] = $this->id();
      $values['from_sid'] = $from_sid;
      $values['to_sid'] = $to_sid;
      $config_transition = WorkflowConfigTransition::create($values);
      $config_transition->save();
    }
    // Maintain the new object in the workflow.
    $this->transitions[$config_transition->id()] = $config_transition;

    return $config_transition;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransitions(?array $ids = NULL, array $conditions = []) {
    $config_transitions = [];

    // Cache all transitions in the workflow.
    if (!$this->transitions) {
      $this->transitions = WorkflowConfigTransition::loadMultiple($ids);
    }

    // Now filter on 'from' states, 'to' states, roles.
    $from_sid = $conditions['from_sid'] ?? FALSE;
    $to_sid = $conditions['to_sid'] ?? FALSE;
    // Get valid states + creation state.
    $states = $this->getStates(WorkflowInterface::ACTIVE_CREATION_STATES);
    foreach ($this->transitions as $id => &$config_transition) {
      if (!isset($states[$config_transition->getFromSid()])) {
        // Not a valid transition for this workflow. @todo Delete them.
      }
      elseif (is_array($ids) && !in_array($id, $ids)) {
        // Not the requested 'from' state.
      }
      elseif ($from_sid && $from_sid != $config_transition->getFromSid()) {
        // Not the requested 'from' state.
      }
      elseif ($to_sid && $to_sid != $config_transition->getToSid()) {
        // Not the requested 'to' state.
      }
      else {
        // Transition is allowed, permitted. Add to list.
        $config_transitions[$id] = $config_transition;
      }
    }
    return $config_transitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransitionsById($tid) {
    return $this->getTransitions([$tid]);
  }

  /**
   * {@inheritdoc}
   */
  public function getTransitionsByStateId($from_sid, $to_sid) {
    $conditions = [
      'from_sid' => $from_sid,
      'to_sid' => $to_sid,
    ];
    return $this->getTransitions(NULL, $conditions);
  }

  /*
   * The following is copied from interface PluginSettingsInterface.
   */

  /**
   * Whether default settings have been merged into the current $settings.
   *
   * @var bool
   */
  protected $defaultSettingsMerged = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'name_as_title' => 0,
      'fieldset' => 0,
      'options' => 'radios',
      'schedule_enable' => 1,
      'schedule_timezone' => 1,
      'always_update_entity' => 0,
      'comment_log_node' => '1',
      'watchdog_log' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings() {
    // Merge defaults before returning the array.
    if (!$this->defaultSettingsMerged) {
      $this->mergeDefaults();
    }
    return $this->options;
  }

  /**
   * {@inheritdoc}
   */
  public function getSetting($key) {
    // Merge defaults if we have no value for the key.
    if (!$this->defaultSettingsMerged && !array_key_exists($key, $this->options)) {
      $this->mergeDefaults();
    }
    return $this->options[$key] ?? NULL;
  }

  /**
   * Merges default settings values into $settings.
   */
  protected function mergeDefaults() {
    $this->options += static::defaultSettings();
    $this->defaultSettingsMerged = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setSettings(array $settings) {
    $this->options = $settings;
    $this->defaultSettingsMerged = FALSE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setSetting($key, $value) {
    $this->options[$key] = $value;
    return $this;
  }

}
