<?php

namespace Drupal\media_manager\Form;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\media_manager\ApiClient;
use Drupal\media_manager\ShowManager;
use Drupal\media_manager\VideoContentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MediaManagerSettingsForm.
 *
 * @ingroup media_manager
 */
class MediaManagerSettingsForm extends ConfigFormBase {

  /**
   * Media Manager API client.
   *
   * @var \Drupal\media_manager\ApiClient
   */
  protected $apiClient;

  /**
   * Date formatting service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new MediaManagerSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   Config factory service.
   * @param \Drupal\media_manager\ApiClient $api_client
   *   Media Manager API client service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   Date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    ConfigFactory $config_factory,
    ApiClient $api_client,
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    parent::__construct($config_factory);
    $this->apiClient = $api_client;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('media_manager.api_client'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'media_manager_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['media_manager.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('media_manager.settings');
    $interval_options = [3600, 10800, 21600, 43200, 86400, 604800];

    /*
     * Base API settings.
     */

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('Media Manager API settings'),
      '#open' => TRUE,
    ];

    $form['api']['key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('PBS Media Manager API key.'),
      '#default_value' => $this->apiClient->getApiKey(),
    ];

    $form['api']['secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#description' => $this->t('PBS Media Manager API secret.'),
      '#default_value' => $this->apiClient->getApiSecret(),
    ];

    $form['api']['base_uri'] = [
      '#type' => 'select',
      '#title' => $this->t('Base endpoint'),
      '#description' => $this->t('PBS Media Manager API base endpoint.'),
      '#options' => [
        'staging' => $this->t('Staging'),
        'live' => $this->t('Production'),
      ],
    ];

    $base_uri = $this->apiClient->getApiEndPoint();
    if ($base_uri == ApiClient::LIVE) {
      $form['api']['base_uri']['#default_value'] = 'live';
    }
    else {
      $form['api']['base_uri']['#default_value'] = 'staging';
    }

    /*
     * Shows queue autoupdate settings.
     */

    $form['shows_queue'] = [
      '#type' => 'details',
      '#title' => $this->t('Shows queue settings'),
      '#open' => TRUE,
    ];

    $form['shows_queue']['shows_queue_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automated queue building'),
      '#description' => $this->t('Enable incremental updates to local Show
        nodes from Media Manager data.'),
      '#default_value' => $config
        ->get(ShowManager::getAutoUpdateConfigName()),
      '#return_value' => TRUE,
    ];

    // Get a count of current PBS show list.
    $show_ids = $config->get('shows.show_ids');
    $show_count = 0;
    if (!empty($show_ids)) {
      $show_ids_array = explode("\n", $show_ids);
      $show_count = count($show_ids_array);
    }
    $form['shows_queue']['show_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Media Manager Show IDs'),
      '#default_value' => $show_ids ?? '',
      '#required' => FALSE,
      '#description' => $this->t('The list of show IDS (PBS Content IDs) to keep synced with this site. One show ID (e.g. 65b5ac61-6c29-4d0d-bc94-9dd572fd9c64) per line. Current count: @count.', ['@count' => $show_count]),
      '#states' => [
        'visible' => [
          'input[name="shows_queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['shows_queue']['shows_queue_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Queue builder update interval'),
      '#description' => $this->t('How often to check Media Manager for
        new or updated shows to add to the queue. The queue itself is processed
        one every cron ron (or by an external cron operation).'),
      '#default_value' => $config
        ->get(ShowManager::getAutoUpdateIntervalConfigName()),
      '#options' => array_map(
        [$this->dateFormatter, 'formatInterval'],
        array_combine($interval_options, $interval_options)
      ),
      '#states' => [
        'visible' => [
          'input[name="shows_queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $drupal_content_types = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $content_type_options = array_combine($drupal_content_types, $drupal_content_types);
    $form['shows_queue']['drupal_show_content'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal Show content type'),
      '#description' => $this->t('Must be a Drupal node.'),
      '#default_value' => $config->get('shows.drupal_show_content'),
      '#options' => $content_type_options,
      '#states' => [
        'visible' => [
          'input[name="shows_queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['shows_queue']['map_fields'] = [
      '#type' => 'link',
      '#title' => $this->t('Map show fields'),
      '#url' => Url::fromRoute('media_manager.show_mappings', [
        'destination' => 'admin/config/media/media-manager']),
      '#states' => [
        'visible' => [
          'input[name="shows_queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $config = $this->config('media_manager.settings');

    $config->set(ApiClient::CONFIG_KEY, $values['key']);
    $config->set(ApiClient::CONFIG_SECRET, $values['secret']);
    $config->set( ApiClient::CONFIG_BASE_URI, $values['base_uri']);

    $config->set(
      ShowManager::getAutoUpdateConfigName(),
      $values['shows_queue_enable']
    );

    $config->set('shows.drupal_show_content', $values['drupal_show_content']);

    if ($form_state->getValue('shows_queue_enable')) {
      $config->set(
        ShowManager::getAutoUpdateIntervalConfigName(),
        (int) $values['shows_queue_interval']
      );
    }

    $config->set('shows.show_ids', $values['show_ids']);

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
