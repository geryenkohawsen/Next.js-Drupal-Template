<?php

namespace Drupal\workflow\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\EntityField;

/**
 * Displays the State ID/Name.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("workflow_state")
 */
class WorkflowState extends EntityField {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
  }

}
