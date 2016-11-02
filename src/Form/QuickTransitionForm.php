<?php

namespace Drupal\moderation_sidebar\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The QuickTransitionForm provides quick buttons for changing transitions.
 */
class QuickTransitionForm extends FormBase {

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation;

  /**
   * The moderation state transition validation service.
   *
   * @var \Drupal\content_moderation\StateTransitionValidation
   */
  protected $validation;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * QuickDraftForm constructor.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderation_info
   *   The moderation information service.
   * @param \Drupal\content_moderation\StateTransitionValidation $validation
   *   The moderation state transition validation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct($moderation_info, $validation, EntityTypeManagerInterface $entity_type_manager) {
    $this->moderationInformation = $moderation_info;
    $this->validation = $validation;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $moderation_info = $container->has('workbench_moderation.moderation_information') ? $container->get('workbench_moderation.moderation_information'): $container->get('content_moderation.moderation_information');
    $validation = $container->has('workbench_moderation.state_transition_validation') ? $container->get('workbench_moderation.state_transition_validation'): $container->get('content_moderation.state_transition_validation');
    return new static(
      $moderation_info,
      $validation,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moderation_sidebar_quick_transition_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityInterface $entity = NULL) {
    // Return an empty form if the user does not have appropriate permissions.
    if (!$entity->access('update')) {
      return [];
    }

    // Persist the entity so we can access it in the submit handler.
    $form_state->set('entity', $entity);

    $transitions = $this->validation->getValidTransitions($entity, $this->currentUser());

    // Exclude self-transitions.
    /** @var \Drupal\content_moderation\Entity\ModerationState $current_state */
    $current_state = $entity->moderation_state->entity;

    /** @var \Drupal\content_moderation\ModerationStateTransitionInterface[] $transitions */
    $transitions = array_filter($transitions, function($transition) use ($current_state) {
      return $transition->getToState() != $current_state->id();
    });

    foreach ($transitions as $transition) {
      $form[$transition->id()] = [
        '#type' => 'submit',
        '#id' => $transition->id(),
        '#value' => $this->t($transition->label()),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button--primary'],
        ],
      ];
    }

    // Allow users to discard Drafts.
    if ($this->moderationInformation->isLatestRevision($entity)
      && !$this->moderationInformation->isLiveRevision($entity)
      && !$entity->isDefaultRevision()) {
      $form['discard_draft'] = [
        '#type' => 'submit',
        '#id' => 'moderation-sidebar-discard-draft',
        '#value' => $this->t('Discard draft'),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button', 'button--danger'],
        ],
        '#submit' => ['::discardDraft'],
      ];
    }

    return $form;
  }

  /**
   * Form submission handler to discard the current draft.
   *
   * Technically, there is no way to delete Drafts, but as a Draft is really
   * just the current, non-live revision, we can simply re-save the default
   * revision to get the same end-result.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function discardDraft(array &$form, FormStateInterface $form_state) {
    /** @var ContentEntityInterface $entity */
    $entity = $form_state->get('entity');
    $default_revision_id = $this->moderationInformation->getDefaultRevisionId($entity->getEntityTypeId(), $entity->id());
    $default_revision = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadRevision($default_revision_id);
    $default_revision->revision_log = $this->t('Used the Moderation Sidebar to delete the current draft.');
    $default_revision->save();
    drupal_set_message($this->t('The draft has been discarded successfully.'));

    // There is no generic entity route to view a single revision, but we know
    // that the node module support this.
    if ($entity->getEntityTypeId() == 'node') {
      $url = Url::fromRoute('entity.node.revision', [
        'node' => $entity->id(),
        'node_revision' => $entity->getRevisionId(),
      ])->toString();
      drupal_set_message($this->t('<a href="@url">You can view an archive of the draft by clicking here.</a>', ['@url' => $url]));
    }

    $form_state->setRedirectUrl($entity->toLink()->getUrl());
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var ContentEntityInterface $entity */
    $entity = $form_state->get('entity');

    /** @var \Drupal\content_moderation\ModerationStateTransitionInterface[] $transitions */
    $transitions = $this->validation->getValidTransitions($entity, $this->currentUser());

    $element = $form_state->getTriggeringElement();

    if (!isset($transitions[$element['#id']])) {
      $form_state->setError($element, $this->t('Invalid transition selected.'));
      return;
    }

    $state_id = $transitions[$element['#id']]->getToState();
    /** @var \Drupal\content_moderation\ModerationStateInterface $state */
    $state = $this->entityTypeManager->getStorage('moderation_state')->load($state_id);

    $entity->moderation_state->target_id = $state_id;

    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionCreationTime(REQUEST_TIME);
      $entity->setRevisionLogMessage($this->t('Used the Moderation Sidebar to change the state to "@state".', ['@state' => $state->label()]));
    }

    $entity->save();

    drupal_set_message($this->t('The moderation state has been updated.'));

    if ($state->isPublishedState()) {
      $form_state->setRedirectUrl($entity->toLink()->getUrl());
    }
    else {
      $entity_type_id = $entity->getEntityTypeId();
      $params = [$entity_type_id => $entity->id()];
      $form_state->setRedirect("entity.{$entity_type_id}.latest_version", $params);
    }
  }

}
