<?php

namespace Drupal\moderation_sidebar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\LocalTaskManager;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\moderation_sidebar\Form\QuickTransitionForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
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
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

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
   *   The module handler service.
   * @param \Drupal\Core\Menu\LocalTaskManagerInterface $local_task_manager
   *   The local task manager.
   */
  public function __construct($moderation_information, RequestStack $request_stack, DateFormatterInterface $date_formatter, ModuleHandlerInterface $module_handler, LocalTaskManagerInterface $local_task_manager) {
    $this->moderationInformation = $moderation_information;
    $this->request = $request_stack->getCurrentRequest();
    $this->dateFormatter = $date_formatter;
    $this->moduleHandler = $module_handler;
    $this->localTaskManager = $local_task_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $moderation_info = $container->has('workbench_moderation.moderation_information') ? $container->get('workbench_moderation.moderation_information') : $container->get('content_moderation.moderation_information');

    // We need an instance of LocalTaskManager that thinks we're viewing the
    // entity. To accomplish this, we need to mock a request stack with a fake
    // request. This looks crazy, but there is no other way to render
    // Local Tasks for an arbitrary path without this.
    /** @var \Symfony\Component\HttpFoundation\RequestStack $request_stack */
    $request_stack = $container->get('request_stack');

    /** @var EntityInterface $entity */
    $entity = $request_stack->getCurrentRequest()->attributes->get('entity');
    $fake_request_stack = new RequestStack();
    $url = $entity->toUrl();
    $request = Request::create($url->toString());

    /** @var \Drupal\Core\Routing\AccessAwareRouter $router */
    $router = $container->get('router');
    $router->matchRequest($request);
    $fake_request_stack->push($request);
    $route_match = new CurrentRouteMatch($fake_request_stack);

    $local_task_manager = new LocalTaskManager(
      $container->get('controller_resolver'),
      $fake_request_stack,
      $route_match,
      $container->get('router.route_provider'),
      $container->get('module_handler'),
      $container->get('cache.discovery'),
      $container->get('language_manager'),
      $container->get('access_manager'),
      $container->get('current_user')
    );

    return new static(
      $moderation_info,
      $request_stack,
      $container->get('date.formatter'),
      $container->get('module_handler'),
      $local_task_manager
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
    // Load the correct translation.
    $language = $this->languageManager()->getCurrentLanguage();
    $entity = $entity->getTranslation($language->getId());

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-container'],
      ],
    ];

    // Add information about this Entity to the top of the bar.
    if ($this->isModeratedEntity($entity)) {
      $state = $entity->moderation_state->entity;
      $state_label = $state->label();
    }
    else if ($entity->hasField('status')) {
      $state_label = $entity->get('status') ? $this->t('Published') : $this->t('Unpublished');
    }
    else {
      $state_label = $this->t('Published');
    }

    $build['info'] = [
      '#theme' => 'moderation_sidebar_info',
      '#title' => $entity->label(),
      '#state' => $state_label,
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
      $build['info']['#revision_author'] = $user->getDisplayName();
      $build['info']['#revision_author_link'] = $user->toLink()->toRenderable();
      $build['info']['#revision_time'] = $time;
      $build['info']['#revision_time_pretty'] = $time_pretty;
    }

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['moderation-sidebar-actions'],
      ],
    ];

    if ($this->isModeratedEntity($entity)) {
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
    }

    // Add a list of (non duplicated) local tasks.
    $build['actions'] += $this->getLocalTasks($entity);

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
   * Checks if a given Entity is moderated.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *    An entity.
   *
   * @return bool
   *   Whether or not the entity is moderated.
   */
  protected function isModeratedEntity(ContentEntityInterface $entity) {
    if (method_exists($this->moderationInformation, 'isModeratedEntity')) {
      $is_moderated_entity = $this->moderationInformation->isModeratedEntity($entity);
    }
    else {
      $is_moderated_entity = $this->moderationInformation->isModeratableEntity($entity);
    }
    return $is_moderated_entity;
  }

  /**
   * Gathers a list of non-duplicated tasks, themed like our other buttons.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   An entity.
   *
   * @return array
   *   A render array representing local tasks for this entity.
   */
  protected function getLocalTasks(ContentEntityInterface $entity) {
    $tasks = $this->localTaskManager->getLocalTasks("entity.{$entity->getEntityTypeId()}.canonical", 0);
    $tabs = [];
    if (isset($tasks['tabs']) && !empty($tasks['tabs'])) {
      foreach ($tasks['tabs'] as $name => $tab) {
        // If this is a moderated node, we provide buttons for certain actions.
        $duplicated_tab = preg_match('/^.*(canonical|edit_form|delete_form|latest_version_tab)$/', $name);
        if (!$this->isModeratedEntity($entity) || !$duplicated_tab) {
          $tabs[$name] = [
            '#title' => $this->t($tab['#link']['title']),
            '#type' => 'link',
            '#url' => $tab['#link']['url'],
            '#attributes' => [
              'class' => ['moderation-sidebar-link', 'button'],
            ],
          ];
        }
      }
    }
    return $tabs;
  }

}
