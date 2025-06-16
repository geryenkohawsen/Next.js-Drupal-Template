<?php

namespace Drupal\workflow\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\workflow\Entity\WorkflowTargetEntity;

/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowViewsHooks {

  /**
   * Implements hook_field_views_data().
   */
  #[Hook('field_views_data')]
  public function fieldViewsData(FieldStorageConfigInterface $field) {
    if (version_compare(\Drupal::VERSION, '11.2') >= 0) {
      $data = \Drupal::service('views.field_data_provider')
        ->defaultFieldImplementation($field);
    }
    else {
      $data = views_field_default_views_data($field);
    }
    $settings = $field->getSettings();

    foreach ($data as $table_name => $table_data) {
      foreach ($table_data as $field_name => $field_data) {
        if ($field_name == 'delta') {
          continue;
        }
        if (isset($field_data['filter'])) {
          $data[$table_name][$field_name]['filter']['wid'] = (array_key_exists('workflow_type', $settings)) ? $settings['workflow_type'] : '';
          $data[$table_name][$field_name]['filter']['id'] = 'workflow_state';
        }
        if (isset($field_data['argument'])) {
          $data[$table_name][$field_name]['argument']['id'] = 'workflow_state';
        }
      }
    }

    return $data;
  }

  /**
   * Implements hook_views_data_alter().
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data) {
    // Provide an integration for each entity type except workflow entities.
    // Copied from comment.views.inc.
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $entity_type_id => $entity_type) {
      if (WorkflowTargetEntity::isWorkflowEntityType($entity_type_id)) {
        continue;
      }
      if (!$entity_type->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      if (!$entity_type->getBaseTable()) {
        continue;
      }

      $field_map = workflow_get_workflow_fields_by_entity_type($entity_type_id);
      if ($field_map) {
        $base_table = $entity_type->getDataTable() ?: $entity_type->getBaseTable();
        $args = ['@entity_type' => $entity_type_id];
        foreach ($field_map as $field_name => $field) {
          $data[$base_table]["{$field_name}_tid"] = [
            'title' => t('Workflow transitions on @entity_type using field: @field_name', $args + ['@field_name' => $field_name]),
            'help' => t('Relate all transitions on @entity_type. This will create 1 duplicate record for every transition. Usually if you need this it is better to create a Transition view.', $args),
            'relationship' => [
              'group' => t('Workflow transition'),
              'label' => t('workflow transition'),
              'base' => 'workflow_transition_history',
              'base field' => 'entity_id',
              'relationship field' => $entity_type->getKey('id'),
              'id' => 'standard',
              'extra' => [
                [
                  'field' => 'entity_type',
                  'value' => $entity_type_id,
                ],
                [
                  'field' => 'field_name',
                  'value' => $field_name,
                ],
              ],
            ],
          ];
        }
      }
    }
  }

}
