<?php

namespace Drupal\workflow\Plugin\views\filter;

use Drupal\views\FieldAPIHandlerTrait;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\ViewExecutable;

/**
 * Filter handler which uses workflow_state as options.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("workflow_state")
 */
class WorkflowState extends ManyToOne {

  use FieldAPIHandlerTrait;

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
    $wid = $this->definition['wid'] ?? '';
    $grouped = ($options['group_info']['widget'] ?? '') == 'select';
    $this->valueOptions = workflow_allowed_workflow_state_names($wid, $grouped);
  }

  /**
   * Returns the valid options for this State.
   *
   * Child classes should be used to override this function and set the
   * 'value options', unless 'options callback' is defined as a valid function
   * or static public method to generate these values.
   *
   * This can use a guard to be used to reduce database hits as much as
   * possible.
   *
   * @return array|null
   *   The stored values from $this->valueOptions.
   */
  public function getValueOptions() {

    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }

    // @todo Implement the below code, and remove the line from init.
    // @todo Follow Options patterns.
    // @see callback_allowed_values_function()
    // @see options_allowed_values()
    return parent::getValueOptions();
  }

}
