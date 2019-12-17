<?php

namespace Drupal\media_manager\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ShowMappingsForm.
 *
 * @ingroup media_manager
 */
class ShowMappingsForm extends ConfigFormBase {

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
   * Constructs a new ShowMappingsForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'media_manager_show_mappings';
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('media_manager.settings');

    $show_content_type = $config->get('shows.drupal_show_content');

    // TODO: for testing
    $show_content_type = 'article';
    $pbs_show_fields = $config->get('shows.mappings');

    $vocabularies = \Drupal\taxonomy\Entity\Vocabulary::loadMultiple();
    $form['genre_vocabularly'] = [
      '#type' => 'select',
      '#title' => 'Genre vocabularly (taxonomy)',
      '#options' => array_keys($vocabularies),
      '#default_value' => 'not used',
    ];

    // TODO: Use this array to make some fields required
    $required_show_fields = [
      'tms_id',
      'id',
    ];

    $article_fields = array_keys($this
      ->entityFieldManager
      ->getFieldDefinitions('node', $show_content_type));
    array_push($article_fields, 'not used');
    foreach ($pbs_show_fields as $field_name => $field_value) {
      $form[$field_name] = [
        '#type' => 'select',
        '#title' => $field_name,
        '#options' => $article_fields,
        '#default_value' => 'not used',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('media_manager.settings');

    $config->set('shows.mappings.call_sign', $form_state->getValue('call_sign'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
