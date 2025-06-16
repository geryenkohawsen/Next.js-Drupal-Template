<?php

namespace Drupal\workflow_cleanup\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Hook classes for workflow_cleanup submodule.
 */
class WorkflowCleanupHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    $output = [];

    switch ($route_name) {
      case 'workflow.cleanup.settings':
        $output = t('This page allows you to delete orphaned and inactive states.
          States can be deleted freely in a development environment, but be
          careful if you have used a State in a production environment. The
          transition history of your content will loose the description of a
          previously used state. If your Workflow must comply to some auditing
          standards, you should NOT use this function.');
        break;
    }

    return $output;
  }

}
