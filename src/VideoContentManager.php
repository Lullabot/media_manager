<?php

namespace Drupal\media_manager;

use DateInterval;
use DateTime;
use DOMDocument;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use InvalidArgumentException;
use stdClass;

/**
 * Class VideoContentManager.
 *
 * @package Drupal\media_manager
 */
class VideoContentManager extends ApiContentManagerBase {

  /**
   * State key for the last update DateTime.
   */
  const LAST_UPDATE_KEY = 'media_manager.video_content.last_update';

  /**
   * Video Content batch queue machine name.
   */
  const BATCH_QUEUE_NAME = 'media_manager.queue.video_content.batch_queue';

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
    return 'video_content';
  }

  /**
   * {@inheritdoc}
   */
  public static function getQueueName(): string {
    return 'media_manager.queue.video_content';
  }

  /**
   * {@inheritdoc}
   *
   * The default time is limited to the past week to prevent very large batches
   * of sync data.
   */
  public function getLastUpdateTime(): DateTime {
    $last_update = $this->state->get(self::LAST_UPDATE_KEY, FALSE);
    if (!$last_update) {
      $last_update = new DateTime();
      $last_update->sub(new DateInterval('P7D'));
    }
    return $last_update;
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
    return 'video_content.queue.autoupdate';
  }

  /**
   * {@inheritdoc}
   */
  public static function getAutoUpdateIntervalConfigName(): string {
    return 'video_content.queue.autoupdate_interval';
  }

  /**
   * Gets an API response for a single Asset.
   *
   * @param string $id
   *   ID of the Asset to get.
   *
   * @return object|null
   *   Asset item data from the API or NULL.
   */
  public function getAsset(string $id): ?stdClass {
    return $this->client->getAsset($id);
  }

  /**
   * {@inheritdoc}
   *
   * This process batches requests in to groups of 50 Shows at a time. The
   * Media Manager API documentation does not set a hard limit on the number of
   * filters that can be used in a single request. It does specify 50 as a
   * ideline maximum for _other_ data model object endpoint so that limit is
   * used here out of an abundance of caution.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Exception
   */
  public function updateQueue(DateTime $since = NULL): bool {
    $time_limit = (int) ini_get('max_execution_time');
    $start = microtime(TRUE);
    $start_dt = new DateTime();

    if (empty($since)) {
      $since = $this->getLastUpdateTime();
    }

    $definition = $this->entityTypeManager->getDefinition(self::getEntityTypeId());
    $shows = $this->entityTypeManager->getStorage(self::getEntityTypeId())
      ->loadByProperties([
        $definition->getKey('bundle') => ShowManager::getBundleId(),
      ]);

    // Rekey the array using Media Manager IDs.
    array_filter($shows, [$this, 'nodeHasGuid']);
    $shows = array_combine(array_map([$this, 'getNodeGuid'], $shows), $shows);

    $batches = array_chunk($shows, 50, TRUE);
    foreach ($batches as $batch) {

      // Give up if the PHP script is about to timeout. This will prevent the
      // last update state value from updating in order to avoid unexpected
      // missed Asset updates. But it will also leave the queue in an ambiguous
      // state with only a portion of the updates queued. Essentially, it is
      // highly recommend to run this process in an environment where PHP's
      // `max_execution_time` is set to unlimited.
      if ($time_limit > 0 && microtime(TRUE) - $start > $time_limit) {
        $this->logger->critical($this->t('Unable to complete Video Content
          queue processing due to PHP script time out setting. Consider
          increasing the time limit or running this process without one.'));
        return FALSE;
      }

      $assets = $this->client->getAssets([
        'fetch-related' => FALSE,
        'show-id' => array_keys($batch),
        // Sorting by updated_at allows the loop to exit as early as possible.
        'sort' => '-updated_at',
      ]);

      foreach ($assets as $asset) {
        $updated_at = self::getLatestUpdatedAt($asset);
        if ($updated_at > $since) {
          // Find the Asset's parent Show ID.
          $parent_tree = self::parseParentTree($asset->attributes->parent_tree);
          if (isset($parent_tree['show'])) {
            if (isset($shows[$parent_tree['show']])) {
              $this->getQueue()->createItem([
                'asset' => $asset,
                'show' => $shows[$parent_tree['show']],
              ]);
            }
            else {
              // ID not found in $shows would potentially indicate some bug
              // with this code or with the API response. This should never
              // happen.
              $this->logger->error($this->t('Unexpected parent Show @show_id
                for Asset @asset_id .', [
                  '@show_id' => $parent_tree['show'],
                  '@asset_id' => $asset->id,
                ]));
            }
          }
          else {
            // All Asset objects should have a Show in the parent tree (except
            // Franchise assets, which are not covered in this module). If an
            // Asset is found to have a parent Show, there is a bug in the
            // parent tree parsing or the API response.
            $this->logger->error($this->t('No parent Show found for Asset ID
              @id.', ['@id' => $asset->id]));
          }
          continue;
        }
        break;
      }
    }

    // This is set to the start time because run time could be long. This means
    // that the queue update may occasionally double-up content, but it should
    // never _miss_ any.
    $this->setLastUpdateTime($start_dt);

    return TRUE;
  }

  /**
   * Parse a Media Manager API Asset parent tree.
   *
   * Franchise is accounted for here, though it is not currently expected that
   * it will ever be used (for this Drupal module).
   *
   * @param object $parent_tree
   *   Parent tree data from the Media Manager Asset.
   *
   * @return array
   *   Ordered array keyed by parent object types and their Media Manager ID
   *   values. E.g. --
   *
   * @code
   * <?php
   * $tree = [
   *   'episode' => 'e358cacc-86e6-46a8-9269-dc72c8e34143',
   *   'season' => 'da41c844-f87e-4c89-ae40-f41c72ebdd0f',
   *   'show' => '294a8c40-1d69-4711-9163-5d17872b40e2',
   *   'franchise' => 'e08bf78d-e6a3-44b9-b356-8753d01c7327',
   * ];
   * @endcode
   */
  public static function parseParentTree(object $parent_tree): array {
    $tree = [];

    // The initial parent will only ever be "franchise", "show", "special",
    // "season", or "episode".
    if (isset($parent_tree->type)) {
      $tree[$parent_tree->type] = $parent_tree->id;
    }
    $branch = $parent_tree->attributes;

    // "episode" will have a "season".
    if (isset($branch->season)) {
      $tree['season'] = $branch->season->id;
      $branch = $branch->season->attributes;
    }

    // "special" or "season" will have a "show".
    if (isset($branch->show)) {
      $tree['show'] = $branch->show->id;
      $branch = $branch->show->attributes;
    }

    // "show" _might_ have a "franchise".
    if (isset($branch->franchise)) {
      $tree['franchise'] = $branch->franchise->id;
    }

    return $tree;
  }

  /**
   * Adds or updates a Video Content node with Media Manager API data.
   *
   * @param object $item
   *   Media Manager API data for an Asset.
   * @param \Drupal\node\NodeInterface|null $show
   *   The Asset's parent Show node. If NULL, an existing Video Content node
   *   must exist or an InvalidArgumentException will be thrown.
   * @param bool $force
   *   Whether or not to "force" the update. If FALSE (default) and a current
   *   Video Content node is found with a matching or newer "last_updated"
   *   value, the existing node will not be updated. If TRUE, "last_updated" is
   *   ignored.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \InvalidArgumentException
   */
  public function addOrUpdateVideoContent(
    object $item,
    NodeInterface $show = NULL,
    bool $force = FALSE
  ): void {
    $node = $this->getOrCreateNode($item->id, self::getBundleId());

    if (empty($show)) {
      if ($node->get('field_show_ref')->isEmpty()) {
        throw new InvalidArgumentException($this->t('Show must be provided when
          the Video Content node does not already exist.'));
      }
      $show = $node->get('field_show_ref')->entity;
    }

    $attributes = $item->attributes;
    $images = $this->parseImages($attributes->images);
    $updated_at = self::getLatestUpdatedAt($item);

    // Do not continue if the Asset has not be updated and $force is FALSE.
    if (!$force && !$node->get('field_last_updated')->isEmpty()) {
      $updated_at_node = $node->get('field_last_updated')->date
        ->getPhpDateTime();
      if ($updated_at_node >= $updated_at) {
        return;
      }
    }

    /*
     * Required fields.
     */

    $node->setTitle($attributes->title);
    $node->set(self::ID_FIELD_NAME, $item->id);
    $node->set('field_video_content_slug', $attributes->slug);
    $node->set('field_show_ref', $show);
    $node->set('field_description', [
      'value' => $attributes->description_long,
      'summary' => $attributes->description_short,
    ]);
    $node->set(
      'field_last_updated',
      $updated_at->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT)
    );
    $node->set('field_seconds', $attributes->duration);

    /*
     * Type-specific fields.
     */

    $type = $attributes->object_type;
    $node->set('field_video_object_type', $type);

    if ($type === 'full_length' && !empty($attributes->episode)) {
      // This may be "episode" or "special".
      $type = $attributes->episode->type;
    }
    else {
      // The above covers "full_length" so this should be only either "preview"
      // or "clip".
      $type = $attributes->object_type;
    }
    $node->set('field_video_type', $type);

    if (isset($attributes->parent_tree)) {
      $parent_attributes = $attributes->parent_tree->attributes;

      if (isset($parent_attributes->ordinal)
        && !empty($parent_attributes->ordinal)) {
        $node->set('field_video_ordinal', $parent_attributes->ordinal);
        $node->set('field_ordinal_number', $parent_attributes->ordinal);
      }
      else {
        $node->set('field_video_ordinal', NULL);
        $node->set('field_ordinal_number', NULL);
      }

      if (isset($parent_attributes->season)
        && !empty($parent_attributes->season->attributes->ordinal)) {
        $node->set(
          'field_video_content_season',
          $parent_attributes->season->attributes->ordinal
        );
      }
      else {
        $node->set('field_video_content_season', NULL);
      }
    }

    /*
     * Optional fields.
     */

    // Inherit Genre from parent Show node.
    if (!$show->get('field_genre')->isEmpty()) {
      $node->set('field_genre', $show->get('field_genre')->entity);
    }
    else {
      $node->set('field_genre', NULL);
    }

    // For new nodes, copy parent Show editorial genres exactly.
    if ($node->isNew()) {
      $node->set(
        'field_editorial_genre',
        $show->get('field_editorial_genre')->getValue()
      );
    }
    // For existing nodes, only add genres from parent Show that are missing.
    else {
      $genres = array_udiff(
        $show->get('field_editorial_genre')->getValue(),
        $node->get('field_editorial_genre')->getValue(),
        [$this, 'compareTargetIds']
      );
      foreach ($genres as $genre) {
        $node->get('field_editorial_genre')->appendItem($genre);
      }
    }

    if (!empty($attributes->player_code)) {
      $video_id = self::extractEmbedCodeVideoId($attributes->player_code);
      if (empty($video_id)) {
        $node->set('field_video_player_code', NULL);
        $this->logger->error($this->t('Unable to extract Video ID from asset
          {@id}', ['@id' => $item->id]));
      }
      else {
        $node->set('field_video_player_code', $video_id);
      }
    }

    if (!empty($attributes->content_rating)) {
      $node->set('field_video_rating', $attributes->content_rating);
    }
    else {
      $node->set('field_video_rating', NULL);
    }

    if (!empty($attributes->encored_on)) {
      $node->set('field_encore_date', $attributes->encored_on);
    }
    else {
      $node->set('field_encore_date', NULL);
    }

    if (!empty($attributes->premiered_on)) {
      $node->set('field_premiere_date', $attributes->premiered_on);
    }
    else {
      $node->set('field_premiere_date', NULL);
    }

    if (isset($images['asset-mezzanine-16x9'])) {
      $node->set('field_video_image', $images['asset-mezzanine-16x9']);
    }
    elseif (isset($images['asset-kids-mezzanine-16x9'])) {
      $node->set('field_video_image', $images['asset-kids-mezzanine-16x9']);
    }
    elseif (isset($images['asset-kids-mezzanine1-16x9'])) {
      $node->set('field_video_image', $images['asset-kids-mezzanine1-16x9']);
    }
    else {
      $node->set('field_video_image', NULL);
    }

    /*
     * Availabilities.
     */

    $availabilities = [
      'field_all_mem_avail_end' => $attributes->availabilities->all_members->end,
      'field_all_mem_avail_start' => $attributes->availabilities->all_members->start,
      'field_pub_avail_end' => $attributes->availabilities->public->end,
      'field_pub_avail_start' => $attributes->availabilities->public->start,
      // Station member information is synced, but not currently used. In order
      // to use this information, changing would need to be made to the Show
      // sync process to capture station audience information. These dates only
      // apply when the Show static matches the station we are interested in
      // and currently we do not use this functionality in Media Manager.
      'field_station_mem_avail_end' => $attributes->availabilities->station_members->end,
      'field_station_mem_avail_start' => $attributes->availabilities->station_members->start,
    ];
    foreach ($availabilities as $field_name => $value) {
      if (!empty($value)
        && $availability_dt = self::dateTimeNoMicroseconds($value)) {
        $node->set(
          $field_name,
          $availability_dt->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT)
        );
      }
      else {
        $node->set($field_name, NULL);
      }
    }

    /*
     * Node status.
     *
     * Video Content node status is only evaluated if the related Show node is
     * publishable. Video Content for unpublishable Shows should never be
     * published.
     */

    if ($show->get('publishable')->value) {
      // Set Scheduler-based publish and unpublish values for the node if the
      // fields are available.
      $now = new DrupalDateTime();
      $start = self::getEarliestAvailabilityStartDate($node);
      $end = self::getLatestAvailabilityEndDate($node);
      $publish_scheduled = FALSE;
      if ($node->hasField('publish_on')) {
        if ($start && $start > $now) {
          $node->set('publish_on', $start->getTimestamp());
          $publish_scheduled = TRUE;
        }
        else {
          $node->set('publish_on', NULL);
        }
      }
      if ($node->hasField('unpublish_on')) {
        if ($end && $end > $now) {
          $node->set('unpublish_on', $end->getTimestamp());
        }
        else {
          $node->set('unpublish_on', NULL);
        }
      }

      // Set node published state based on whether or not the video is available
      // now. The computed field "available" cannot be used here because it is
      // not available until after a node has been saved. This check accounts
      // for both new and existing nodes.
      if ($start && $start <= $now && (empty($end) || $end > $now)) {
        $node->setPublished();
      }
      else {
        $node->setUnpublished();
      }

      // Publish the related Show node if it is currently unpublished and this
      // Video Content node is published or scheduled to publish.
      if (!$show->isPublished() && ($node->isPublished() || $publish_scheduled)) {
        $show->setPublished();
        $show->save();
      }
    }
    else {
      $node->setUnpublished();
      if ($node->hasField('publish_on')) {
        $node->set('publish_on', NULL);
      }
      if ($node->hasField('unpublish_on')) {
        $node->set('unpublish_on', NULL);
      }
    }

    $node->save();
  }

  /**
   * Comapare arrays with "target_id" keys.
   *
   * This method is meant to be used with `array_udiff` to compare field with
   * multiple entity reference items.
   *
   * @param array $a
   *   Array A with "target_id" key.
   * @param array $b
   *   Array b with "target_id" key.
   *
   * @return int
   *   -1, 0, 1 if Array A's "target_id" value is less than, equal to, or
   *   greater than Array B's "target_id" value respectively.
   */
  public static function compareTargetIds(array $a, array $b): int {
    if ($a['target_id'] < $b['target_id']) {
      return -1;
    }
    if ($a['target_id'] == $b['target_id']) {
      return 0;
    }
    else {
      return 1;
    }
  }

  /**
   * Attempts to extract a video ID from an iframe embed code.
   *
   * @param string $code
   *   An HTML embed code string, e.g. "<iframe id='partnerPlayer'
   *   frameborder='0' marginwidth='0' marginheight='0' scrolling='no'
   *   width='100%' height='100%'
   *   src='//player.pbs.org/partnerplayer/qvbejbvsH7nskeELmqmduQ=='
   *   allowfullscreen></iframe>". The target "Video ID" in this string is
   *   "qvbejbvsH7nskeELmqmduQ==".
   *
   * @return string|null
   *   The extracted code or NULL if one is not found.
   */
  public static function extractEmbedCodeVideoId(string $code): ?string {
    $iframe = Xss::filter($code, ['iframe']);

    // Attempt to extract the video ID from the iframe `src` attribute.
    $dom = new DOMDocument();

    // Suppress XML error messages and load HTML.
    $internalErrors = libxml_use_internal_errors(TRUE);
    $dom->loadHTML($iframe);
    libxml_use_internal_errors($internalErrors);

    /* @var \DOMNodeList $tags */
    $tags = $dom->getElementsByTagName('iframe');
    $code = NULL;
    if (!empty($tags) && $tags->length > 0) {
      /* @var \DOMElement $tag */
      $tag = $tags->item(0);
      $src = $tag->getAttribute('src');

      // Gets the last element from the `src` path (the Video ID).
      $code = basename(parse_url($src, PHP_URL_PATH));
    }

    return $code;
  }

  /**
   * Gets all Video Content date field pairs (start and end dates).
   *
   * @return array
   *   Field data keyed by type, each with a "start" and "end" key matching the
   *   appropriate field name.
   */
  public static function getDateFieldPairs(): array {
    return [
      'public' => [
        'start' => 'field_pub_avail_start',
        'end' => 'field_pub_avail_end',
      ],
      // Station is not currently considered.
      /*'station' => [
        'start' => 'field_station_mem_avail_start',
        'end' => 'field_station_mem_avail_end',
      ],*/
      'passport' => [
        'start' => 'field_all_mem_avail_start',
        'end' => 'field_all_mem_avail_end',
      ],
    ];
  }

  /**
   * Determines if video is available to _any_ group of users.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Video Content node.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $date
   *   Date to check for, defaults to current date/time if none provided.
   *
   * @return bool
   *   TRUE is the video is available to any group, FALSE otherwise.
   *
   * @throws \Exception
   */
  public static function videoIsAvailable(
    NodeInterface $node,
    ?DrupalDateTime $date = NULL
  ): bool {
    $available = FALSE;

    if (!$date) {
      $date = new DrupalDateTime();
    }

    $start = self::getEarliestAvailabilityStartDate($node);
    $end = self::getLatestAvailabilityEndDate($node);

    // Start date is passed, no end date.
    if ($start && $date > $start && !$end) {
      $available = TRUE;
    }
    // Start date is passed, end date is not passed.
    elseif ($start && $date > $start && $end && $date < $end) {
      $available = TRUE;
    }

    return $available;
  }

  /**
   * Determines if video is _only_ available on Passport.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Video Content node.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $date
   *   Date to check for, defaults to current date/time if none provided.
   *
   * @return bool
   *   TRUE is the video is only available on Passport, FALSE otherwise.
   *
   * @throws \Exception
   */
  public static function videoIsPassportOnly(
    NodeInterface $node,
    ?DrupalDateTime $date = NULL
  ): bool {
    $passport_only = FALSE;

    if (!$date) {
      $date = new DrupalDateTime();
    }

    foreach (self::getDateFieldPairs() as $type => $fields) {
      $start = $node->get($fields['start'])->date;
      $end = $node->get($fields['end'])->date;

      // If $now is within the availability dates, check the type and determine
      // the Passport state.
      if ($start && $date > $start && (!$end || $date < $end)) {
        // If the type is public, the video is definitely not Passport only.
        if ($type == 'public') {
          $passport_only = FALSE;
          break;
        }
        // If the type is passport, the video _might_ be Passport only.
        elseif ($type == 'passport') {
          $passport_only = TRUE;
        }
      }
    }

    return $passport_only;
  }

  /**
   * Gets the earliest availability start date for a Video Content node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Video Content node.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   Earliest availability start datetime or NULL if no end dates are set.
   */
  public static function getEarliestAvailabilityStartDate(NodeInterface $node): ?DrupalDateTime {
    $earliest = NULL;
    foreach (self::getDateFieldPairs() as $fields) {
      $start = $node->get($fields['start'])->date;
      if (empty($earliest)) {
        $earliest = $start;
      }
      elseif ($start < $earliest) {
        $earliest = $node->get($fields['start'])->date;
      }
    }
    return $earliest;
  }

  /**
   * Gets the latest availability end date for a Video Content node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Video Content node.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   Latest availability end datetime or NULL if no end dates are set.
   */
  public static function getLatestAvailabilityEndDate(NodeInterface $node): ?DrupalDateTime {
    $latest = NULL;
    foreach (self::getDateFieldPairs() as $fields) {
      if ($node->get($fields['end'])->date > $latest) {
        $latest = $node->get($fields['end'])->date;
      }
    }
    return $latest;
  }

}
