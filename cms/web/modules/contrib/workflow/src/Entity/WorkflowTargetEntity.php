<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a wrapper/ decorator for the $transition->getTargetEntity().
 *
 * @todo Change all static functions to non static.
 * But a decorator requires duplicating many functions...
 */
class WorkflowTargetEntity {

  /**
   * The entity a transition points to.
   */
  protected ?EntityInterface $targetEntity;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityInterface $entity) {
    $this->targetEntity = $entity;
  }

  /**
   * Returns the original unchanged entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The original entity.
   *
   * @see EntityInterface::getOriginal()
   * @see https://www.drupal.org/node/3295826
   */
  public static function getOriginal(EntityInterface $entity) {
    return method_exists($entity, 'getOriginal')
      ? $entity->getOriginal()
      : $entity->original ?? NULL;
  }

  /**
   * Determines the Workflow field_name of an entity.
   *
   * If an entity has multiple workflows, only returns the first one.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity at hand.
   * @param string $field_name
   *   The field name. If given, will be returned unchanged.
   *
   * @return string
   *   The field name of the first workflow field.
   */
  public static function getFieldName(EntityInterface $entity, $field_name = '') {
    $field_name = match (TRUE) {

      // $entity may be empty on Entity Add page.
      !$entity => '',

      // Normal case, a field name is given.
      !empty($field_name) => $field_name,

      // Get the first field_name (multiple may exist).
      !empty($fields = workflow_allowed_field_names($entity)) =>
      array_key_first($fields),

      // No Workflow field exists.
      default => '',
    };
    return $field_name;
  }

  /**
   * Gets an Options list of field names.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   An entity.
   * @param string $entity_type
   *   An entity_type.
   * @param string $entity_bundle
   *   An entity.
   * @param string $field_name
   *   A field name.
   *
   * @return array
   *   An list of field names.
   */
  public static function getPossibleFieldNames(?EntityInterface $entity = NULL, $entity_type = '', $entity_bundle = '', $field_name = '') {
    $result = [];
    foreach (_workflow_info_fields($entity, $entity_type, $entity_bundle, $field_name) as $definition) {
      $field_name = $definition->getName();
      $label = $entity
        ? $entity->{$field_name}->getFieldDefinition()->getLabel()
        : $field_name;
      $result[$field_name] = $label;
    }
    return $result;
  }

  /**
   * Gets the creation state for a given $entity and $field_name.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param string $field_name
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The creation State for the Workflow of the field.
   *
   * @see WorkflowTargetEntity::getCurrentStateId()
   */
  public static function getCreationState(EntityInterface $entity, $field_name) {
    $state = NULL;

    /** @var \Drupal\Core\Config\Entity\ConfigEntityBase $entity */
    /** @var \Drupal\Core\Field\FieldDefinitionInterface $field_config */
    $field_config = $entity->get($field_name)->getFieldDefinition();
    $field_storage = $field_config->getFieldStorageDefinition();
    $wid = $field_storage->getSetting('workflow_type');
    /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
    $workflow = $wid ? Workflow::load($wid) : NULL;
    if (!$workflow) {
      \Drupal::messenger()->addError(t('Workflow %wid cannot be loaded. Contact your system administrator.', ['%wid' => $wid ?? '']));
    }
    else {
      $state = $workflow->getCreationState();
    }

    return $state;
  }

  /**
   * Gets the creation state ID for a given $entity and $field_name.
   *
   * Is a helper function for:
   * - workflow_node_current_state()
   * - workflow_node_previous_state()
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param string $field_name
   *
   * @return string
   *   The ID of the creation State for the Workflow of the field.
   */
  public static function getCreationStateId(EntityInterface $entity, $field_name) {
    $state = WorkflowTargetEntity::getCreationState($entity, $field_name);
    return $state ? $state->id() : '';
  }

  /**
   * Gets the current state of a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The name of the field of the entity to check.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The current state.
   *
   * @see WorkflowTargetEntity::getCurrentStateId()
   */
  public static function getCurrentState(EntityInterface $entity, $field_name = '') {
    $sid = WorkflowTargetEntity::getCurrentStateId($entity, $field_name);
    /** @var \Drupal\workflow\Entity\WorkflowState $state */
    $state = WorkflowState::load($sid);
    return $state;
  }

  /**
   * Gets the current state ID of a given entity.
   *
   * There is no need to use a page cache.
   * The performance is OK, and the cache gives problems when using Rules.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The name of the field of the entity to check.
   *   If empty, the field_name is determined on the spot. This must be avoided,
   *   since it makes having multiple workflow per entity unpredictable.
   *   The found field_name will be returned in the param.
   *
   * @return string
   *   The ID of the current state.
   */
  public static function getCurrentStateId(EntityInterface $entity, $field_name = '') {
    $sid = '';

    if (!$entity) {
      return $sid;
    }

    // If $field_name is not known, yet, determine it.
    $field_name = ($field_name) ? $field_name : workflow_get_field_name($entity, $field_name);
    // If $field_name is found, get more details.
    if (!$field_name || !isset($entity->{$field_name})) {
      // Return the initial value.
      return $sid;
    }

    // Normal situation: get the value.
    $sid = $entity->{$field_name}->value;
    if ($sid) {
      return $sid;
    }

    // Use previous state if Entity is new/in preview/without current state.
    // (E.g., content was created before adding workflow.)
    if ((!$sid) || $entity->isNew() || (!empty($entity->in_preview))) {
      $sid = self::getPreviousStateId($entity, $field_name);
    }

    return $sid;
  }

  /**
   * Gets the previous state of a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   The previous state.
   */
  public static function getPreviousState(EntityInterface $entity, $field_name = '') {
    $sid = WorkflowTargetEntity::getPreviousStateId($entity, $field_name);
    /** @var \Drupal\workflow\Entity\WorkflowState $state */
    $state = WorkflowState::load($sid);
    return $state;
  }

  /**
   * Gets the previous state ID of a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The ID of the previous state.
   */
  public static function getPreviousStateId(EntityInterface $entity, $field_name = '') {
    $sid = '';

    if (!$entity) {
      return $sid;
    }

    // Determine $field_name if not known, yet.
    $field_name = ($field_name) ? $field_name : workflow_get_field_name($entity, $field_name);
    if (!$field_name) {
      // Return the initial value.
      return $sid;
    }

    // Retrieve previous state from the original.
    if (isset($entity->original) && !empty($entity->original->{$field_name}->value)) {
      return $entity->original->{$field_name}->value;
    }

    // A node may not have a Workflow attached.
    if ($entity->isNew()) {
      return self::getCreationStateId($entity, $field_name);
    }

    // @todo Read history with an explicit langcode(?).
    $langcode = ''; // $entity->language()->getId();
    // @todo D8: #2373383 Add integration with older revisions via Revisioning module.
    $entity_type_id = $entity->getEntityTypeId();
    $last_transition = WorkflowTransition::loadByProperties($entity_type_id, $entity->id(), [], $field_name, $langcode, 'DESC');
    if ($last_transition) {
      $sid = $last_transition->getToSid(); // @see #2637092, #2612702
    }
    if (!$sid) {
      // No history found on an existing entity.
      $sid = self::getCreationStateId($entity, $field_name);
    }

    return $sid;
  }

  /**
   * {@inheritdoc}
   *
   * Gets the initial/resulting Transition of a workflow form/widget.
   */
  public static function getTransition(EntityInterface $entity, $field_name): WorkflowTransitionInterface {

    // Only 1 scheduled transition can be found, but multiple executed ones.
    $transition = WorkflowScheduledTransition::loadByProperties(
      $entity->getEntityTypeId(),
      $entity->id(),
      [],
      $field_name
    );

    // Note: Field is empty if node created before module installation.
    $item = $entity->{$field_name}[0] ?? NULL;
    $transition ??= $item
      ? $item->getTransition()
      : WorkflowTransition::create(['entity' => $entity, 'field_name' => $field_name]);
    return $transition;
  }

  /**
   * Determine if the entity is Workflow* entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return bool
   *   TRUE, if the entity is defined by workflow module.
   *
   * @usage Use it when a function should not operate on Workflow objects.
   */
  public static function isWorkflowEntityType($entity_type_id) {
    return in_array($entity_type_id, [
      'workflow_type',
      'workflow_state',
      'workflow_config_transition',
      'workflow_transition',
      'workflow_scheduled_transition',
    ]);
  }

}
