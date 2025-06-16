<?php

namespace Drupal\workflow_access\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the base form for workflow add and edit forms.
 */
class WorkflowAccessSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workflow_access_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['workflow_access.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $weight = WorkflowAccessSettingsForm::getSetting('workflow_access_priority');
    $form['workflow_access'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Workflow Access Settings'),
    ];
    $form['workflow_access']['#tree'] = TRUE;

    $url = 'https://api.drupal.org/api/drupal/core%21modules%21node%21node.api.php/function/hook_node_access_records/8';
    $form['workflow_access']['workflow_access_priority'] = [
      '#type' => 'weight',
      '#delta' => 10,
      '#title' => $this->t('Workflow Access Priority'),
      '#default_value' => $weight,
      '#description' => $this->t(
        'This sets the node access priority. Changing this setting can be
         dangerous. If there is any doubt, leave it at 0.
         <a href=":url" target="_blank">Read the manual</a>.',
        [':url' => $url]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $weight = $form_state->getValue(['workflow_access', 'workflow_access_priority']);
    WorkflowAccessSettingsForm::setSetting('workflow_access_priority', $weight);

    // Module's weight has changed.
    node_access_needs_rebuild(TRUE);

    parent::submitForm($form, $form_state);
  }

  /**
   * Get the module settings.
   */
  public static function getSetting($value = 'workflow_access_priority') {
    $priority = \Drupal::config('workflow_access.settings')
      ->get($value);
    return $priority;
  }

  /**
   * Set the module settings.
   */
  private function setSetting($key, $value) {
    $this->config('workflow_access.settings')
      ->set('workflow_access_priority', $value)
      ->save();
  }

}
