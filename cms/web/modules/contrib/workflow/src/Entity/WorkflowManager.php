<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Manages entity type plugin definitions.
 */
class WorkflowManager implements WorkflowManagerInterface {

  use StringTranslationTrait;

  /**
   * The entity_field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity_type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The user settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $userConfig;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Construct the WorkflowManager object as a service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity_field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity_type manager service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   *
   * @see workflow.services.yml
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, TranslationInterface $string_translation, ModuleHandlerInterface $module_handler) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslation = $string_translation;
    $this->userConfig = $config_factory->get('user.settings');
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldMap($entity_type_id = '') {
    if ($entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        return [];
      }
    }

    $map = $this->entityFieldManager->getFieldMapByFieldType('workflow');
    if ($entity_type_id) {
      return $map[$entity_type_id] ?? [];
    }
    return $map;
  }

  /**
   * {@inheritdoc}
   *
   * @deprecated in workflow:1.8 and will be removed in a future version. Use function
   *   workflow_node_current_state() from workflow.module instead.
   */
  public static function getCurrentStateId(EntityInterface $entity, $field_name = '') {
    return workflow_node_current_state($entity, $field_name);
  }

  /**
   * {@inheritdoc}
   *
   * @deprecated in workflow:1.8 and will be removed in a future version. Use function
   *   workflow_node_previous_state() from workflow.module instead.
   */
  public static function getPreviousStateId(EntityInterface $entity, $field_name = '') {
    return workflow_node_previous_state($entity, $field_name);
  }

}
