<?php

namespace Drupal\workflow\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowFieldHooks {

  /**
   * Implements hook_form_FORM_ID_alter() for 'field_config_edit_form'.
   */
  #[Hook('form_field_config_edit_form_alter')]
  public function formFieldConfigEditFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    $field_type = $form_state->getFormObject()->getEntity()->getType();
    if ($field_type == 'workflow') {
      // The Workflow field must have a value, so set to required.
      $form['required']['#default_value'] = 1;
      $form['required']['#disabled'] = TRUE;
      $form['set_default_value']['#default_value'] = FALSE;
      $form['set_default_value']['#disabled'] = TRUE;
      $form['default_value']['#access'] = FALSE;
    }
  }

  /**
   * Implements hook_field_formatter_info_alter().
   *
   * The module reuses the formatters defined in list.module.
   */
  #[Hook('field_formatter_info_alter')]
  public function fieldFormatterInfoAlter(&$info) {
    $info['list_key']['field_types'][] = 'workflow';
    $info['list_default']['field_types'][] = 'workflow';
  }

  /**
   * Implements hook_field_widget_info_alter().
   *
   * The module does not implement widgets of its own, but reuses the
   * widgets defined in options.module.
   *
   * @see workflow_options_list()
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(&$info) {
    $info['options_select']['field_types'][] = 'workflow';
    $info['options_buttons']['field_types'][] = 'workflow';
  }

}
