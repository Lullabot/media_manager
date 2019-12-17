<?php

namespace Drupal\media_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use OpenPublicMedia\PbsMediaManager\Client;
use OpenPublicMedia\PbsMediaManager\Exception\BadRequestException;

/**
 * Class ApiClient.
 *
 * @package Drupal\media_manager
 */
class ApiClient extends Client {

  /**
   * Config key for the API key.
   */
  const CONFIG_KEY = 'api.key';

  /**
   * Config key for the API secret.
   */
  const CONFIG_SECRET = 'api.secret';

  /**
   * Config key for the API base endpoint.
   */
  const CONFIG_BASE_URI = 'api.base_uri';

  /**
   * API key.
   *
   * @var string
   */
  private $key;

  /**
   * API secret.
   *
   * @var string
   */
  private $secret;

  /**
   * API base endpoint.
   *
   * @var string
   */
  public $baseUri;

  /**
   * Media Manager immutable config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * ApiClient constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {

    $this->config = $config_factory->get('media_manager.settings');
    $this->key = $this->config->get(self::CONFIG_KEY);
    $this->secret = $this->config->get(self::CONFIG_SECRET);

    $base_uri = $this->config->get(self::CONFIG_BASE_URI);
    if ($base_uri === 'staging') {
      $base_uri = self::STAGING;
    }
    elseif ($base_uri === 'live') {
      $base_uri = self::LIVE;
    }
    else {
      // TODO: This probably should default to STAGING.
      $base_uri = self::LIVE;
    }
    $this->baseUri = $base_uri;

    parent::__construct($this->key, $this->secret, $this->baseUri);
  }

  /**
   * Gets the API client key.
   *
   * @return string
   *   Media Manager API key setting.
   */
  public function getApiKey(): string {
    return $this->key;
  }

  /**
   * Gets the API client secret.
   *
   * @return string
   *   Media Manager API secret setting.
   */
  public function getApiSecret(): string {
    return $this->secret;
  }

  /**
   * Gets the API client base endpoint.
   *
   * @return string
   *   Media Manager API endpoint setting.
   */
  public function getApiEndPoint(): string {
    return $this->baseUri;
  }

  /**
   * Indicates if the API client is configured.
   *
   * @return bool
   *   TRUE if the key, secret, and endpoint are set. FALSE otherwise.
   */
  public function isConfigured(): bool {
    return (
      !empty($this->getApiKey()) &&
      !empty($this->getApiSecret()) &&
      !empty($this->getApiEndPoint())
    );
  }

  /**
   * Sends a test query to the API and returns an error or "OK".
   *
   * @return string
   *   An error string or "OK" (indicating that the connection is good).
   */
  public function testConnection(): string {
    $result = 'OK';

    try {
      $this->request('get', 'genres');
    }
    catch (BadRequestException $e) {
      $error = json_decode($e->getMessage());
      if (isset($error->detail)) {
        $result = $error->detail;
      }
      else {
        $result = (string) $error;
      }
    }

    return $result;
  }

}
