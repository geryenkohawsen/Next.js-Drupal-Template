<?php

namespace Drupal\workflow\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Subscriber for Workflow routes.
 *
 * @see \Drupal\workflow\Plugin\Derivative\WorkflowLocalTask
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new RouteSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager) {
    $this->entityTypeManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {

    $field_map = workflow_get_workflow_fields_by_entity_type();
    foreach ($field_map as $entity_type_id => $fields) {

      /*
       * @todo For entities with multiple workflow fields, Create an
       *   Entity workflow field list page and a route
       *   that redirect to the correct page.
       * @todo Routes for multiple workflow fields on 3 different bundles of 1 entity type.
       */

      // Generate route for default field. Redirect to workflow/{field_name}.
      $path = "/$entity_type_id/{{$entity_type_id}}/workflow";
      $route = $this->getEntityLoadRoute($entity_type_id, $path);
      $collection->add("entity.$entity_type_id.workflow_history", $route);

      // Generate one route for each workflow field.
      foreach ($fields as $field_name => $field) {
        $path = "/$entity_type_id/{{$entity_type_id}}/workflow/$field_name";
        $route = $this->getEntityLoadRoute($entity_type_id, $path);
        $collection->add("entity.$entity_type_id.workflow_history.$field_name", $route);
      }
    }
  }

  /**
   * Gets the entity load route.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $path
   *   The Path of the route.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The generated route, if available.
   */
  protected function getEntityLoadRoute($entity_type_id, $path) {

    /*
     * @todo Create the Route for taxonomy term like
     * '/taxonomy/term/{taxonomy_term}/workflow/{field_name}'
     *
     */
    $route = new Route(
      $path,
      [
        '_controller' => '\Drupal\workflow\Controller\WorkflowTransitionListController::historyOverview',
        '_title_callback' => '\Drupal\workflow\Controller\WorkflowTransitionListController::getTitle',
      ],
      [
        '_custom_access' => '\Drupal\workflow\Access\WorkflowHistoryAccess::access',
      ],
      [
        '_admin_route' => TRUE,
        '_workflow_entity_type_id' => $entity_type_id, // @todo Remove this.
        'parameters' => [
          $entity_type_id => ['type' => "entity:$entity_type_id"],
        ],
      ]
    );

    return $route;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', 100];
    return $events;
  }

  /**
   * Get all field of type workflow.
   *
   * @return array
   *   Return all workflow fields.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function listWorkflowFields() {
    $fieldType = 'workflow';
    $fieldStorageConfigs = $this->entityTypeManager->getStorage('field_storage_config')->loadByProperties(['type' => $fieldType]);
    if (!$fieldStorageConfigs) {
      return [];
    }

    $availableItems = [];
    foreach ($fieldStorageConfigs as $fieldStorage) {
      $availableItems[] = $fieldStorage;
    }

    return $availableItems;
  }

}
