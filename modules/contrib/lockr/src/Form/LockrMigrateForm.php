<?php

namespace Drupal\lockr\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

use Lockr\Lockr;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\lockr\CertWriter;
use Drupal\lockr\ClientFactory;

/**
 * Form handler for Lockr move to production.
 */
class LockrMigrateForm implements ContainerInjectionInterface, FormInterface {

  use StringTranslationTrait;

  /**
   * Simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Lockr library client.
   *
   * @var Lockr
   */
  protected $lockr;

  /**
   * Constructs a new LockrMigrateForm.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param Lockr $lockr
   *   The Lockr library client.
   * @param TranslationInterface $translation
   *   The Drupal translator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Lockr $lockr,
    TranslationInterface $translation
  ) {
    $this->configFactory = $config_factory;
    $this->lockr = $lockr;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('lockr.lockr'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lockr_migrate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $build_info = $form_state->getBuildInfo();
    $info = $build_info['args'][0];
    $form['instructions'] = [
      '#preix' => '<p>',
      '#markup' => $this->t('Click the button below to deploy this site to production. This should only be done in your production environment as it writes a new certificate to the file system.'),
      '#suffix' => '</p>',
    ];

    $form['#attached']['library'][] = 'lockr/move_to_prod';
    $form['#attached']['drupalSettings']['lockr'] = [
      'accounts_host' => 'https://accounts.lockr.io',
      'keyring_id' => $info['keyring']['id'],
    ];

    $form['client_token'] = [
      '#type' => 'hidden',
      '#required' => TRUE,
    ];

    $form['move_to_prod'] = [
      '#type' => 'button',
      '#value' => $this->t('Migrate to Production'),
      '#attributes' => [
        'class' => ['move-to-prod'],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => [
        'class' => ['move-to-prod-submit'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $client_token = $form_state->getValue('client_token');
    $dn = [
      'countryName' => 'US',
      'stateOrProvinceName' => 'Washington',
      'localityName' => 'Tacoma',
      'organizationName' => 'Lockr',
    ];
    try {
      $result = $this->lockr->createCertClient($client_token, $dn);
      CertWriter::writeCerts('prod', $result);
      $config = $this->configFactory->getEditable('lockr.settings');
      $config->set('custom', TRUE);
      $config->set('cert_path', 'private://lockr/prod/pair.pem');
      $config->save();
    }
    catch (\Exception $e) {
      // XXX: probably log and/or show message
      throw $e;
      return;
    }
  }

}
