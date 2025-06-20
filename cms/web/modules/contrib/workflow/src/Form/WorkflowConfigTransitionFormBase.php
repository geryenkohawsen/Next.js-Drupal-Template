<?php

namespace Drupal\workflow\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflow\Entity\WorkflowInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a draggable listing of Workflow Config Transitions entities.
 *
 * @see \Drupal\workflow\Entity\WorkflowConfigTransition
 */
abstract class WorkflowConfigTransitionFormBase extends ConfigFormBase {

  /**
   * The key to use for the form element containing the entities.
   *
   * @var string
   */
  protected $entitiesKey = 'entities';

  /**
   * The WorkflowConfigTransition form type.
   *
   * @var string
   */
  protected $type;

  /**
   * The entities being listed.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  protected $entities = [];

  /**
   * The workflow object.
   *
   * @var \Drupal\workflow\Entity\Workflow
   */
  protected $workflow;

  /**
   * The messenger / logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger, TypedConfigManagerInterface $typed_config_manager) {
    // N.B. The $this->type and $this->entitiesKey must be set in the var section.
    parent::__construct($config_factory, $typed_config_manager);
    $this->logger = $logger;
    // Get the Workflow from the page.
    $this->workflow = workflow_url_get_workflow();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory')->get('workflow'),
      $container->get('config.typed')
     );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $form_type = $this->type;
    return "workflow_config_transition_{$form_type}_form";
  }

  /**
   * {@inheritdoc}
   *
   * Create an $entity for every ConfigTransition.
   */
  public function load() {
    $entities = [];

    $entity_type = $this->entitiesKey;
    $workflow = $this->workflow;
    $states = $workflow->getStates(WorkflowInterface::ACTIVE_CREATION_STATES);

    if ($states) {
      /** @var \Drupal\workflow\Entity\WorkflowState $from_state */
      /** @var \Drupal\workflow\Entity\WorkflowState $to_state */
      switch ($entity_type) {
        case 'workflow_state':
          foreach ($states as $from_state) {
            $from_sid = $from_state->id();
            $entities[$from_sid] = $from_state;
          }
          break;

        case 'workflow_config_transition':
          foreach ($states as $from_state) {
            $from_sid = $from_state->id();
            foreach ($states as $to_state) {
              $to_sid = $to_state->id();

              // Don't allow transition TO (creation).
              if ($to_state->isCreationState()) {
                continue;
              }
              // Only allow transitions from $from_state.
              if ($to_sid <> $from_sid) {
                // continue.
              }

              // Load existing config_transitions. Create if not found.
              $config_transitions = $workflow->getTransitionsByStateId($from_sid, $to_sid);
              if (!$config_transition = reset($config_transitions)) {
                $config_transition = $workflow->createTransition($from_sid, $to_sid);
              }
              $entities[] = $config_transition;
            }
          }
          break;

        default:
          $this->messenger()->addError($this->t('Improper type provided in load method.'));
          $this->logger->notice('Improper type provided in load method.', []);
      }
    }
    return $entities;
  }

  /**
   * Builds the table header of the table on this form.
   *
   * @return array
   *   The table header.
   */
  abstract public function buildHeader();

  /**
   * Builds a row for the table.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be displayed.
   *
   * @return array
   *   The row render array.
   */
  abstract public function buildRow(EntityInterface $entity);

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [];
    if (!$this->workflow) {
      return $form;
    }

    /*
     * Begin of copied code DraggableListBuilder::buildForm()
     */
    $form[$this->entitiesKey] = [
      '#type' => 'table',
      '#header' => $this->buildHeader(),
      '#sticky' => TRUE,
      '#empty' => $this->t('There is no @label yet.', ['@label' => 'Transition']),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'weight',

        ],
      ],
    ];

    $this->entities = $this->load();
    $delta = 10;
    // Change the delta of the weight field if have more than 20 entities.
    if (!empty($this->weightKey)) {
      $count = count($this->entities);
      if ($count > 20) {
        $delta = ceil($count / 2);
      }
    }
    foreach ($this->entities as $entity) {
      $row = $this->buildRow($entity);
      if (isset($row['label'])) {
        $row['label'] = ['#markup' => $row['label']];
      }
      if (isset($row['weight'])) {
        $row['weight']['#delta'] = $delta;
      }
      $form[$this->entitiesKey][$entity->id()] = $row;
    }
    /*
     * End of copied code DraggableListBuilder::buildForm()
     */

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

}
