<?php

namespace Drupal\lockr\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for advanced Lockr settings.
 */
class LockrAdvancedForm implements ContainerInjectionInterface, FormInterface {

  use StringTranslationTrait;

  /**
   * Simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal file system interface.
   *
   * @var FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new LockrAdvancedForm.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param FileSystemInterface $file_system
   *   The Drupal file system interface.
   * @param TranslationInterface $translation
   *   The Drupal translator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    TranslationInterface $translation
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lockr_advanced_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['fs'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $config = $this->configFactory->get('lockr.settings');

    $form['fs']['region'] = [
      '#type' => 'select',
      '#title' => $this->t('Region'),
      '#default_value' => $config->get('region'),
      '#empty_option' => $this->t('- Nearest -'),
      '#options' => [
        'us' => 'US',
        'eu' => 'EU',
      ],
    ];

    $form['fs']['custom'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set custom certificate location'),
      '#default_value' => $config->get('custom', FALSE),
    ];

    $form['fs']['custom_cert'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Certificate path'),
      '#default_value' => $config->get('cert_path'),
      '#states' => [
        'visible' => [':input[name="custom"]' => ['checked' => TRUE]],
      ],
    ];

    $form['fs']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('custom')) {
      return;
    }

    $cert_path = $form_state->getValue('custom_cert');

    if (!$cert_path) {
      $form_state->setErrorByName(
        'custom_cert',
        $this->t('Certificate location is required for custom certs')
      );
      return;
    }

    $real_cert_path = $this->fileSystem->realpath($cert_path);
    if (is_dir($real_cert_path) || !is_readable($real_cert_path)) {
      $form_state->setErrorByName(
        'custom_cert',
        $this->t('Certificate must be a readable file')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $custom = $form_state->getValue('custom');
    $region = $form_state->getValue('region');

    $config = $this->configFactory->getEditable('lockr.settings');

    if ($region) {
      $config->set('region', $region);
    }
    else {
      $config->clear('region');
    }

    $config->set('custom', $custom);
    if ($custom) {
      $config->set('cert_path', $form_state->getValue('custom_cert'));
    }
    else {
      $config->clear('custom_cert');
    }
    $config->save();
  }

}
