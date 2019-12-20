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

    $vocabularies = \Drupal\taxonomy\Entity\Vocabulary::loadMultiple();
    $form['genre_vocabularly'] = [
      '#type' => 'select',
      '#title' => 'Genre vocabularly (taxonomy)',
      '#options' => array_keys($vocabularies),
      '#default_value' => 'not used',
    ];

    // TODO: Use this array to make some fields required below.
    $required_show_fields = [
      'tms_id',
      'id',
    ];

    $show_fields = array_keys($this
      ->entityFieldManager
      ->getFieldDefinitions('node', $show_content_type));
    $show_field_options = array_combine($show_fields, $show_fields);
    // Add a default 'unused' option.
    array_unshift($show_field_options, ['unused' => 'unused']);
    $pbs_show_fields = $config->get('shows.mappings');
    foreach ($pbs_show_fields as $field_name => $field_value) {
      $default = !empty($pbs_show_fields[$field_name]) ?
        $pbs_show_fields[$field_name] : 'unused';
      $form[$field_name] = [
        '#type' => 'select',
        '#title' => $field_name,
        '#options' => $show_field_options,
        '#default_value' => $pbs_show_fields[$field_name],
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

    $pbs_show_fields = $config->get('shows.mappings');
    foreach ($pbs_show_fields as $field_name => $field_value) {
      $config->set('shows.mappings.' . $field_name, $values[$field_name]);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
