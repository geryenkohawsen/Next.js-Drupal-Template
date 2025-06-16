<?php

namespace Drupal\workflow_access\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Form\WorkflowConfigTransitionFormBase;
use Drupal\workflow_access\Entity\WorkflowAccessState;

/**
 * Provides the base form for workflow add and edit forms.
 */
class WorkflowAccessRoleForm extends WorkflowConfigTransitionFormBase {

  /**
   * {@inheritdoc}
   */
  protected $entitiesKey = 'workflow_state';

  /**
   * The WorkflowConfigTransition form type.
   *
   * @var string
   */
  protected $type = 'access';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workflow_access_role';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [WorkflowAccessState::ROLE_ACCESS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'label_new' => $this->t('State'),
      'view' => $this->t('Roles who can view posts in this state'),
      'update' => $this->t('Roles who can edit posts in this state'),
      'delete' => $this->t('Roles who can delete posts in this state'),
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
      /** @var \Drupal\workflow\Entity\WorkflowState $state */
      $state = $entity;

      if ($state->isCreationState()) {
        // No need to set perms on creation.
        return [];
      }

      $view = $update = $delete = [];
      $count = 0;
      $access_state = new WorkflowAccessState(['id' => $state->id()]);
      foreach ($access_state->readAccess() as $rid => $access) {
        $count++;
        $view[$rid] = $access['grant_view'] ? $rid : 0;
        $update[$rid] = $access['grant_update'] ? $rid : 0;
        $delete[$rid] = $access['grant_delete'] ? $rid : 0;
      }
      // Allow view grants by default for anonymous and authenticated users,
      // if no grants were set up earlier.
      if (!$count) {
        $roles = [
          AccountInterface::ANONYMOUS_ROLE,
          AccountInterface::AUTHENTICATED_ROLE,
        ];
        $view = array_combine($roles, $roles);
      }

      // A list of role names keyed by role ID, including the 'author' role.
      // Only get the roles with proper permission + Author role.
      $type_id = $workflow->id();
      $roles = workflow_allowed_user_role_names("create $type_id workflow_transition");

      $row['label_new'] = [
        '#type' => 'value',
        '#markup' => $this->t('@label', ['@label' => $state->label()]),
      ];
      $row['view'] = [
        '#type' => 'checkboxes',
        '#options' => $roles,
        '#default_value' => $view,
      ];
      $row['update'] = [
        '#type' => 'checkboxes',
        '#options' => $roles,
        '#default_value' => $update,
      ];
      $row['delete'] = [
        '#type' => 'checkboxes',
        '#options' => $roles,
        '#default_value' => $delete,
      ];
    }
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValue($this->entitiesKey) as $sid => $access) {
      if (!$access_state = WorkflowAccessState::load($sid)) {
        continue;
      }

      foreach ($access['view'] as $rid => $checked) {
        $data[$rid] = [
          'grant_view' => (!empty($access['view'][$rid])) ? (bool) $access['view'][$rid] : 0,
          'grant_update' => (!empty($access['update'][$rid])) ? (bool) $access['update'][$rid] : 0,
          'grant_delete' => (!empty($access['delete'][$rid])) ? (bool) $access['delete'][$rid] : 0,
        ];
      }
      $access_state->insertAccess($data);

      // Update all nodes to reflect new settings.
      node_access_needs_rebuild(TRUE);
    }

    parent::submitForm($form, $form_state);
  }

}
