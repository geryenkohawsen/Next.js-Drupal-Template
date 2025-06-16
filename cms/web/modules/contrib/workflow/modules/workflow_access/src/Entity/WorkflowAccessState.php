<?php

namespace Drupal\workflow_access\Entity;

/**
 * Workflow Access configuration entity to persistently store configuration.
 *
 * @todo Make this a wrapper class for WorkflowState.
 */
class WorkflowAccessState {

  public const ROLE_ACCESS = 'workflow_access.role';

  /**
   * The machine name.
   *
   * @var string
   */
  public $id;

  /**
   * Constructs the object.
   *
   * @param array $values
   *   The list of values ['id', 'wid'].
   */
  public function __construct($values) {
    $this->id = $values['id'];
  }

  /**
   * {@inheritdoc}
   *
   * Avoids error on WorkflowStateListBuilder:
   * "Cannot load the "workflow_state" entity with NULL ID."
   *
   * @see WorkflowState::load()
   */
  public static function load($id) {
    return $id ? new WorkflowAccessState(['id' => $id]) : NULL;
  }

  /**
   * Given a sid, retrieve the access information and return the row(s).
   */
  public function readAccess() {
    $result = \Drupal::configFactory()->getEditable(WorkflowAccessState::ROLE_ACCESS)
      ->get($this->id);
    // Avoid errors in calling function when no data available.
    $result ??= [];
    return $result;
  }

  /**
   * Given data, insert into workflow access - we never update.
   */
  public function insertAccess(&$data) {
    \Drupal::configFactory()->getEditable(WorkflowAccessState::ROLE_ACCESS)
      ->set($this->id, $data)
      ->save();
    return $this;
  }

  /**
   * Given data, insert into workflow access - we never update.
   */
  public function updateAccess(&$data) {
    return $this->insertAccess($data);
  }

  /**
   * Given a sid, delete all access data for this state.
   */
  public function deleteAccess() {
    \Drupal::configFactory()->getEditable(WorkflowAccessState::ROLE_ACCESS)
      ->clear($this->id)
      ->save();
    return $this;
  }

}
