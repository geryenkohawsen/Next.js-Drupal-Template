<?php

namespace Drupal\workflow\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\workflow\Element\WorkflowTransitionElement;
use Drupal\workflow\Entity\WorkflowTargetEntity;
use Drupal\workflow\Entity\WorkflowTransition;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Provides a Transition Form to be used in the Workflow Widget.
 */
class WorkflowTransitionForm extends ContentEntityForm {

  /*************************************************************************
   * Implementation of interface FormInterface.
   */

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // We need a proprietary Form ID, to identify the unique forms
    // when multiple fields or entities are shown on 1 page.
    // Test this f.i. by checking the'scheduled' box. It will not unfold.
    // $form_id = parent::getFormId();

    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->getEntity();
    $field_name = $transition->getFieldName();

    // Entity may be empty on VBO bulk form.
    // $entity = $transition->getTargetEntity();
    // Compose Form Id from string + Entity Id + Field name.
    // Field ID contains entity_type, bundle, field_name.
    // The Form Id is unique, to allow for multiple forms per page.
    // $workflow_type_id = $transition->getWorkflowId();
    // Field name contains implicit entity_type & bundle (since 1 field per entity)
    // $entity_type = $transition->getTargetEntityTypeId();
    // $entity_id = $transition->getTargetEntityId();;
    $suffix = 'form';
    // Emulate nodeForm convention.
    if ($transition->id()) {
      $suffix = 'edit_form';
    }
    $form_id = implode('_', [
      'workflow_transition',
      $transition->getTargetEntityTypeId(),
      $transition->getTargetEntityId() ?? 'new',
      $field_name,
      $suffix,
    ]);
    // $form_id = Html::getUniqueId($form_id);

    return $form_id;
  }

  /**
   * Gets the TransitionWidget in a form (for e.g., Workflow History Tab)
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   * @param array $form_state_additions
   *   Some additions.
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface|null $transition
   *   The current transition, if any.
   *
   * @return array
   *   The form.
   */
  public static function createInstance(EntityInterface $entity, $field_name, array $form_state_additions = [], ?WorkflowTransitionInterface $transition = NULL) {
    /** @var \Drupal\Core\Entity\EntityFormBuilder $entity_form_builder */
    $entity_form_builder = \Drupal::getContainer()->get('entity.form_builder');

    $transition ??= WorkflowTargetEntity::getTransition($entity, $field_name);
    //@todo Check if WorkflowDefaultWidget::createInstance() can be used.
    $form = $entity_form_builder->getForm($transition, 'add', $form_state_additions);
    return $form;
  }

  public static function trimWorkflowTransitionForm(array $workflow_form) {
    // Determine and add the attached fields.
    $transition = $workflow_form['#default_value'];
    $attached_fields = $transition->getAttachedFields();
    // Then, remove all form elements, keep widget elements.
    $base_fields = WorkflowTransition::baseFieldDefinitions($transition->getEntityType());
    $fields = $attached_fields + $base_fields + [
      '_workflow_transition' => '_workflow_transition',
      'force' => 'force',
    ];
    foreach (Element::children($workflow_form) as $attribute_name) {
      if (array_key_exists($attribute_name, $fields)) {
        $element[$attribute_name] ??= $workflow_form[$attribute_name];
      }
    }
    // The following are not in Element::children.
    $element['#default_value'] = $workflow_form['#default_value'];
    $element['#action'] = $workflow_form['#action'];
    // Overwrite value set by Form.

    $workflow_settings = $transition->getWorkflow()->getSettings();
    $element = [
      '#type' => $workflow_settings['fieldset'] ? 'details' : 'container',
      '#collapsible' => ($workflow_settings['fieldset'] != 0),
      '#open' => ($workflow_settings['fieldset'] != 2),
    ] + $element;

    return $element;
  }

  /* *************************************************************************
   *
   * Implementation of interface EntityFormInterface (extends FormInterface).
   *
   */

  /**
   * This function is called by buildForm().
   *
   * Caveat: !! It is not declared in the EntityFormInterface !!
   *
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    // Call parent to get (extra) fields.
    // This might cause baseFieldDefinitions to appear twice.
    $form = parent::form($form, $form_state);

    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->entity;

    $form['#default_value'] = $transition;
    // Overwrite the BaseFields with the custom values.
    $form = WorkflowTransitionElement::transitionElement($form, $form_state, $form);
    return $form;
  }

  /**
   * Returns an array of supported actions for the current entity form.
   *
   * Caveat: !! It is not declared in the EntityFormInterface !!
   *
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    // Action buttons are added in common workflow_form_alter(),
    // since it will be done in many form_id's.
    // Keep aligned: workflow_form_alter(), WorkflowTransitionForm::actions().
    // addActionButtons($form, $form_state);
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $transition = $this->entity;
    // Update the entity.
    $transition = $this->copyFormValuesToEntity($transition, $form, $form_state);
    // Mark the entity as NOT requiring validation. (Used in validateForm().)
    $transition->setValidationRequired(FALSE);

    return $transition;
  }

  /**
   * {@inheritdoc}
   */
  public function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    parent::copyFormValuesToEntity($entity, $form, $form_state);

    // Use a proprietary version of copyFormValuesToEntity().
    $values = $form_state->getValues();
    $transition = WorkflowTransitionElement::copyFormValuesToTransition($entity, $form, $form_state, $values);
    return $transition;
  }

  /**
   * {@inheritdoc}
   *
   * This is called from submitForm().
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Execute transition and update the attached entity.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->getEntity();
    return $transition->executeAndUpdateEntity();
  }

}
