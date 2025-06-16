<?php

namespace Drupal\workflow\Entity;

/**
 * Provides an interface for workflow manager.
 *
 * Contains lost of functions from D7 workflow.module file.
 */
interface WorkflowManagerInterface {

  /********************************************************************
   * Helper functions.
   */

  /**
   * Utility function to return an array of workflow fields.
   *
   * @param string $entity_type_id
   *   The content entity type to which the workflow fields are attached.
   *
   * @return array
   *   An array of workflow field map definitions, keyed by field name. Each
   *   value is an array with two entries:
   *   - type: The field type.
   *   - bundles: The bundles in which the field appears, as array with entity
   *     types as keys and the array of bundle names as values.
   *
   * @see \Drupal\comment\CommentManagerInterface::getFields()
   * @see \Drupal\Core\Entity\EntityFieldManager::getFieldMapByFieldType
   * @see \Drupal\Core\Entity\EntityManagerInterface::getFieldMap()
   */
  public function getFieldMap($entity_type_id = '');

}
