<?php

namespace Drupal\workflow\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a class to build a draggable listing of Workflow Config Transitions entities.
 *
 * @see \Drupal\workflow\Entity\WorkflowConfigTransition
 */
class WorkflowConfigTransitionLabelForm extends WorkflowConfigTransitionFormBase {

  /**
   * {@inheritdoc}
   */
  protected $entitiesKey = 'workflow_config_transition';

  /**
   * {@inheritdoc}
   */
  protected $type = 'label';

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'from' => $this->t('Transition from'),
      'to' => $this->t('Transition to'),
      'label_new' => $this->t('label'),
      'config_transition' => '',
    ];

    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row = [];

    $workflow = $this->workflow;
    if ($workflow) {
      /** @var \Drupal\workflow\Entity\WorkflowConfigTransition $config_transition */
      $config_transition = $entity;

      static $previous_from_sid = -1;
      // Get transitions, sorted by weight of the old state.
      $from_state = $config_transition->getFromState();
      $to_state = $config_transition->getToState();
      $from_sid = $from_state->id();

      // Skip the transitions without any roles.
      $skip = TRUE;
      foreach ($config_transition->roles as $rid => $active) {
        if ($active) {
          $skip = FALSE;
        }
      }
      if ($skip == TRUE && ($from_state != $to_state)) {
        return $row;
      }

      $row['from'] = [
        '#type' => 'value',
        '#markup' => ($previous_from_sid != $from_sid) ? $from_state->label() : '"',
      ];
      $row['to'] = [
        '#type' => 'value',
        '#markup' => $to_state->label(),
      ];
      $row['label_new'] = [
        '#type' => 'textfield',
        '#default_value' => $config_transition->get('label'),
      ];
      $row['config_transition'] = [
        '#type' => 'value',
        '#value' => $config_transition,
      ];

      $previous_from_sid = $from_sid;
    }
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValue($this->entitiesKey) as $value) {
      $new_label = trim($value['label_new']);
      $value['config_transition']
        ->set('label', $new_label)
        ->save();
    }

    $this->messenger()->addStatus($this->t('The transition labels have been saved.'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    /** @var \Drupal\workflow\Entity\WorkflowConfigTransition[] $workflow_transitions */
    $workflow_transitions = $this->workflow->getStates();

    $config_names = [];
    foreach ($workflow_transitions as $transition) {
      $config_names[] = $transition->getConfigDependencyName();
    }
    return $config_names;
  }

}
