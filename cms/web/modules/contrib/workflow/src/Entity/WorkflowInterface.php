<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines a common interface for Workflow*Transition* objects.
 *
 * @see \Drupal\workflow\Entity\WorkflowConfigTransition
 * @see \Drupal\workflow\Entity\WorkflowTransition
 * @see \Drupal\workflow\Entity\WorkflowScheduledTransition
 */
interface WorkflowInterface extends EntityInterface {

  // In getStates(), return all states including Creation and Inactive.
  public const ALL_STATES = TRUE;
  // In getStates(), return only Active states, not Creation.
  public const ACTIVE_STATES = FALSE;
  // In getStates(), return the Active states, including Creation.
  public const ACTIVE_CREATION_STATES = 'CREATION';

  /**
   * Returns the workflow id.
   *
   * @return string
   *   $wid
   */
  public function getWorkflowId();

  /**
   * Validate the workflow. Generate a message if not correct.
   *
   * This function is used on the settings page of:
   * - Workflow field: WorkflowItem->settingsForm()
   *
   * @return bool
   *   $is_valid
   */
  public function isValid();

  /**
   * Returns if the Workflow may be deleted.
   *
   * @return bool
   *   TRUE if a Workflow may safely be deleted.
   */
  public function isDeletable();

  /**
   * Creates the initial state for a new Workflow.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The initial state.
   */
  public function createCreationState();

  /**
   * Create a new state for this workflow.
   *
   * @param string $sid
   *   The new state ID.
   * @param bool $save
   *   Indicator if the new state must be saved. Normally, the new State is
   *   saved directly in the database. This is because you can use States only
   *   with Transitions, and they rely on State IDs which are generated
   *   magically when saving the State. But you may need a temporary state.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The new state.
   */
  public function createState($sid, $save = TRUE);

  /**
   * Gets the initial state for a newly created entity.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The initial state.
   */
  public function getCreationState();

  /**
   * Gets the ID of the initial state for a newly created entity.
   *
   * @return string
   *   The ID of the state.
   */
  public function getCreationSid();

  /**
   * Gets the first valid state ID, after the creation state.
   *
   * Uses WorkflowState::getOptions(), because this does an access check.
   * The first State ID is user-dependent!
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity at hand. May be NULL (E.g., on a Field settings page).
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param bool $force
   *   Indicator to force the transition.
   *
   * @return string
   *   A State ID.
   */
  public function getFirstSid(EntityInterface $entity, $field_name, AccountInterface $user, $force = FALSE);

  /**
   * Returns the next state for the current state.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity at hand.
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param bool $force
   *   Indicator to force the transition.
   *
   * @return string
   *   A State ID.
   *
   * @usage Is used in VBO Bulk actions.
   */
  public function getNextSid(EntityInterface $entity, $field_name, AccountInterface $user, $force = FALSE);

  /**
   * Gets all states for a given workflow.
   *
   * @param mixed $all
   *   Indicates to which states to return.
   *   - TRUE = all states, including Creation and Inactive;
   *   - FALSE = only Active states, not Creation;
   *   - 'CREATION' = Active states, including Creation.
   * @param bool $reset
   *   An option to refresh all caches.
   *
   * @return \Drupal\workflow\Entity\WorkflowState[]
   *   An array of WorkflowState objects.
   */
  public function getStates($all = WorkflowInterface::ACTIVE_STATES, bool $reset = FALSE);

  /**
   * Gets a state for a given workflow.
   *
   * @param string $sid
   *   A state ID.
   *
   * @return \Drupal\workflow\Entity\WorkflowState|null
   *   A WorkflowState object.
   */
  public function getState($sid);

  /**
   * Creates a Transition for this workflow.
   *
   * @param string $from_sid
   *   The From State ID.
   * @param string $to_sid
   *   The To State ID.
   * @param array $values
   *   The list of new values.
   *
   * @return \Drupal\workflow\Entity\WorkflowConfigTransitionInterface
   *   The created Transition.
   */
  public function createTransition($from_sid, $to_sid, array $values = []);

  /**
   * Loads all allowed ConfigTransitions for this workflow.
   *
   * @param array|null $ids
   *   An array of Transition IDs. If NULL, show all transitions.
   * @param array $conditions
   *   $conditions['from_sid']: if provided, a 'from' State ID.
   *   $conditions['to_sid']: if provided, a 'to' state ID.
   *
   * @return \Drupal\workflow\Entity\WorkflowConfigTransition[]
   *   A list of Config Transitions.
   */
  public function getTransitions(?array $ids = NULL, array $conditions = []);

  /**
   * Loads all allowed ConfigTransitions for this workflow, filtered by ID.
   *
   * @param int $tid
   *   Transition ID.
   *
   * @return \Drupal\workflow\Entity\WorkflowConfigTransition[]
   *   A list of Config Transitions.
   */
  public function getTransitionsById($tid);

  /**
   * Get a specific transition.
   *
   * @param string $from_sid
   *   The From State ID.
   * @param string $to_sid
   *   The To State ID.
   *
   * @return \Drupal\workflow\Entity\WorkflowConfigTransition[]
   *   A list of Config Transitions.
   */
  public function getTransitionsByStateId($from_sid, $to_sid);

  /*
   * The following are copied from PluginSettingsInterface.php.
   */

  /**
   * Defines the default settings for this plugin.
   *
   * @return array
   *   A list of default settings, keyed by the setting name.
   */
  public static function defaultSettings();

  /**
   * Returns the array of settings, including defaults for missing settings.
   *
   * @return array
   *   The array of settings.
   */
  public function getSettings();

  /**
   * Returns the value of a setting, or its default value if absent.
   *
   * @param string $key
   *   The setting name.
   *
   * @return mixed
   *   The setting value.
   */
  public function getSetting($key);

  /**
   * Sets the settings for the plugin.
   *
   * @param array $settings
   *   The array of settings, keyed by setting names. Missing settings will be
   *   assigned their default values.
   *
   * @return $this
   */
  public function setSettings(array $settings);

  /**
   * Sets the value of a setting for the plugin.
   *
   * @param string $key
   *   The setting name.
   * @param mixed $value
   *   The setting value.
   *
   * @return $this
   */
  public function setSetting($key, $value);

}
