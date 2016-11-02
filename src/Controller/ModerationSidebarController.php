<?php

namespace Drupal\moderation_sidebar\Controller;

use Drupal\content_moderation\ModerationInformation;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\moderation_sidebar\Form\QuickTransitionForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Endpoints for the Moderation Sidebar module.
 */
class ModerationSidebarController extends ControllerBase {

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformation
   */
  protected $moderationInformation;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Creates a ModerationSidebarController instance.
   *
   * @param \Drupal\content_moderation\ModerationInformation $moderation_information
   *   The moderation information service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler serivce.
   */
  public function __construct($moderation_information, RequestStack $request_stack, DateFormatterInterface $date_formatter, ModuleHandlerInterface $module_handler) {
    $this->moderationInformation = $moderation_information;
    $this->request = $request_stack->getCurrentRequest();
    $this->dateFormatter = $date_formatter;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $moderation_info = $container->has('workbench_moderation.moderation_information') ? $container->get('workbench_moderation.moderation_information') : $container->get('content_moderation.moderation_information');
    return new static(
      $moderation_info,
      $container->get('request_stack'),
      $container->get('date.formatter'),
      $container->get('module_handler')
    );
  }

  /**
   * Displays information relevant to moderating an entity in-line.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return array
   *   The render array for the sidebar.
   */
  public function sideBar(ContentEntityInterface $entity) {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-container'],
      ],
    ];

    // Add information about this Entity to the top of the bar.
    $state = $entity->moderation_state->entity;
    $build['info'] = [
      '#theme' => 'moderation_sidebar_info',
      '#title' => $entity->label(),
      '#state' => $state->label(),
    ];

    if ($entity instanceof RevisionLogInterface) {
      $user = $entity->getRevisionUser();
      $time = (int) $entity->getRevisionCreationTime();
      $too_old = strtotime('-1 month');
      // Show formatted time differences for edits younger than a month.
      if ($time > $too_old) {
        $diff = $this->dateFormatter->formatTimeDiffSince($time, ['granularity' => 1]);
        $time_pretty = $this->t('@diff ago', ['@diff' => $diff]);
      }
      else {
        $date = date('m/d/Y - h:i A', $time);
        $time_pretty = $this->t('on @date', ['@date' => $date]);
      }
      $user_link = $user->toLink()->toRenderable();
      $user_link['#attributes']['target'] = '_blank';
      $build['info']['#revision_author'] = $user->label();
      $build['info']['#revision_author_link'] = $user_link;
      $build['info']['#revision_time'] = $time;
      $build['info']['#revision_time_pretty'] = $time_pretty;
      $build['info']['#revision_id'] = $entity->getRevisionId();
    }

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-actions'],
      ],
    ];

    $entity_type_id = $entity->getEntityTypeId();
    $is_latest = $this->moderationInformation->isLatestRevision($entity);

    // If this revision is not the latest, provide a link to the latest entity.
    if (!$is_latest) {
      $build['actions']['view_latest'] = [
        '#title' => $this->t('View existing draft'),
        '#type' => 'link',
        '#url' => Url::fromRoute("entity.{$entity_type_id}.latest_version", [
          $entity_type_id => $entity->id(),
        ]),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Provide a link to the default display of the entity.
    if (!$entity->isDefaultRevision()) {
      $build['actions']['view_default'] = [
        '#title' => $this->t('View live content'),
        '#type' => 'link',
        '#url' => $entity->toLink()->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Show an edit link if this is the latest revision.
    if ($is_latest && !$this->moderationInformation->isLiveRevision($entity)) {
      $build['actions']['edit_draft'] = [
        '#title' => $this->t('Edit draft'),
        '#type' => 'link',
        '#url' => $entity->toLink(NULL, 'edit-form')->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button'],
        ],
      ];
    }

    // Provide a list of actions representing transitions for this revision.
    $build['actions']['quick_draft_form'] = $this->formBuilder()->getForm(QuickTransitionForm::class, $entity);

    // Only show the entity delete action on the default revision.
    if ($entity->isDefaultRevision()) {
      $build['actions']['delete'] = [
        '#title' => $this->t('Delete content'),
        '#type' => 'link',
        '#url' => $entity->toLink(NULL, 'delete-form')->getUrl(),
        '#attributes' => [
          'class' => ['moderation-sidebar-link', 'button', 'button--danger'],
        ],
      ];
    }

    // Allow other module to alter our build.
    $this->moduleHandler->alter('moderation_sidebar', $build, $entity);

    return $build;
  }

  /**
   * Displays the moderation sidebar for the latest revision of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return array
   *   The render array for the sidebar.
   */
  public function sideBarLatest(ContentEntityInterface $entity) {
    $entity = $this->moderationInformation->getLatestRevision($entity->getEntityTypeId(), $entity->id());
    return $this->sideBar($entity);
  }

  /**
   * Renders the sidebar title for moderating this Entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   A moderated entity.
   *
   * @return string
   *   The title of the sidebar.
   */
  public function title(ContentEntityInterface $entity) {
    $type = $bundle_entity = $this->entityTypeManager()->getStorage($entity->getEntityType()->getBundleEntityType())->load($entity->bundle());
    $label = $type->label();
    return $this->t('Moderate @label', ['@label' => $label]);
  }

  /**
   * Performs custom access checks for Moderation Sidebar routes.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *    A moderated entity.
   *
   * @return bool
   *   Whether or not the route can be accessed.
   */
  public function access(ContentEntityInterface $entity) {
    $is_moderated = $this->moderationInformation->isModeratedEntity($entity);
    return AccessResultAllowed::allowedIf($is_moderated);
  }

}
