<?php

namespace Drupal\workflow\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Implements a form to revert an entity to a previous state.
 *
 * @package Drupal\workflow\Form
 */
class WorkflowTransitionRevertForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workflow_transition_revert_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->entity;
    $state = $transition->getFromState();

    if (!$state) {
      \Drupal::logger('workflow_revert')->error('Invalid state', []);
      $message = $this->t('Invalid transition. Your information has been recorded.');
      $this->messenger()->addError($message);
      return [];
    }

    $question = $this->t(
      'Are you sure you want to revert %title to the "@state" state?',
      [
        '@state' => $state->label(),
        '%title' => $transition->label() ?? '',
      ]
    );
    return $question;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->entity;
    return $this->getUrl($transition);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Revert');
  }

  /**
   * {@inheritdoc}
   *
   * @todo The fact that we need to overwrite this function,
   * is an indicator that the Transition is not completely a complete Entity.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $entity */
    $this->revertTransition($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $this->entity;

    // The entity will be updated when the transition is executed. Keep the
    // original one for the confirmation message.
    $previous_sid = $transition->getToSid();

    // Force the transition because it's probably not valid.
    $new_sid = $transition->executeAndUpdateEntity(TRUE);

    $message = ($previous_sid == $new_sid)
      ? $this->t('State is reverted.')
      : $this->t('State could not be reverted.');
    $this->messenger()->addMessage($message);

    $form_state->setRedirectUrl($this->getUrl($transition));
  }

  /**
   * Reverts the given transition.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   The transition to be reverted, changed by reference.
   */
  protected function revertTransition(WorkflowTransitionInterface $transition) {
    $from_sid = $transition->getFromSid();
    $to_sid = $transition->getToSid();
    // Use global user, since revert() is a UI-only function.
    $user = workflow_current_user();
    $time = \Drupal::time()->getRequestTime();
    $comment = $this->t('State reverted.');

    // Refresh Transition and revert states.
    // Transition has been cloned already in calling function,
    // but still must be marked as 'new'.
    $transition->set('from_sid', $to_sid)
      ->setValues($from_sid, $user->id(), $time, $comment)
      ->enforceIsNew(TRUE)
      ->set('hid', '')
      ->setExecuted(FALSE);
  }

  /**
   * Returns the URL for a Transition.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   Transition object.
   *
   * @return \Drupal\Core\Url
   *   The Transition's URL.
   */
  private function getUrl(WorkflowTransitionInterface $transition) {
    $route_provider = \Drupal::service('router.route_provider');
    if (count($route_provider->getRoutesByNames(['entity.node.workflow_history'])) === 1) {
      $url = new Url('entity.node.workflow_history', [
        'node' => $transition->getTargetEntityId(),
        'field_name' => $transition->getFieldName(),
      ]);
    }
    else {
      $entity = $transition->getTargetEntity();
      $url = new Url($transition->getTargetEntity()->toUrl()->getRouteName(), [
        $entity->getEntityTypeId() => $transition->getTargetEntityId(),
        'field_name' => $transition->getFieldName(),
      ]);
    }
    return $url;
  }

}
