<?php

namespace Drupal\workflow\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\workflow\Element\WorkflowTransitionButtons;

/**
 * Contains Field and Help hooks.
 *
 * Class is declared as a service in services.yml file.
 * @see https://drupalize.me/blog/drupal-111-adds-hooks-classes-history-how-and-tutorials-weve-updated
 */
class WorkflowHooks {

  /**
   * The migration manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationPluginManager;

  /**
   * Initializes the services required.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time_service
   *   The datetime.time service.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_plugin_manager
   *   The migration manager.
   */
  public function __construct(?MigrationPluginManagerInterface $migration_plugin_manager = NULL) {
    if (!$migration_plugin_manager && \Drupal::hasService('plugin.manager.migration')) {
      $migration_plugin_manager = \Drupal::service('plugin.manager.migration');
    }
    $this->migrationPluginManager = $migration_plugin_manager;
  }

  /**
   * Implements hook_help() on several pages.
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    $output = '';

    switch ($route_name) {
      case 'help.page.workflow':
        $output .= '<h3>' . t('About') . '</h3>';
        $output .= '<p>' . t('The Workflow module adds a field to Entities to
        store field values as Workflow states. You can control "state transitions"
        and add action to specific transitions.') . '</p>';
        return $output;

      case 'entity.workflow_transition.field_ui_fields':
        return t('This page allows you to add fields to the Workflow form.
        Normally this is an advanced action, which is not needed in
        regular use cases. You may alter the sort order and attributes under
        "Manage form display".');

      case 'entity.entity_form_display.workflow_transition.default':
        return t("This page allows you to sort fields on the Workflow form and
        set some attributes. Please be aware that some settings are overridden
        by the settings on the 'Edit' page, including hidden/optional/required:
        <ul>
          <li>To state widget: 'How to show the available states' (this cannot be hidden/disabled)</li>
          <li>Timestamp widget: 'Enable scheduling'</li>
          <li>Comment widget: 'How to show the Comment sub-field'</li>
        </ul>");

      case 'entity.workflow_type.collection':
        return t('This page allows you to maintain Workflows. Once a workflow is
        created, you can maintain your entity type and add a Field of type
        \'Workflow\'.');

      case 'entity.workflow_state.collection':
        return t("To create a new state, enter its name in the last row of the
        'State' column. Check the 'Active' box to make it effective. You may
        also drag it to the appropriate position.") . '<br />'
          . t("A state must be marked as active, to be available in the
        workflow's transitions.") . '<br />'
          . t("If you wish to inactivate a state that has content (i.e. count is
        not zero), then you need to select a state to which to reassign that
        content.");

      case 'entity.workflow_transition.collection':
        $url = Url::fromRoute('user.admin_permissions', [],
          ['fragment' => 'module-workflow']);
        return t('You are currently viewing the possible transitions to and from
        workflow states. The state is shown in the left column; the state to be
        moved to is to the right. For each transition, check the box next to
        the role(s) that may initiate the transition. For example, if only the
        "production editor" role may move content from Review state to the
        Published state, check the box next to "production editor". The author
        role is built in and refers to the user who authored the content.')
          . '<br /><i>'
          . t("If not all roles are in the list, please review which roles may
        'participate in workflows' on <a href=':url'>the Permissions page</a>.
        On that page, uncheck the 'Authenticated user' role temporarily to
        view the permissions of each separate role.</i>",
            [':url' => $url->toString()]);

      case 'entity.workflow_transition_label.collection':
        return t("You can add labels to transitions if you don't like the
        standard state labels. They will modify the Workflow form options, so
        specific workflow transitions can have their own labels, relative to
        the beginning and ending states. Rather than showing the user a
        workflow box containing options like 'review required' as a state in
        the workflow, it could say 'move to the editing department for grammar
        review'.");
    }
    return $output;
  }

  /**
   * Implements hook_form_alter().
   *
   * Adds action/drop buttons next to the 'Save'/'Delete' buttons,
   * when the 'options' widget element is set to 'action buttons'.
   * Note: do not use with multiple workflows per entity: confusing UX.
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Keep aligned: workflow_form_alter(), WorkflowTransitionForm::actions().
    WorkflowTransitionButtons::addActionButtons($form, $form_state);
  }

  /**
   * Implements hook_preprocess_field().
   *
   * Note: Hook hook_preprocess_field must remain procedural (message in D11.1).
   * in Drupal\Core\Hook\HookCollectorPass::checkForProceduralOnlyHooks().
   */
  // public function preprocessField(&$variables, $hook) {
  // }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme() {
    $themes = [
      'workflow_transition' => [
        'render element' => 'elements',
        'file' => 'workflow.theme.inc',
      ],
    ];

    return $themes;
  }

}
