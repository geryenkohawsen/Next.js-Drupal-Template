<?php

namespace Drupal\workflow_cleanup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflow\Entity\WorkflowConfigTransition;
use Drupal\workflow\Entity\WorkflowState;

/**
 * Provides a Form for organizing obsolete States.
 *
 * @package Drupal\workflow_cleanup\Form
 */
class WorkflowCleanupSettingsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workflow_cleanup_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [];

    // Get all of the states, indexed by sid.
    $orphans = $inactive = [];

    $states = WorkflowState::loadMultiple();
    foreach ($states as $state) {
      if (!$state->getWorkflow()) {
        // The associated workflow does not exist.
        $orphans[$state->id()] = $state;
      }
      elseif (!$state->isActive()) {
        $inactive[$state->id()] = $state;
      }
    }

    // Save the relevant states in an indexed array.
    $form['#workflow_states'] = $orphans + $inactive;

    $form['no_workflow'] = [
      '#type' => 'details',
      '#title' => $this->t('Orphaned States'),
      '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
      '#description' => $this->t(
        'These states no longer belong to an existing workflow.'
      ),
      '#tree' => TRUE,
    ];
    foreach ($orphans as $sid => $state) {
      $form['no_workflow'][$sid]['check'] = [
        '#type' => 'checkbox',
        '#title' => $state->label(),
        '#return_value' => $sid,
      ];
    }

    $form['inactive'] = [
      '#type' => 'details',
      '#title' => $this->t('Inactive (Deleted) States'),
      '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
      '#description' => $this->t(
        'These states belong to a workflow, but have been marked inactive (deleted).'
      ),
      '#tree' => TRUE,
    ];
    foreach ($inactive as $sid => $state) {
      $form['inactive'][$sid]['check'] = [
        '#type' => 'checkbox',
        '#title' => $state->label() . ' (' . $state->getWorkflow()->label() . ')',
        '#return_value' => $sid,
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete selected states'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $states = $form['#workflow_states'];
    $values = $form_state->getValues();
    foreach (['no_workflow', 'inactive'] as $section) {
      if (!isset($values[$section])) {
        continue;
      }

      foreach ($values[$section] as $sid => $data) {
        if (!$data['check']) {
          continue;
        }

        /** @var \Drupal\workflow\Entity\WorkflowState $state */
        $state = $states[$sid];
        $state_name = $state->label();

        // Delete any transitions this state is involved in.
        $count = 0;
        foreach (WorkflowConfigTransition::loadMultiple() as $config_transition) {
          if ($config_transition->getFromSid() == $sid || $config_transition->getToSid() == $sid) {
            $config_transition->delete();
            $count++;
          }
        }
        if ($count) {
          $this->messenger()->addStatus($this->t('@count transitions for the "@state" state have been deleted.',
            ['@state' => $state_name, '@count' => $count]));
        }

        // @todo Remove history records, too.
        $count = 0;
        // $count = db_delete('workflow_node_history')->condition('sid', $sid)->execute();
        if ($count) {
          $this->messenger()->addStatus($this->t('@count history records for the "@state" state have been deleted.',
            ['@state' => $state_name, '@count' => $count]));
        }

        $state->delete();
        $this->messenger()->addStatus($this->t('The "@state" state has been deleted.',
          ['@state' => $state_name]));
      }
    }
  }

}
