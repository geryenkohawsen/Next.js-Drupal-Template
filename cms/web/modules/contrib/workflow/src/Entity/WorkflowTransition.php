<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;
use Drupal\workflow\Event\WorkflowEvents;
use Drupal\workflow\Event\WorkflowTransitionEvent;
use Drupal\workflow\Hook\WorkflowEntityHooks;
use Drupal\workflow\WorkflowTypeAttributeTrait;

/**
 * Implements an actual, executed, Transition.
 *
 * If a transition is executed, the new state is saved in the Field.
 * If a transition is saved, it is saved in table {workflow_transition_history}.
 *
 * @ContentEntityType(
 *   id = "workflow_transition",
 *   label = @Translation("Workflow transition"),
 *   label_singular = @Translation("Workflow transition"),
 *   label_plural = @Translation("Workflow transitions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Workflow transition",
 *     plural = "@count Workflow transitions",
 *   ),
 *   bundle_label = @Translation("Workflow type"),
 *   module = "workflow",
 *   translatable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\workflow\WorkflowAccessControlHandler",
 *     "list_builder" = "Drupal\workflow\WorkflowTransitionListBuilder",
 *     "form" = {
 *        "add" = "Drupal\workflow\Form\WorkflowTransitionForm",
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *        "edit" = "Drupal\workflow\Form\WorkflowTransitionForm",
 *        "revert" = "Drupal\workflow\Form\WorkflowTransitionRevertForm",
 *      },
 *     "views_data" = "Drupal\workflow\WorkflowTransitionViewsData",
 *   },
 *   base_table = "workflow_transition_history",
 *   entity_keys = {
 *     "id" = "hid",
 *     "bundle" = "wid",
 *     "langcode" = "langcode",
 *     "owner" = "uid",
 *   },
 *   permission_granularity = "bundle",
 *   bundle_entity_type = "workflow_type",
 *   field_ui_base_route = "entity.workflow_type.edit_form",
 *   links = {
 *     "canonical" = "/workflow_transition/{workflow_transition}",
 *     "delete-form" = "/workflow_transition/{workflow_transition}/delete",
 *     "edit-form" = "/workflow_transition/{workflow_transition}/edit",
 *     "revert-form" = "/workflow_transition/{workflow_transition}/revert",
 *   },
 * )
 */
class WorkflowTransition extends ContentEntityBase implements WorkflowTransitionInterface {

  /*
   * Adds the messenger trait.
   */
  use MessengerTrait;

  /*
   * Adds the translation trait.
   */
  use StringTranslationTrait;

  /*
   * Adds the trait for getOwner(), setOwner() functions.
   */
  use EntityOwnerTrait;
  /*
   * Adds variables and get/set methods for Workflow property.
   */
  use WorkflowTypeAttributeTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /*
   * Transition data: are provided via baseFieldDefinitions().
   */

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isScheduled = FALSE;

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isExecuted = FALSE;

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isForced = FALSE;

  /**
   * Entity class functions.
   */

  /**
   * Creates a new entity.
   *
   * No arguments passed, when loading from DB.
   * All arguments must be passed, when creating an object programmatically.
   * One argument $entity may be passed, only to directly call delete() afterwards.
   *
   * {@inheritdoc}
   *
   * @see entity_create()
   */
  public function __construct(array $values = [], $entity_type_id = 'workflow_transition', $bundle = FALSE, array $translations = []) {
    parent::__construct($values, $entity_type_id, $bundle, $translations);
    $this->eventDispatcher = \Drupal::service('event_dispatcher');
    // This transition is not scheduled.
    $this->isScheduled = FALSE;
    // This transition is not executed, if it has no hid, yet, upon load.
    $this->isExecuted = ($this->id() > 0);
  }

  /**
   * {@inheritdoc}
   *
   * @param array $values
   *   An array of values to set, keyed by property name.
   *   A value for the 'field_name' is required.
   *   Also either state ID ('from_sid') or targetEntity ('entity').
   */
  public static function create(array $values = []) {
    $transition = NULL;

    // First parameter must be State object or State ID.
    $state = $values[0] ?? NULL;
    unset($values[0]);
    if (is_string($state)) {
      $state = WorkflowState::load($state);
    }

    if ($state instanceof WorkflowState) {
      /** @var \Drupal\workflow\Entity\WorkflowState $state */
      $values['wid'] ??= $state ? $state->getWorkflowId() : '';
      $values['from_sid'] ??= $state ? $state->id() : '';
    }

    $entity = $values['entity'] ?? NULL;
    if ($entity) {
      unset($values['entity']);
      $field_name = $values['field_name'];
      $values['wid'] = $entity->{$field_name}->getSetting('workflow_type');
      // @todo Use baseFieldDefinition::allowed_values_function,
      // but problem with entity creation, hence added explicitly here.
      $values['from_sid'] = workflow_node_current_state($entity, $field_name);
      // Overwrite 'entity_id' with Object. Strange, but identical to 'uid'.
      // An entity reference,
      // which allows to access entity with $transition->entity_id->entity
      // and to access the entity id with $transition->entity_id->target_id.
      $values['entity_id'] = $entity;
      $values['entity_type'] = $entity->getEntityTypeId();
    }

    // Additional default values are defined in baseFieldDefinitions().
    $transition = parent::create($values);
    return $transition;
  }

  /**
   * {@inheritdoc}
   */
  public function createDuplicate($new_class_name = WorkflowTransition::class) {
    $field_name = $this->getFieldName();
    $from_sid = $this->getFromSid();

    $duplicate = $new_class_name::create([$from_sid, 'field_name' => $field_name]);
    $duplicate->setTargetEntity($this->getTargetEntity());
    $duplicate->setValues($this->getToSid(), $this->getOwnerId(), $this->getTimestamp(), $this->getComment());
    $duplicate->force($this->isForced());
    return $duplicate;
  }

  /**
   * {@inheritdoc}
   */
  public function setValues($to_sid, $uid = NULL, $timestamp = NULL, $comment = NULL, $force_create = FALSE) {
    // Normally, the values are passed in an array, and set in parent::__construct, but we do it ourselves.
    $from_sid = $this->getFromSid();

    $this->set('to_sid', $to_sid);
    if ($uid !== NULL) {
      $this->setOwnerId($uid);
    }
    if ($timestamp !== NULL) {
      $this->setTimestamp($timestamp);
    }
    if ($comment !== NULL) {
      $this->setComment($comment);
    }

    // If constructor is called with new() and arguments.
    if (!$from_sid && !$to_sid && !$this->getTargetEntity()) {
      // If constructor is called without arguments, e.g., loading from db.
    }
    elseif ($from_sid && $this->getTargetEntity()) {
      // Caveat: upon entity_delete, $to_sid is '0'.
      // If constructor is called with new() and arguments.
    }
    elseif ($from_sid === NULL) {
      // Not all parameters are passed programmatically.
      if (!$force_create) {
        $this->messenger()->addError(
          $this->t('Wrong call to constructor Workflow*Transition(%from_sid to %to_sid)',
            ['%from_sid' => $from_sid, '%to_sid' => $to_sid]));
      }
    }

    return $this;
  }

  /**
   * CRUD functions.
   */

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    $field_name = $this->getFieldName();
    $entity = $this->getTargetEntity();
    WorkflowEntityHooks::deleteTransitionsOfEntity($entity, 'workflow_scheduled_transition', $field_name);
  }

  /**
   * Saves the entity.
   *
   * Mostly, you'd better use WorkflowTransitionInterface::execute().
   *
   * {@inheritdoc}
   */
  public function save() {

    if ($this->isScheduled()
      && $this::class == WorkflowTransition::class) {
      // Convert/cast/wrap Transition to ScheduledTransition.
      $transition = $this->createDuplicate(WorkflowScheduledTransition::class);
      $result = $transition->save();
      return $result;
    }

    // @todo $entity->revision_id is NOT SET when coming from node/XX/edit !!
    $field_name = $this->getFieldName();
    $entity = $this->getTargetEntity();
    $entity->getRevisionId();

    // Set Target Entity, to be used by Rules.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $reference */
    if ($reference = $this->get('entity_id')->first()) {
      $reference->set('entity', $entity);
    }

    $this->dispatchEvent(WorkflowEvents::PRE_TRANSITION);

    switch (TRUE) {
      case $this->isEmpty():
        // Empty transition.
        $result = SAVED_UPDATED;
        break;

      case $this->id():
        // Update the transition. It already exists.
        $result = parent::save();
        break;

      case $this->isScheduled():
      case $this->getEntityTypeId() == 'workflow_scheduled_transition':
        // Avoid custom actions for subclass WorkflowScheduledTransition.
        $result = parent::save();
        break;

      default:
        // Insert the executed transition, unless it has already been inserted.
        // Note: this might be outdated due to code improvements.
        // @todo Allow a scheduled transition per revision.
        // @todo Allow a state per language version (langcode).
        $same_transition = self::loadByProperties($entity->getEntityTypeId(), $entity->id(), [], $field_name);
        if ($same_transition &&
          $same_transition->getTimestamp() == \Drupal::time()->getRequestTime() &&
          $same_transition->getToSid() == $this->getToSid()) {
          $result = SAVED_UPDATED;
        }
        $result = parent::save();
        break;
    }

    $this->dispatchEvent(WorkflowEvents::POST_TRANSITION);
    \Drupal::moduleHandler()->invokeAll('workflow', ['transition post', $this, $this->getOwner()]);
    $this->addPostSaveMessage();

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function dispatchEvent($event_name) {
    $transition_event = new WorkflowTransitionEvent($this);
    $this->eventDispatcher->dispatch($transition_event, $event_name);
    return $this;
  }

  /**
   * Generates a message after the Transition has been saved.
   */
  protected function addPostSaveMessage() {
    if (!empty($this->getWorkflow()->getSetting('watchdog_log'))) {
      return $this;
    }

    if ($this->isExecuted() && $this->hasStateChange()) {
      // Register state change with watchdog.
      $message = match ($this->getEntityTypeId()) {
        'workflow_scheduled_transition'
        => 'Scheduled state change of @entity_type_label %entity_label to %sid2 executed',
        default
        => 'State of @entity_type_label %entity_label set to %sid2',
      };
      $this->logError($message, 'notice');
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * @return WorkflowTransitionInterface|null
   *   A WorkflowTransition, if found. Else NULL.
   */
  public static function loadByProperties($entity_type_id, $entity_id, array $revision_ids = [], $field_name = '', $langcode = '', $sort = 'ASC', $transition_type = 'workflow_transition') {
    $limit = 1;
    $transitions = self::loadMultipleByProperties($entity_type_id, [$entity_id], $revision_ids, $field_name, $langcode, $limit, $sort, $transition_type);
    if ($transitions) {
      $transition = reset($transitions);
      return $transition;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function loadMultipleByProperties($entity_type_id, array $entity_ids, array $revision_ids = [], $field_name = '', $langcode = '', $limit = NULL, $sort = 'ASC', $transition_type = 'workflow_transition') {

    /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
    $query = \Drupal::entityQuery($transition_type)
      ->condition('entity_type', $entity_type_id)
      ->accessCheck(FALSE)
      ->sort('timestamp', $sort)
      ->addTag($transition_type);
    if (!empty($entity_ids)) {
      $query->condition('entity_id', $entity_ids, 'IN');
    }
    if (!empty($revision_ids)) {
      $query->condition('revision_id', $entity_ids, 'IN');
    }
    if ($field_name != '') {
      $query->condition('field_name', $field_name, '=');
    }
    if ($langcode != '') {
      $query->condition('langcode', $langcode, '=');
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    if ($transition_type == 'workflow_transition') {
      $query->sort('hid', 'DESC');
    }
    $ids = $query->execute();
    $transitions = $ids ? self::loadMultiple($ids) : [];
    return $transitions;
  }

  /**
   * Implementing interface WorkflowTransitionInterface - properties.
   */

  /**
   * Determines if the Transition is valid and can be executed.
   *
   * @todo Add to isAllowed() ?
   * @todo Add checks to WorkflowTransitionElement ?
   *
   * @return bool
   *   TRUE is the Transition is OK, else FALSE.
   */
  public function isValid() {
    $valid = TRUE;
    // Load the entity, if not already loaded.
    // This also sets the (empty) $revision_id in Scheduled Transitions.
    $entity = $this->getTargetEntity();

    if (!$entity) {
      // @todo There is a watchdog error, but no UI-error. Is this OK?
      $message = 'User tried to execute a Transition without an entity.';
      $this->logError($message);
      $valid = FALSE;
    }
    elseif (!$this->getFromState()) {
      // @todo The page is not correctly refreshed after this error.
      $message = $this->t('You tried to set a Workflow State, but
        the entity is not relevant. Please contact your system administrator.');
      $this->messenger()->addError($message);
      $message = 'Setting a non-relevant Entity from state %sid1 to %sid2';
      $this->logError($message);
      $valid = FALSE;
    }

    return $valid;
  }

  /**
   * Check if all fields in the Transition are empty.
   *
   * @return bool
   *   TRUE if the Transition is empty.
   */
  protected function isEmpty() {
    if ($this->hasStateChange()) {
      return FALSE;
    }
    if ($this->getComment()) {
      return FALSE;
    }
    $attached_fields = $this->getAttachedFields();
    foreach ($attached_fields as $field_name => $field) {
      if (isset($this->{$field_name}) && !$this->{$field_name}->isEmpty()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed(UserInterface $user, $force = FALSE) {

    if ($force) {
      return TRUE;
    }

    /*
     * Get user's permissions.
     */
    // N.B. Keep aligned between WorkflowState, ~Transition, ~HistoryAccess.
    $type_id = $this->getWorkflowId();
    if ($user->hasPermission("bypass $type_id workflow_transition access")) {
      // Superuser is special (might be cron).
      // And $force allows Rules to cause transition.
      return TRUE;
    }
    // Determine if user is owner of the entity.
    $is_owner = WorkflowRole::isOwner($user, $this->getTargetEntity());
    if ($is_owner) {
      $user->addRole(WorkflowRole::AUTHOR_RID);
    }

    /*
     * Get the object and its permissions.
     */
    $from_sid = $this->getFromSid();
    $to_sid = $this->getToSid();
    $config_transitions = $this->getWorkflow()->getTransitionsByStateId($from_sid, $to_sid);

    /*
     * Determine if user has Access.
     */
    $result = FALSE;
    foreach ($config_transitions as $config_transition) {
      $result = $result || $config_transition->isAllowed($user, $force);
    }

    if ($result == FALSE) {
      // @todo There is a watchdog error, but no UI-error. Is this OK?
      $message = "Attempt to go to nonexistent transition (from $from_sid to $to_sid)";
      $this->logError($message);
    }

    return $result;
  }

  /**
   * Determines if the State changes by this Transition.
   *
   * @return bool
   *   TRUE if from and to State ID's are different.
   */
  public function hasStateChange() {
    return $this->getFromSid() !== $this->getToSid();
  }

  /**
   * {@inheritdoc}
   */
  public function execute($force = FALSE) {
    // Load the entity, if not already loaded.
    // This also sets the (empty) $revision_id in Scheduled Transitions.
    $entity = $this->getTargetEntity();

    $user = $this->getOwner();
    $from_sid = $this->getFromSid();
    $to_sid = $this->getToSid();
    $field_name = $this->getFieldName();
    $comment = $this->getComment();

    static $static_info = NULL;
    $entity_id = $entity->id();
    // For non-default revisions, there is no way of executing the same transition twice in one call.
    // Set a random identifier since we won't be needing to access this variable later.
    if ($entity instanceof RevisionableInterface) {
      /** @var \Drupal\Core\Entity\RevisionableInterface $entity */
      if (!$entity->isDefaultRevision()) {
        $entity_id .= $entity->getRevisionId();
      }
    }
    // Create a label to identify this transition,
    // even upon insert, when id() is not set, yet.
    $label = "{$from_sid}-{$to_sid}";
    if (isset($static_info[$entity_id][$field_name][$label]) && !$this->isEmpty()) {
      // Error: this Transition is already executed.
      // On the development machine, execute() is called twice, when
      // on an Edit Page, the entity has a scheduled transition, and
      // user changes it to 'immediately'.
      // Why does this happen?? ( BTW. This happens with every submit.)
      // Remedies:
      // - search root cause of second call.
      // - try adapting code of transition->save() to avoid second record.
      // - avoid executing twice.
      $message = 'Transition is executed twice in a call. The second call for
        @entity_type %entity_id is not executed.';
      $this->logError($message);

      // Return the result of the last call.
      return $static_info[$entity_id][$field_name][$label]; // <-- exit !!!
    }
    // OK. Prepare for next round. Do not set last_sid!!
    $static_info[$entity_id][$field_name][$label] = $from_sid;

    // Make sure $force is set in the transition, too.
    if ($force) {
      $this->force($force);
    }
    $force = $this->isForced();

    // Store the transition(s), so it can be easily fetched later on.
    // This is a.o. used in:
    // - hook_entity_update to trigger 'transition post',
    // - hook workflow_access_node_access_records.
    $entity->workflow_transitions[$field_name] = $this;

    if (!$this->isValid()) {
      return $from_sid;  // <-- exit !!!
    }

    // @todo Move below code to $this->isAllowed().
    // If the state has changed, check the permissions.
    // No need to check if Comments or attached fields are filled.
    if ($this->hasStateChange()) {
      // Determine if user is owner of the entity. If so, add role.
      $is_owner = WorkflowRole::isOwner($user, $entity);
      if ($is_owner) {
        $user->addRole(WorkflowRole::AUTHOR_RID);
      }
      if (!$this->isAllowed($user, $force)) {
        $message = 'User %user not allowed to go from state %sid1 to %sid2';
        $this->logError($message);
        return FALSE;  // <-- exit !!!
      }

      // Make sure this transition is valid and allowed for the current user.
      // Invoke a callback indicating a transition is about to occur.
      // Modules may veto the transition by returning FALSE.
      // (Even if $force is TRUE, but they shouldn't do that.)
      // P.S. The D7 hook_workflow 'transition permitted' is removed,
      // in favour of below hook_workflow 'transition pre'.
      $permitted = \Drupal::moduleHandler()->invokeAll('workflow', ['transition pre', $this, $user]);
      // Stop if a module says so.
      if (in_array(FALSE, $permitted, TRUE)) {
        // @todo There is a watchdog error, but no UI-error. Is this OK?
        $message = 'Transition vetoed by module.';
        $this->logError($message, 'notice');
        return FALSE;  // <-- exit !!!
      }
    }

    /*
     * Output: process the transition.
     */
    if ($this->isScheduled()) {
      // Log the transition in {workflow_transition_scheduled}.
      $this->save();
    }
    else {
      // The transition is allowed, but not scheduled.
      // Let other modules modify the comment.
      // The transition (in context) contains all relevant data.
      $context = ['transition' => $this];
      \Drupal::moduleHandler()->alter('workflow_comment', $comment, $context);
      $this->setComment($comment);

      $this->isExecuted = TRUE;

      if (!$this->isEmpty()) {
        // Save the transition in {workflow_transition_history}.
        $this->save();
      }
    }

    // Save value in static from top of this function.
    $static_info[$entity_id][$field_name][$label] = $to_sid;

    return $to_sid;
  }

  /**
   * {@inheritdoc}
   */
  public function executeAndUpdateEntity($force = FALSE) {
    $entity = $this->getTargetEntity();
    $to_sid = $this->getToSid();

    // Generate error and stop if transition has no new State.
    if (!$to_sid) {
      $t_args = [
        '%sid2' => $this->getToState()->label(),
        '%entity_label' => $entity->label(),
      ];
      $message = "Transition is not executed for %entity_label, since 'To' state %sid2 is invalid.";
      $this->logError($message);
      $this->messenger()->addError($this->t($message, $t_args));

      return $this->getFromSid();
    }

    // Save the (scheduled) transition.
    if ($this->isScheduled()) {
      $this->save();
      $to_sid = $this->getFromSid();
    }
    elseif ($this->isExecuted()) {
      // We create a new transition, or update an existing one.
      // Do not update the entity itself.
      // Validate transition, save in history table and delete from schedule table.
      $to_sid = $this->execute($force);
    }
    else {
      // Update targetEntity's itemList with the workflow field in two formats.
      $to_sid = $this->getFromSid();
      $this->setEntityWorkflowField();
      $this->setEntityChangedTime();
      if ($entity->save()) {
        $to_sid = $this->getToSid();
      }
    }

    return $to_sid;
  }

  /**
   * Updates the entity's workflow field with value and transition.
   */
  public function setEntityWorkflowField(): WorkflowTransitionInterface {
    $entity = $this->getTargetEntity();
    $field_name = $this->getFieldName();
    // Set the Transition to the field. This also sets the value to State ID.
    $entity->{$field_name}->setValue($this);
    return $this;
  }

  /**
   * Update the Entity's ChangedTime when the option is set.
   */
  public function setEntityChangedTime(): WorkflowTransitionInterface {
    if ($this->getWorkflow()->getSetting('always_update_entity')) {
      $entity = $this->getTargetEntity();
      // Copied from EntityFormDisplay::updateChangedTime(EntityInterface $entity) {
      if ($entity instanceof EntityChangedInterface) {
        $entity->setChangedTime($this->getTimestamp());
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntity(EntityInterface $entity) {
    $this->entity_type = '';
    $this->entity_id = NULL;
    $this->revision_id = '';
    $this->langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED;

    // If Transition is added via CommentForm, use the Commented Entity.
    if ($entity && $entity->getEntityTypeId() == 'comment') {
      /** @var \Drupal\comment\CommentInterface $entity */
      $entity = $entity->getCommentedEntity();
    }

    if ($entity) {
      $this->set('entity_id', $entity);
      /** @var \Drupal\Core\Entity\RevisionableContentEntityBase $entity */
      $this->entity_type = $entity->getEntityTypeId();
      $this->entity_id = $entity;
      $this->revision_id = $entity->getRevisionId();
      $this->langcode = $entity->language()->getId();
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntity() {
    $entity = $this->entity_id->entity;
    if ($entity) {
      return $entity;
    }

    $entity_id = $this->entity_id->target_id;
    if ($entity_id ??= $this->getTargetEntityId()) {
      $entity_type_id = $this->getTargetEntityTypeId();
      $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
      $this->entity_id = $entity;
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityId() {
    return $this->get('entity_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId() {
    return $this->get('entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldName() {
    return $this->get('field_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode() {
    return $this->getTargetEntity()->language()->getId();

  }

  /**
   * {@inheritdoc}
   */
  public function getFromState(): ?WorkflowState {
    $sid = $this->getFromSid();
    $state = $this->{'from_sid'}->entity ?? NULL;
    $state ??= $this->getWorkflow()->getState($sid);
    return $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getToState(): ?WorkflowState {
    $sid = $this->getToSid();
    $state = $this->{'to_sid'}->entity ?? NULL;
    $state ??= $this->getWorkflow()->getState($sid);
    return $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getFromSid(): string {
    // BaseField is defined as 'list_string'.
    $sid = $this->{'from_sid'}->value ?? NULL;
    // BaseField is defined as 'entity_reference'.
    $sid ??= $this->{'from_sid'}->target_id ?? '';
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getToSid(): string {
    // BaseField is defined as 'list_string'.
    $sid = $this->{'to_sid'}->value ?? NULL;
    // BaseField is defined as 'entity_reference'.
    $sid ??= $this->{'to_sid'}->target_id ?? '';
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getComment() {
    return $this->get('comment')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setComment($value) {
    $this->set('comment', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function getDefaultRequestTime(WorkflowTransition $transition, BaseFieldDefinition $definition) {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public static function getDefaultStateId(WorkflowTransition $transition, BaseFieldDefinition $definition) {
    $sid = '';

    switch ($definition->getName()) {
      case 'from_sid':
        $entity = $transition->getTargetEntity();
        $field_name = $transition->getFieldName();
        $sid = workflow_node_current_state($entity, $field_name);
        if (!$sid) {
          \Drupal::logger('workflow_action')->notice('Unable to get current workflow state of entity %id.', ['%id' => $entity->id()]);
          return NULL;
        }
        break;

      case 'to_sid':
        $current_state = $transition->getFromState();
        $sid = match ($current_state->isCreationState()) {
          FALSE => $current_state->id(),
          TRUE => $current_state->getWorkflow()->getFirstSid(
            $transition,
            $transition->getFieldName(),
            $transition->getOwner()),
        };
        break;

      default:
        // Error. Should not happen.
        break;
    }
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestamp() {
    return $this->get('timestamp')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestampFormatted() {
    $timestamp = $this->getTimestamp();
    return \Drupal::service('date.formatter')->format($timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function setTimestamp($value) {
    $this->set('timestamp', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isScheduled() {
    return $this->isScheduled;
  }

  /**
   * {@inheritdoc}
   */
  public function isRevertible() {
    // Some states are useless to revert.
    if (!$this->hasStateChange()) {
      return FALSE;
    }
    // Some states are not fit to revert to.
    $from_state = $this->getFromState();
    if (!$from_state
      || !$from_state->isActive()
      || $from_state->isCreationState()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function schedule($schedule = TRUE) {
    $this->isScheduled = $schedule;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setExecuted($isExecuted = TRUE) {
    $this->isExecuted = $isExecuted;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isExecuted() {
    return (bool) $this->isExecuted;
  }

  /**
   * {@inheritdoc}
   */
  public function isForced() {
    return (bool) $this->isForced;
  }

  /**
   * {@inheritdoc}
   */
  public function force($force = TRUE) {
    $this->isForced = $force;
    return $this;
  }

  /**
   * Implementing interface FieldableEntityInterface extends EntityInterface.
   */

  /**
   * Get additional fields of workflow(_scheduled)_transition.
   *
   * {@inheritdoc}
   */
  public function getFieldDefinitions() {
    return parent::getFieldDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachedFields() {

    $entity_field_manager = \Drupal::service('entity_field.manager');

    $entity_type_id = $this->getEntityTypeId();
    $entity_type_id = 'workflow_transition';
    $bundle = $this->bundle();

    // Determine the fields added by Field UI.
    // $extra_fields = $this->entityFieldManager->getExtraFields($entity_type_id, $bundle);
    // $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id, $bundle);
    $fields = $entity_field_manager->getFieldDefinitions($entity_type_id, $bundle);
    $attached_fields = array_filter($fields, function ($field) {
      return ($field instanceof FieldConfig);
    });

    return $attached_fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    $fields['hid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Transition ID'))
      ->setDescription(t('The transition ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['wid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Workflow Type'))
      ->setDescription(t('The workflow type the transition relates to.'))
      ->setSetting('target_type', 'workflow_type')
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE);

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity type'))
      ->setDescription(t('The Entity type this transition belongs to.'))
      ->setSetting('is_ascii', TRUE)
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setReadOnly(TRUE);

    // An entity reference,
    // which allows to access the entity id with $node->entity_id->target_id
    // and to access the entity itself with $node->uid->entity.
    $fields['entity_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Entity ID'))
      ->setDescription(t('The Entity ID this record is for.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The current version identifier.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['field_name'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Field name'))
      ->setDescription(t('The name of the field the transition relates to.'))
      // Field name is technically required, but in widget is not.
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -1,
      ])
      ->setSetting('allowed_values_function', 'workflow_field_allowed_values')
      ->setDefaultValueCallback('static::getName')
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDescription(t('The entity language code.'))
      ->setTranslatable(TRUE);

    $fields['delta'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Delta'))
      ->setDescription(t('The sequence number for this data item, used for multi-value fields.'))
      ->setReadOnly(TRUE)
      // Only single value is supported.
      ->setDefaultValue(0);

    // Set $fields['uid'].
    // The uid is an entity reference to the user entity type,
    // which allows to access the user id with $node->uid->target_id
    // and to access the user entity with $node->uid->entity.
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']
      ->setDescription(t('The user ID of the transition author.'))
      ->setRevisionable(TRUE);

    $fields['from_sid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('From state'))
      ->setDescription(t('The {workflow_states}.sid the entity started as.'))
      ->setCardinality(1)
      ->setSetting('target_type', 'workflow_state')
      // Do not change. @see https://www.drupal.org/project/drupal/issues/2643308
      // @todo Fixme this is not used in the Transition Form state widget.
      ->setSetting('allowed_values_function', 'workflow_state_allowed_values')
      ->setDefaultValueCallback(static::class . '::getDefaultStateId')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -1,
      ])
      ->setReadOnly(TRUE);

    $fields['to_sid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('To state'))
      ->setDescription(t('The {workflow_states}.sid the entity transitioned to.'))
      ->setCardinality(1)
      ->setSetting('target_type', 'workflow_state')
      // Do not change. @see https://www.drupal.org/project/drupal/issues/2643308
      // @todo Fixme this is not used in the Transition Form state widget.
      ->setSetting('allowed_values_function', 'workflow_state_allowed_values')
      ->setDefaultValueCallback(static::class . '::getDefaultStateId')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(TRUE);

    $fields['timestamp'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Timestamp'))
      ->setDescription(t('The time that the current transition was executed.'))
      ->setDefaultValueCallback(static::class . '::getDefaultRequestTime')
      ->setDisplayOptions('form', [
        'type' => 'workflow_transition_timestamp',
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setRevisionable(TRUE);

    $fields['comment'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Comment'))
      ->setDescription(t('Briefly describe the changes you have made.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'textarea',
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

  /**
   * Generate a Watchdog error.
   *
   * @param string $message
   *   The message.
   * @param string $type
   *   The message type {'error' | 'notice'}.
   * @param string $from_sid
   *   The old State ID.
   * @param string $to_sid
   *   The new State ID.
   */
  public function logError($message, $type = 'error', $from_sid = '', $to_sid = '') {

    // Prepare an array of arguments for error messages.
    $entity = $this->getTargetEntity();
    $t_args = [
      '%user' => ($user = $this->getOwner()) ? $user->getDisplayName() : '',
      '%sid1' => ($from_sid || !$this->getFromState()) ? $from_sid : $this->getFromState()->label(),
      '%sid2' => ($to_sid || !$this->getToState()) ? $to_sid : $this->getToState()->label(),
      '%entity_id' => $this->getTargetEntityId(),
      '%entity_label' => $entity ? $entity->label() : '',
      '@entity_type' => $entity ? $entity->getEntityTypeId() : '',
      '@entity_type_label' => $entity ? $entity->getEntityType()->getLabel() : '',
      'link' => ($this->getTargetEntityId() && $this->getTargetEntity()->hasLinkTemplate('canonical')) ? $this->getTargetEntity()->toLink($this->t('View'))->toString() : '',
    ];
    ($type == 'error') ? \Drupal::logger('workflow')->error($message, $t_args)
      : \Drupal::logger('workflow')->notice($message, $t_args);
  }

  /**
   * {@inheritdoc}
   */
  public function dpm($function = NULL) {
    if (!function_exists('dpm')) { // In Workflow->dpm().
      return $this;
    }

    $function ??= debug_backtrace()[1]['function'];
    $transition = $this;
    $transition_id = $this->id() ?: 'NEW';
    $entity = $transition->getTargetEntity();
    $entity_id = $entity ? "{$entity->bundle()}/{$entity->id()}/{$entity->getRevisionId()}" : "___/0";
    $time = \Drupal::service('date.formatter')->format($transition->getTimestamp() ?? 0);
    $user = $transition->getOwner();
    $user_name = ($user) ? $user->getDisplayName() : 'unknown username';
    $t_string = "{$this->getEntityTypeId()} {$transition_id} for workflow_type <i>{$this->getWorkflowId()}</i> " . ($function ? ("in function '$function'") : '');
    $output[] = "Entity type/id/vid = {$this->getTargetEntityTypeId()}/$entity_id @ $time";
    $output[] = "Field   = {$transition->getFieldName()}";
    $output[] = "From/To = {$transition->getFromSid()} > {$transition->getToSid()}";
    $output[] = "From/To = {$transition->getFromState()} > {$transition->getToState()}";
    $output[] = "Comment = {$user_name} says: {$transition->getComment()}";
    $output[] = "Forced  = " . ($transition->isForced() ? 'yes' : 'no')
    . "; Scheduled = " . ($transition->isScheduled() ? 'yes' : 'no')
    . "; Executed = " . ($transition->isExecuted() ? 'yes' : 'no');
    dpm($output, $t_string); // In Workflow->dpm().

    return $this;
  }

}
