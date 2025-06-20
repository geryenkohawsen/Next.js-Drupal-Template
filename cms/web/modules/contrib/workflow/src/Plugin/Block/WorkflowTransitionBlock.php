<?php

namespace Drupal\workflow\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Form\WorkflowTransitionForm;

/**
 * Provides a 'Workflow Transition form' block.
 *
 * @Block(
 *   id = "workflow_transition_form_block",
 *   admin_label = @Translation("Workflow Transition form"),
 *   category = @Translation("Forms"),
 * )
 */
class WorkflowTransitionBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    if (!$entity = workflow_url_get_entity()) {
      return AccessResult::forbidden();
    }

    // Only show block on entity view page (when default operation = '').
    if ($operation = workflow_url_get_operation()) {
      return AccessResult::forbidden();
    }

    // Only show block if entity has workflow, and user has permission.
    foreach (_workflow_info_fields($entity) as $definition) {
      $type_id = $definition->getSetting('workflow_type');
      if ($account->hasPermission("access $type_id workflow_transition form")) {
        return AccessResult::allowed();
      }
    }

    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $form = [];

    // Get the entity for this form.
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    if (!$entity = workflow_url_get_entity()) {
      return $form;
    }
    // Get the field name, to avoid error on Node Add page.
    if (!$field_name = workflow_get_field_name($entity)) {
      return $form;
    }
    // Add the WorkflowTransitionForm to the page.
    $form = WorkflowTransitionForm::createInstance($entity, $field_name, []);

    return $form;
  }

}
