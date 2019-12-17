<?php

namespace Drupal\media_manager;

use DateTime;
use DateTimeZone;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Exception;
use stdClass;

/**
 * Class ShowManager.
 *
 * @package Drupal\media_manager
 */
class ShowManager extends ApiContentManagerBase {

  /**
   * Machine name for the genre vocabularly.
   *
   * TODO: Make this configurable on the settings page.
   */
  const GENRE_BUNDLE = 'genre';

  /**
   * State key for the last update DateTime.
   */
  const LAST_UPDATE_KEY = 'media_manager.shows.last_update';

  /**
   * All Genre taxonomy term entities.
   *
   * @var array
   */
  private $genres;

  /**
   * ApiQueueController constructor.
   *
   * @param \Drupal\media_manager\ApiClient $client
   *   Media Manager API client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger channel service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   Queue factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(
    ApiClient $client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelInterface $logger,
    QueueFactory $queue_factory,
    StateInterface $state
  ) {
    parent::__construct($client, $entity_type_manager, $logger, $queue_factory, $state);
    $this->genres = $this->getGenres();
  }

  /**
   * {@inheritdoc}
   */
  public static function getEntityTypeId(): string {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public static function getBundleId(): string {
    // TODO: Get this configuration from the settings page.
    return 'article';
  }

  /**
   * {@inheritdoc}
   */
  public static function getQueueName(): string {
    return 'media_manager.queue.shows';
  }

  /**
   * {@inheritdoc}
   */
  public function getLastUpdateTime(): DateTime {
    return $this->state->get(
      self::LAST_UPDATE_KEY,
      new DateTime('@1')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setLastUpdateTime(DateTime $time): void {
    $this->state->set(self::LAST_UPDATE_KEY, $time);
  }

  /**
   * {@inheritdoc}
   */
  public function resetLastUpdateTime(): void {
    $this->state->delete(self::LAST_UPDATE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public static function getAutoUpdateConfigName(): string {
    return 'shows.queue.autoupdate';
  }

  /**
   * {@inheritdoc}
   */
  public static function getAutoUpdateIntervalConfigName(): string {
    return 'shows.queue.autoupdate_interval';
  }

  /**
   * Gets all existing Genre entities from the database.
   *
   * @return array
   *   Genre entities keyed by Media Manager ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getGenres(): array {
    $definition = $this->entityTypeManager->getDefinition('taxonomy_term');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    /** @var \Drupal\taxonomy\Entity\Term[] $entities */
    $entities = $storage->loadByProperties([
      $definition->getKey('bundle') => self::GENRE_BUNDLE,
    ]);
    // Re-key by Media Manager ID.
    $genres = [];
    foreach ($entities as $entity) {
      $genres[$entity->get(self::ID_FIELD_NAME)->value] = $entity;
    }
    return $genres;
  }

  /**
   * Gets an existing Genre term (creating a new one if necessary).
   *
   * This method also adds the newly created genre to the self::genres array so
   * it can be reused with further objects processed by this class.
   *
   * @param string $id
   *   Media Manager ID for the Genre.
   * @param string $name
   *   Genre name.
   * @param string $slug
   *   Genre slug.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   A Genre term.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function getOrAddGenre(string $id, string $name, string $slug): EntityInterface {
    if (isset($this->genres[$id])) {
      $genre = $this->genres[$id];
    }
    else {
      $definition = $this->entityTypeManager->getDefinition('taxonomy_term');
      $genre = Term::create([
        $definition->getKey('bundle') => self::GENRE_BUNDLE,
      ]);
      $genre->setName($name);
      $genre->set(self::ID_FIELD_NAME, $id);
      $genre->set('field_slug', $slug);
      $genre->save();
      $this->genres[$id] = $genre;
    }
    return $genre;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function updateQueue(DateTime $since = NULL): bool {
    $dt_start = new DateTime();

    if (empty($since)) {
      $since = $this->getLastUpdateTime();
    }

    // This query cannot sort by update time. Results must be fully traversed.
    $shows = $this->client->getShows(['fetch-related' => FALSE]);

    foreach ($shows as $show) {
      $updated_at = self::getLatestUpdatedAt($show);
      if ($updated_at > $since) {
        $this->getQueue()->createItem($show);
      }
    }

    $this->setLastUpdateTime($dt_start);

    return TRUE;
  }

  /**
   * Gets an API response for a single Show.
   *
   * @param string $guid
   *   ID of the Show to get.
   *
   * @return object|null
   *   Show item data from the API or NULL.
   */
  public function getShow(string $guid): ?stdClass {
    return $this->client->getShow($guid);
  }

  /**
   * Get Show nodes with optional properties.
   *
   * @param array $properties
   *   Properties to filter Show nodes.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Shows nodes.
   */
  public function getShowNodes(array $properties = []): array {
    try {
      $definition = $this->entityTypeManager->getDefinition(self::getEntityTypeId());
      $storage = $this->entityTypeManager->getStorage(self::getEntityTypeId());
      $nodes = $storage->loadByProperties([
        $definition->getKey('bundle') => self::getBundleId(),
      ] + $properties);
    }
    catch (Exception $e) {
      // Let NULL fall through.
      $nodes = [];
    }

    return $nodes;
  }

  /**
   * Gets a Show node by TMS ID.
   *
   * @param string $id
   *   TMS ID to query with.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Show node with TMS ID or NULL if none found.
   */
  public function getShowNodeByTmsId(string $id): ?NodeInterface {
    $nodes = $this->getShowNodes(['field_show_tms_id' => $id]);

    $node = NULL;
    if (!empty($nodes)) {
      $node = reset($nodes);
      if (count($nodes) > 1) {
        $this->logger->error('Multiple nodes found for Media Manager
          TMS ID {id}. Node IDs found: {nid_list}. Using node {nid}.', [
            'id' => $id,
            'nid_list' => implode(', ', array_keys($nodes)),
            'nid' => $node->id(),
          ]);
      }
    }

    return $node;
  }

  /**
   * Gets a Show node by slug.
   *
   * @param string $slug
   *   Slug to query with.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Show node with the slug or NULL if none found.
   */
  public function getShowNodeBySlug(string $slug): ?NodeInterface {
    $nodes = $this->getShowNodes(['field_show_slug' => $slug]);

    $node = NULL;
    if (!empty($nodes)) {
      $node = reset($nodes);
    }

    return $node;
  }

  /**
   * Adds or updates a Show node based on API data.
   *
   * @param object $item
   *   An API response object for the Show.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function addOrUpdateShow(object $item): void {
    $node = $this->getOrCreateNode($item->id, self::getBundleId());

    $attributes = $item->attributes;
    $updated_at = self::getLatestUpdatedAt($item);
    $updated_at->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    $images = $this->parseImages($attributes->images);

    $node->setTitle($attributes->title);
    // $node->set('field_pbs_mm_id', $item->id);
    // $node->set('field_pbs_tms_id', $attributes->tms_id);

    // TODO: Add an updated field
    // $node->set(
      // 'field_show_updated',
      // $updated_at->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT)
    // );

    // $node->set('field_description', [
      // 'value' => $attributes->description_long,
      // 'summary' => $attributes->description_short,
    // ]);
    // $node->set('field_episode_count', $attributes->episodes_count);

    // // Capture the _first_ recognized audience scope based on the field's
    // // allowed values list. Multiple scopes are possible, but for now this
    // // functionality is really only used as a method to catch and disable Shows
    // // with an audience of "kids".
    // if (!empty($attributes->audience)) {
      // $audience_scope = $node->getFieldDefinition('field_audience_scope');
      // $allowed_values = $audience_scope->getSetting('allowed_values');
      // foreach ($attributes->audience as $audience) {
        // if (isset($allowed_values[$audience->scope])) {
          // $node->set('field_audience_scope', $audience->scope);
          // break;
        // }
      // }
    // }

    // if (!empty($attributes->genre)) {
      // $node->set('field_genre', $this->getOrAddGenre(
        // $attributes->genre->id,
        // $attributes->genre->title,
        // $attributes->genre->slug
      // ));
    // }
    // else {
      // $node->set('field_genre', NULL);
    // }

    // if (!empty($attributes->premiered_on)) {
      // $node->set('field_premiere_date', $attributes->premiered_on);
    // }
    // else {
      // $node->set('field_premiere_date', NULL);
    // }

    // if (!empty($attributes->franchise)) {
      // $node->set('field_show_franchise_id', $attributes->franchise->id);
    // }
    // else {
      // $node->set('field_show_franchise_id', NULL);
    // }

    // if (isset($images['show-mezzanine16x9'])) {
      // $node->set('field_show_mezzanine', $images['show-mezzanine16x9']);
      // $node->set('field_show_mezz_original', $images['show-mezzanine16x9']);
    // }
    // elseif (isset($images['show-kids-16x9'])) {
      // $node->set('field_show_mezzanine', $images['show-kids-16x9']);
      // $node->set('field_show_mezz_original', $images['show-kids-16x9']);
    // }
    // else {
      // $node->set('field_show_mezzanine', NULL);
      // $node->set('field_show_mezz_original', NULL);
    // }

    // // Attempt to replace the default mezzanine with the latest episode/video.
    // $this->updateShowMezzanine($node);

    // $node->set(
      // 'field_latest_episode',
      // ['target_id' => $this->getLatestEpisodeId($node)]
    // );

    // if (isset($images['show-poster2x3'])) {
      // $node->set('field_show_poster', $images['show-poster2x3']);
    // }
    // else {
      // $node->set('field_show_poster', NULL);
    // }

    // // _All_ content with a "kids" audience scope will be unpublished. This data
    // // is in the API primarily to support PBS's own pbskids.org website and the
    // // Assets related to these Shows are never available for embed (despite what
    // // other API data says). Note: the computed field "publishable" is not used
    // // here because it will not work for newly added (unsaved) nodes.
    // if ($node->get('field_audience_scope')->value === 'kids') {
      // $node->setUnpublished();
    // }
    // // If no Video Content related to this Show is available or scheduled to be
    // // available, unpublish it.
    // elseif (empty($this->getVideoContent($node, TRUE))) {
      // $node->setUnpublished();
    // }
    // else {
      // $node->setPublished();
    // }

    $node->save();
  }

  /**
   * Updates a Show node mezzanine from the latest Video Content child node.
   *
   * This method modifies the `field_show_mezzanine` field on the Show node, but
   * _does not_ save the modified node.
   *
   * @param \Drupal\node\NodeInterface &$show
   *   Show node to update.
   *
   * @return bool
   *   TRUE if the Show node was updated, FALSE otherwise.
   */
  public function updateShowMezzanine(NodeInterface &$show): bool {
    $node_updated = FALSE;

    // Get the latest full length episode to feature on the show mezzanine.
    if (!$latestEpisode = $this->getLatestEpisodeId($show)) {
      // Get the latest video of any type if a full episode is not available.
      $latestEpisode = $this->getLatestVideoWithImage($show);
    }

    if (!empty($latestEpisode)) {
      // Set show mezzanine to use the image from the latest episode or video.
      /** @var \Drupal\node\NodeInterface $video_content */
      $video_content = $this->initStorage($show)->load($latestEpisode);
      $show->set(
        'field_show_mezzanine',
        $video_content->get('field_video_image')->value
      );
      $node_updated = TRUE;
    }

    return $node_updated;
  }

  /**
   * Get an instance of the entity storage object for a content type.
   *
   * @param \Drupal\node\NodeInterface $show
   *   The show being updated.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface|null
   *   An instance of an entity storage object.
   */
  protected function initStorage(NodeInterface $show) {
    $storage = NULL;
    if ($show->bundle() != $this->getBundleId()) {
      $this->logger->notice($this->t(
        'Invalid Show node bundle: @bundle.', ['@bundle' => $show->bundle()]
      ));
      return $storage;
    }

    try {
      $storage = $this->entityTypeManager
        ->getStorage(VideoContentManager::getEntityTypeId());
    }
    catch (Exception $e) {
      watchdog_exception('media_manager', $e);
    }
    return $storage;
  }

  /**
   * Get an instance of the entity query object for a content type.
   *
   * @param \Drupal\node\NodeInterface $show
   *   The show being updated.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   An instance of an entity query object.
   */
  protected function initQuery(NodeInterface $show) {
    $query = NULL;
    if ($storage = $this->initStorage($show)) {
      $query = $storage->getQuery();
    }
    return $query;
  }

  /**
   * Gets IDs of available (published) Video Content nodes related to a Show.
   *
   * @param \Drupal\node\NodeInterface $show
   *   Show node.
   * @param bool $include_scheduled
   *   Whether to include include Video Content scheduled in the future (via the
   *   contrib module Scheduler).
   *
   * @return array
   *   Node IDs of related Video Content nodes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getVideoContent(
    NodeInterface $show,
    bool $include_scheduled = FALSE
  ): array {
    $definition = $this->entityTypeManager
      ->getDefinition(VideoContentManager::getEntityTypeId());
    $query = $this->entityTypeManager
      ->getStorage(VideoContentManager::getEntityTypeId())
      ->getQuery();

    $query
      ->condition($definition->getKey('bundle'), VideoContentManager::getBundleId())
      ->condition('field_show_ref', $show->id());

    if ($include_scheduled) {
      $now = new DrupalDateTime();
      $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
      $or = $query->orConditionGroup();
      $or->condition('status', 1);
      $or->condition('publish_on', $now->getTimestamp(), '>');
      $query->condition($or);
    }
    else {
      $query->condition('status', 1);
    }

    return $query->execute();
  }

  /**
   * Determines if a Show is suitable for publishing consideration.
   *
   * Note: This method does not determine whether or not a Show should be
   * published. It determines if the Show should be _considered_ for publishing.
   *
   * Currently, the only requirement for a Show to be publishable is that it
   * does not have an audience scope of "kids".
   *
   * @param \Drupal\node\NodeInterface $show
   *   Show node.
   *
   * @return bool
   *   TRUE if the Show is publishable, FALSE otherwise.
   */
  public static function showIsPublishable(NodeInterface $show): bool {
    $field = 'field_audience_scope';
    if ($show->hasField($field) && !$show->get($field)->isEmpty()
      && $show->get($field)->value === 'kids') {
      return FALSE;
    }
    return TRUE;
  }

}
