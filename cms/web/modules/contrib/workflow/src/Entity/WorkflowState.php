<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workflow\WorkflowTypeAttributeTrait;
use Drupal\workflow\WorkflowURLRouteParametersTrait;

/**
 * Workflow configuration entity to persistently store configuration.
 *
 * @ConfigEntityType(
 *   id = "workflow_state",
 *   label = @Translation("Workflow state"),
 *   label_singular = @Translation("Workflow state"),
 *   label_plural = @Translation("Workflow states"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Workflow state",
 *     plural = "@count Workflow states",
 *   ),
 *   module = "workflow",
 *   static_cache = TRUE,
 *   translatable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\workflow\WorkflowAccessControlHandler",
 *     "list_builder" = "Drupal\workflow\WorkflowStateListBuilder",
 *     "form" = {
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *      }
 *   },
 *   config_prefix = "state",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "weight" = "weight",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "module",
 *     "wid",
 *     "weight",
 *     "sysid",
 *     "status",
 *   },
 *   links = {
 *     "canonical" = "/admin/config/workflow/workflow/{workflow_type}",
 *     "collection" = "/admin/config/workflow/workflow/{workflow_type}/states",
 *   },
 * )
 */
class WorkflowState extends ConfigEntityBase implements WorkflowStateInterface {

  /*
   * Add variables and get/set methods for Workflow property.
   */
  use WorkflowTypeAttributeTrait;
  /*
   * Add translation trait.
   */
  use StringTranslationTrait;
  /*
   * Provide URL route parameters for entity links.
   */
  use WorkflowURLRouteParametersTrait;

  /*
   * The default weight for creation state, to have it on top of state list.
   */
  private const CREATION_DEFAULT_WEIGHT = -50;
  /*
   * The internal value to determine the creation state.
   */
  private const CREATION_STATE = 1;
  /*
   * A value to initially create 1 new creation state, once per Workflow type, without encoded workflow_type.
   */
  public const CREATION_STATE_NAME = 'creation';
  /*
   * The fixed to-be-translated label of the creation state.
   */
  private const CREATION_STATE_LABEL = 'Creation';

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
   * The weight of this Workflow state.
   *
   * @var int
   */
  public $weight;

  /**
   * The fixed System ID.
   *
   * @var int
   */
  public $sysid = 0;
  /**
   * Indicator if the State can be used or not.
   *
   * @var int
   */
  public $status = 1;

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
   * Constructs the object.
   *
   * @param array $values
   *   The list of values.
   * @param string $entity_type_id
   *   The name of the new State. If '(creation)', a CreationState is generated.
   */
  public function __construct(array $values = [], $entity_type_id = 'workflow_state') {
    $sid = $values['id'] ?? NULL;
    $values['label'] ??= $sid ? $sid : '';

    // Set default values for '(creation)' state.
    // This only happens when a creation state is explicitly created.
    if ($sid == self::CREATION_STATE_NAME) {
      $values['sysid'] = self::CREATION_STATE;
      $values['weight'] = self::CREATION_DEFAULT_WEIGHT;
      // Do not translate the machine_name.
      $values['label'] = $this->t(self::CREATION_STATE_LABEL);
    }
    parent::__construct($values, $entity_type_id);
  }

  /**
   * {@inheritdoc}
   *
   * Calls parent::label() and is used in workflow_allowed_state_names().
   */
  public function __toString() {
    $label = $this->t('@label', ['@label' => $this->label()])->__toString();
    return $label;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    // We cannot use $this->getWorkflow()->getConfigDependencyName() because
    // calling $this->getWorkflow() here causes an infinite loop.
    /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $workflow_type */
    $workflow_type = \Drupal::entityTypeManager()->getDefinition('workflow_type');
    $this->addDependency('config', "{$workflow_type->getConfigPrefix()}.{$this->getWorkflowId()}");
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Avoids error on WorkflowStateListBuilder:
   * "Cannot load the "workflow_state" entity with NULL ID."
   */
  public static function load($id) {
    return $id ? parent::load($id) : NULL;
  }

  /**
   * Get all states in the system, with options to filter, only where a workflow exists.
   *
   * {@inheritdoc}
   *
   * @param array $ids
   *   An array of State IDs, or NULL to load all states.
   * @param string $wid
   *   The requested Workflow ID.
   * @param bool $reset
   *   An option to refresh all caches.
   *
   * @return WorkflowState[]
   *   An array of cached states, keyed by state_id.
   *
   * @_deprecated WorkflowState::getStates() ==> WorkflowState::loadMultiple()
   */
  public static function loadMultiple(?array $ids = NULL, $wid = '', $reset = FALSE) {
    static $states = NULL;
    // Avoid PHP8.2 Error: Constant expression contains invalid operations.
    if ($states ??= parent::loadMultiple()) {
      // Sort the States on state weight.
      // @todo Sort States via 'orderby: weight' in schema file.
      uasort($states, [
        'Drupal\workflow\Entity\WorkflowState',
        'sort',
      ]);
    }

    // Filter on Wid, if requested, E.g., by Workflow->getStates().
    // Set the ID as array key.
    $result = [];
    // Make parameter $ids more robust.
    $ids ??= [];
    foreach ($states as $sid => $state) {
      /** @var \Drupal\workflow\Entity\WorkflowState $state */
      if (!$wid || ($state && $wid == $state->getWorkflowId())) {
        if (empty($ids) || in_array($sid, $ids)) {
          $result[$sid] = $state;
        }
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function save($create_creation_state = TRUE) {
    // Create the machine_name for new states.
    // N.B. Keep machine_name aligned in WorkflowState and ~ListBuilder.
    $sid = $this->id();
    $wid = $this->getWorkflowId();
    $label = $this->label();

    // Set the workflow-including machine_name.
    if ($sid == self::CREATION_STATE_NAME) {
      // Set the initial sid.
      $sid = implode('_', [$wid, $sid]);
      $this->set('id', $sid);
    }
    elseif (empty($sid)) {
      if ($label) {
        $transliteration = \Drupal::service('transliteration');
        $value = $transliteration->transliterate($label, LanguageInterface::LANGCODE_DEFAULT, '_');
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
        $sid = implode('_', [$wid, $value]);
      }
      else {
        workflow_debug(__FILE__, __FUNCTION__, __LINE__);  // @todo D8-port: still test this snippet.
        $sid = "state_$sid";
        $sid = implode('_', [$wid, $sid]);
      }
      $this->set('id', $sid);
    }

    return parent::save();
  }

  /**
   * {@inheritdoc}
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    /** @var \Drupal\workflow\Entity\WorkflowState $a */
    /** @var \Drupal\workflow\Entity\WorkflowState $b */
    $a_wid = $a->getWorkflowId();
    $b_wid = $b->getWorkflowId();
    $sort_order = $a_wid <=> $b_wid;
    if ($a_wid == $b_wid) {
      $a_weight = $a->getWeight();
      $b_weight = $b->getWeight();
      $sort_order = $a_weight <=> $b_weight;
    }
    return $sort_order;
  }

  /**
   * Deactivate a Workflow State, moving existing content to a given State.
   *
   * @param string $new_sid
   *   The state ID, to which all affected entities must be moved.
   */
  public function deactivate($new_sid) {

    $current_sid = $this->id();
    $force = TRUE;

    // Notify interested modules. We notify first to allow access to data before we zap it.
    // - re-parents any entity that we don't want to orphan, whilst deactivating a State.
    // - delete any lingering entity to state values.
    // \Drupal::moduleHandler()->invokeAll('workflow', ['state delete', $current_sid, $new_sid, NULL, $force]);
    // Invoke the hook.
    $entity_type_id = $this->getEntityTypeId();
    \Drupal::moduleHandler()->invokeAll("entity_{$entity_type_id}_predelete", [$this, $current_sid, $new_sid]);

    // Re-parent any entity that we don't want to orphan, whilst deactivating a State.
    // @todo D8-port: State should not know about Transition: move this to Workflow->DeactivateState.
    if ($new_sid) {
      // A candidate for the batch API.
      // @todo Future updates should seriously consider setting this with batch.
      $comment = $this->t('Previous state deleted');

      foreach (_workflow_info_fields() as $field_info) {
        $entity_type_id = $field_info->getTargetEntityTypeId();
        $field_name = $field_info->getName();

        $result = [];
        // CommentForm's are not re-parented upon Deactivate WorkflowState.
        if ($entity_type_id != 'comment') {
          $result = \Drupal::entityQuery($entity_type_id)
            ->condition($field_name, $current_sid, '=')
            ->accessCheck(FALSE)
            ->execute();
        }

        foreach ($result as $entity_id) {
          $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
          $transition = WorkflowTransition::create([$current_sid, 'field_name' => $field_name])
            ->setTargetEntity($entity)
            ->setValues($new_sid, NULL, NULL, $comment, TRUE)
            ->force($force);

          // Execute Transition, invoke 'pre' and 'post' events, save new state in Field-table, save also in workflow_transition_history.
          // For Workflow Node, only {workflow_node} and {workflow_transition_history} are updated. For Field, also the Entity itself.
          // Execute transition and update the attached entity.
          $new_sid = $transition->executeAndUpdateEntity($force);
        }
      }
    }

    // Delete the transitions this state is involved in.
    $workflow = Workflow::load($this->getWorkflowId());
    /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    foreach ($workflow->getTransitionsByStateId($current_sid, '') as $transition) {
      $transition->delete();
    }
    foreach ($workflow->getTransitionsByStateId('', $current_sid) as $transition) {
      $transition->delete();
    }

    // Delete the state. -- We don't actually delete, just deactivate.
    // This is a matter up for some debate, to delete or not to delete, since this
    // causes name conflicts for states. In the meantime, we just stick with what we know.
    // If you really want to delete the states, use workflow_cleanup module, or delete().
    $this->status = FALSE;
    $this->save();

    // Clear the cache.
    self::loadMultiple(NULL, '', TRUE);
  }

  /**
   * Property functions.
   */

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * Returns the Workflow object of this State.
   *
   * @return bool
   *   TRUE if state is active, else FALSE.
   */
  public function isActive() {
    return (bool) $this->status;
  }

  /**
   * Checks if the given state is the 'Create' state.
   *
   * @return bool
   *   TRUE if the state is the Creation state, else FALSE.
   */
  public function isCreationState() {
    return $this->sysid == self::CREATION_STATE;
  }

  /**
   * Returns the allowed transitions for the current state.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity at hand. May be NULL (E.g., on a Field settings page).
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account.
   * @param bool $force
   *   The force indicator.
   *
   * @return \Drupal\workflow\Entity\WorkflowConfigTransition[]
   *   An array of id=>transition pairs with allowed transitions for State.
   */
  public function getTransitions(?EntityInterface $entity = NULL, $field_name = '', ?AccountInterface $account = NULL, $force = FALSE) {
    $transitions = [];
    $workflow = $this->getWorkflow();

    if (!$workflow) {
      // No workflow, no options ;-)
      return $transitions;
    }

    // Load a User object, since we cannot add Roles to AccountInterface.
    if (!$user = workflow_current_user($account)) {
      // In some edge cases, no user is provided.
      return $transitions;
    }

    // N.B. Keep aligned between WorkflowState, ~Transition, ~HistoryAccess.
    $type_id = $this->getWorkflowId();
    if ($user->hasPermission("bypass $type_id workflow_transition access")) {
      // Superuser is special (might be cron).
      // And $force allows Rules to cause transition.
      $force = TRUE;
    }

    /*
     * Get user's permissions.
     */
    // Determine if user is owner of the entity. If so, add role.
    $is_owner = WorkflowRole::isOwner($user, $entity);
    if ($is_owner) {
      $user->addRole(WorkflowRole::AUTHOR_RID);
    }

    /*
     * Determine if user has Access to each transition.
     */
    $transitions = $workflow->getTransitionsByStateId($this->id(), '');
    foreach ($transitions as $key => $transition) {
      if (!$transition->isAllowed($user, $force)) {
        unset($transitions[$key]);
      }
    }
    // Let custom code add/remove/alter the available transitions,
    // using the new drupal_alter.
    // Modules may veto a choice by removing a transition from the list.
    // Lots of data can be fetched via the $transition object.
    $context = [
      'entity' => $entity, // ConfigEntities do not have entity attached.
      'field_name' => $field_name, // Or field.
      'user' => $user, // User may have the custom WorkflowRole::AUTHOR_RID.
      'workflow' => $workflow,
      'state' => $this,
      'force' => $force,
    ];
    \Drupal::moduleHandler()->alter('workflow_permitted_state_transitions', $transitions, $context);

    return $transitions;
  }

  /**
   * Returns the allowed values for the current state.
   *
   * @param object|null $entity
   *   The entity at hand. May be NULL (E.g., on a Field settings page).
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user object.
   * @param bool $force
   *   The force indicator.
   * @param bool $use_cache
   *   The indicator to use earlier, cached, results.
   *
   * @return array
   *   An array of [sid => transition->label()] pairs.
   *   If $this->id() is set, returns the allowed transitions from this state.
   *   If $this->id() is 0 or FALSE, then labels of ALL states of the State's
   *   Workflow are returned.
   */
  public function getOptions($entity, $field_name, ?AccountInterface $account = NULL, $force = FALSE, $use_cache = TRUE) {
    $options = [];
    $transitions = NULL;

    $current_sid = $this->id();

    // Define an Entity-specific cache per page load.
    static $cache = [];
    // Get options from page cache, using a non-empty index (just to be sure).
    $entity_type_index = ($entity) ? $entity->getEntityTypeId() : 'x';
    $entity_index = ($entity) ? $entity->id() ?? 'x' : 'x';
    $sid_index = $current_sid ?? 'x';
    if ($use_cache && isset($cache[$entity_type_index][$entity_index][$force][$sid_index])) {
      $options = $cache[$entity_type_index][$entity_index][$force][$sid_index];
      return $options;
    }

    $workflow = $this->getWorkflow();
    if (!$workflow) {
      // No workflow, no options ;-)
      return $options;
    }

    if (!$current_sid) {
      // If no State ID is given (on Field settings page), we return all states.
      // We cannot use getTransitions, since there are no ConfigTransitions
      // from State with ID 0, and we do not want to repeat States.
      // @see https://www.drupal.org/project/workflow/issues/3119998
      // @see WorkflowState::__toString().
      $options = $workflow->getStates(WorkflowInterface::ACTIVE_CREATION_STATES);
    }
    elseif ($current_sid) {
      // This is called by FormatterBase->view();
      // which calls WorkflowItem->getPossibleOptions();
      $transitions = $this->getTransitions($entity, $field_name, $account, $force);
    }
    elseif ($entity->{$field_name}->value ?? NULL) {
      // Note Avoid recursive calling.
      // @todo Is this code now obsolete in v1.19?
      $transition = $entity->{$field_name}->first()?->getTransition();
      $transitions = $this->getTransitions($transition, $field_name, $account, $force);
    }
    else {
      // Empty field. Entity is created before enabling Workflow module.
      $options = $workflow->getStates();
    }

    // Return the transitions (for better label()), with state ID.
    if (is_array($transitions)) {
      foreach ($transitions as $transition) {
        $to_sid = $transition->to_sid;
        // @see WorkflowConfigTransition::__toString().
        // When Transition->to_sid is 'entity_reference',
        // do string conversion here, to avoid Error:
        // "Call to undefined method WorkflowConfigTransition::render()
        // "in Drupal\Core\Template\Attribute->__toString()
        // (line 329 of \Drupal\Core\Template\Attribute.php).
        $options[$to_sid] = (string) $transition;
      }
    }

    // Save to entity-specific cache.
    $cache[$entity_type_index][$entity_index][$force][$sid_index] = $options;

    return $options;
  }

  /**
   * Returns the number of entities with this state.
   *
   * @return int
   *   Counted number.
   *
   * @todo Add $options to select on entity type, etc.
   */
  public function count() {
    $count = 0;
    $sid = $this->id();

    foreach (_workflow_info_fields() as $field_info) {
      $field_name = $field_info->getName();
      $query = \Drupal::entityQuery($field_info->getTargetEntityTypeId());
      $count += $query
        ->condition($field_name, $sid, '=')
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }

    return $count;
  }

}
