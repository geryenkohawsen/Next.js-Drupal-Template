<?php

namespace Drupal\workflow;

use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowInterface;

/**
 * Wrapper methods for Workflow* objects.
 *
 * Using this trait will add getWorkflow(), getWorkflowID() and setWorkflow()
 * methods to the class.
 *
 * @ingroup workflow
 */
trait WorkflowTypeAttributeTrait {

  /**
   * The machine_name of the attached Workflow.
   *
   * @var string
   */
  protected $wid = '';

  /**
   * The attached Workflow.
   *
   * It must explicitly be defined, and not be public, to avoid errors
   * when exporting with json_encode().
   *
   * @var \Drupal\workflow\Entity\Workflow
   */
  protected $workflow = NULL;

  /**
   * Sets the Workflow.
   *
   * @param \Drupal\workflow\Entity\WorkflowInterface|null $workflow
   *   The Workflow object.
   */
  public function setWorkflow(?WorkflowInterface $workflow = NULL) {
    $this->wid = '';
    $this->workflow = NULL;
    if ($workflow) {
      $this->wid = $workflow->id();
      $this->workflow = $workflow;
    }
  }

  /**
   * Returns the Workflow object of this object.
   *
   * @return \Drupal\workflow\Entity\Workflow
   *   Workflow object.
   */
  public function getWorkflow() {
    if ($this->workflow) {
      return $this->workflow;
    }

    /* @noinspection PhpAssignmentInConditionInspection */
    if ($wid = $this->getWorkflowId()) {
      $this->workflow = Workflow::load($wid);
    }
    return $this->workflow;
  }

  /**
   * Sets the Workflow ID of this object.
   *
   * @param string $wid
   *   The Workflow ID.
   *
   * @return object
   *   The Workflow object.
   */
  public function setWorkflowId($wid) {
    $this->wid = $wid;
    $this->workflow = NULL;
    return $this;
  }

  /**
   * Returns the Workflow ID of this object.
   *
   * @return string
   *   Workflow ID.
   */
  public function getWorkflowId() {
    /** @var \Drupal\Core\Entity\ContentEntityBase $this */
    if (!empty($this->wid)) {
      return $this->wid;
    }

    $value = $this->get('wid');
    try {
      $this->wid = match (TRUE) {
        is_string($value) => $value,

        // In WorkflowTransition.
        is_object($value) => $value->getValue()[0]['target_id'] ?? '',
      };
    }
    catch (\UnhandledMatchError $e) {
      workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');
    }
    return $this->wid;
  }

}
