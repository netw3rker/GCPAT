<?php

namespace Drupal\lockr\Plugin\KeyType;

use Lockr\Lockr;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyTypeBase;

/**
 * Defines a key type for encryption that generates keys with Lockr.
 *
 * @KeyType(
 *   id = "lockr_encryption",
 *   label = @Translation("Lockr Encryption"),
 *   description = @Translation("A key type used for encryption, generating keys using Townsend AKM."),
 *   group = "encryption",
 *   key_value = {
 *     "plugin" = "generate"
 *   }
 * )
 */
class LockrEncryptionKeyType extends KeyTypeBase
  implements KeyPluginFormInterface {

  /**
   * Lockr library client.
   *
   * @var Lockr
   */
  protected $lockr;

  /**
   * Constructs a new LockrEncryptionKeyType.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param Lockr $lockr
   *   The Lockr library client.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Lockr $lockr
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->lockr = $lockr;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('lockr.lockr')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['key_size' => 256];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ) {
    $key_size_options = [
      '128' => 128,
      '192' => 192,
      '256' => 256,
    ];

    $key_size = $this->getConfiguration()['key_size'];

    $form['key_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Key size'),
      '#description' => $this->t('The size of the key in bits.'),
      '#options' => $key_size_options,
      '#default_value' => $key_size,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    // Default validation is fine.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public static function generateKeyValue(array $configuration) {
    $key_size = $configuration['key_size'];
    return \Drupal::service('lockr.lockr')->generateKey((int) $key_size);
  }

  /**
   * {@inheritdoc}
   */
  public function validateKeyValue(array $form, FormStateInterface $form_state, $key_value) {
    if (!$form_state->getValue('key_size')) {
      return;
    }

    // Validate the key size.
    $bytes = $form_state->getValue('key_size') / 8;
    if (strlen($key_value) != $bytes) {
      $form_state->setErrorByName('key_size', $this->t('The selected key size does not match the actual size of the key.'));
    }
  }

}

