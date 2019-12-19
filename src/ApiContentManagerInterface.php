<?php

namespace Drupal\media_manager;

use DateTime;
use Drupal\Core\Queue\QueueInterface;

/**
 * Interface ApiContentManagerInterface.
 *
 * @package Drupal\media_manager
 */
interface ApiContentManagerInterface {

  /**
   * Gets the entity type machine name (ID) for the content being managed.
   *
   * @return string
   *   Entity type machine name.
   */
  public static function getEntityTypeId(): string;

  /**
   * Gets the bundle machine name (ID) for the content being managed.
   *
   * @return string
   *   Bundle machine name.
   */
  public function getBundleId(): string;

  /**
   * Gets the queue machine name for the API content type.
   *
   * @return string
   *   Queue machine name.
   */
  public static function getQueueName(): string;

  /**
   * Gets the queue for the API content type.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   API content type update queue.
   */
  public function getQueue(): QueueInterface;

  /**
   * Adds items to the API content type's queue based on a time constraint.
   *
   * @param \DateTime|null $since
   *   Time constraint for the queue cutoff. Defaults to the last time the queue
   *   was updated.
   *
   * @return bool
   *   TRUE if the queue update fully completes, FALSE if it does not.
   */
  public function updateQueue(DateTime $since = NULL): bool;

  /**
   * Gets the date and time of the last API content type sync.
   *
   * @return \DateTime
   *   Date and time of last API content type sync.
   */
  public function getLastUpdateTime(): DateTime;

  /**
   * Sets the date and time of the last API content type sync.
   *
   * @param \DateTime $time
   *   Date and time to set.
   */
  public function setLastUpdateTime(DateTime $time): void;

  /**
   * Resets the stored date and time of the last API content type sync.
   */
  public function resetLastUpdateTime(): void;

  /**
   * Gets the machine name for the API content type's auto update config.
   *
   * @return string
   *   Config machine name.
   */
  public static function getAutoUpdateConfigName(): string;

  /**
   * Gets the machine name for the API content type interval config.
   *
   * @return string
   *   Config machine name.
   */
  public static function getAutoUpdateIntervalConfigName(): string;

  /**
   * Gets the underlying Media Manager API client.
   *
   * @return \Drupal\media_manager\ApiClient
   *   Media Manager API Client service.
   */
  public function getApiClient(): ApiClient;

}
