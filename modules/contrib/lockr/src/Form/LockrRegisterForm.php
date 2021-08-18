<?php

namespace Drupal\lockr\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Lockr\Lockr;

use Drupal\lockr\CertWriter;
use Drupal\lockr\SettingsFactory;

/**
 * Form handler for Lockr registration.
 */
class LockrRegisterForm implements ContainerInjectionInterface, FormInterface {

  use StringTranslationTrait;

  /**
   * Lockr library client.
   *
   * @var Lockr
   */
  protected $lockr;

  /**
   * Lockr settings factory.
   *
   * @var SettingsFactory
   */
  protected $settingsFactory;

  /**
   * Simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal messenger.
   *
   * @var MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new LockrRegisterForm.
   *
   * @param Lockr $lockr
   *   The Lockr library client.
   * @param SettingsFactory $settings_factory
   *   The settings factory.
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param MessengerInterface $messenger
   *   The Drupal messenger.
   * @param TranslationInterface $translation
   *   The Drupal translator.
   */
  public function __construct(
    Lockr $lockr,
    SettingsFactory $settings_factory,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    TranslationInterface $translation
  ) {
    $this->lockr = $lockr;
    $this->settingsFactory = $settings_factory;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lockr.lockr'),
      $container->get('lockr.settings_factory'),
      $container->get('config.factory'),
      $container->get('messenger'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lockr_register_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'lockr/register';
    $form['#attached']['drupalSettings']['lockr'] = [
      'site_name' => $this->getSiteName(),
      'accounts_host' => 'https://accounts.lockr.io',
    ];

    $form['client_token'] = [
      '#type' => 'hidden',
      '#required' => TRUE,
    ];

    $form['register'] = [
      '#type' => 'button',
      '#value' => $this->t('Register Site'),
      '#attributes' => [
        'class' => ['register-site'],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => [
        'class' => ['register-submit'],
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
    $partner = $this->settingsFactory->getPartner();
    try {
      if (is_null($partner)) {
        $dn = [
          'countryName' => 'US',
          'stateOrProvinceName' => 'Washington',
          'localityName' => 'Tacoma',
          'organizationName' => 'Lockr',
        ];
        $result = $this->lockr->createCertClient($client_token, $dn);
        CertWriter::writeCerts('dev', $result);
        $config = $this->configFactory->getEditable('lockr.settings');
        $config->set('custom', TRUE);
        $config->set('cert_path', 'private://lockr/dev/pair.pem');
        $config->save();
      }
      elseif ($partner['name'] === 'pantheon') {
        $this->lockr->createPantheonClient($client_token);
      }
    }
    catch (\Exception $e) {
      // XXX: probably log and/or show message
      throw $e;
      return;
    }
    $this->messenger->addMessage($this->t("That's it! You're signed up with Lockr; your keys are now safe."));
    $form_state->setRedirect('entity.key.collection');
  }

  /**
   * Get the human readable name of the site.
   */
  protected function getSiteName() {
    return $this->configFactory->get('system.site')->get('name');
  }

}
