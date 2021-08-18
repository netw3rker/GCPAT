<?php

namespace Drupal\lockr\Form;

use DateTime;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

use Lockr\Exception\LockrApiException;
use Lockr\LockrClient;
use Lockr\LockrSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\lockr\CertWriter;
use Drupal\lockr\CertManager;

/**
 * Form handler for Lockr renew cert.
 */
class LockrRenewForm implements ContainerInjectionInterface, FormInterface {

  use StringTranslationTrait;

  /**
   * Lockr library client.
   *
   * @var LockrClient
   */
  protected $lockrClient;

  /**
   * Cert manager.
   *
   * @var CertManager
   */
  protected $certManager;

  /**
   * Drupal stream wrapper manager.
   *
   * @var StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Drupal messenger.
   *
   * @var MessengerInterface
   */
  protected $messenger;

  /**
   * Drupal logger.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * Drupal site settings.
   *
   * @var Settings
   */
  protected $settings;

  /**
   * Constructs a new LockrRenewForm.
   *
   * @param LockrClient $lockr_client
   *   The Lockr library client.
   * @param CertManager $cert_manager
   *   The Lockr cert manager.
   * @param TranslationInterface $translation
   *   The Drupal translator.
   * @param StreamWrapperManagerInterface $stream_wrapper_manager
   *   The Drupal stream wrapper manager.
   * @param MessengerInterface $messenger
   *   The Drupal messenger.
   * @param LoggerChannelFactoryInterface $logger_factory
   *   The Drupal logger factory.
   * @param Settings $settings
   *   The Drupal site settings.
   */
  public function __construct(
    LockrClient $lockr_client,
    CertManager $cert_manager,
    TranslationInterface $translation,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    Settings $settings
  ) {
    $this->lockrClient = $lockr_client;
    $this->certManager = $cert_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('lockr');
    $this->settings = $settings;
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lockr.client'),
      $container->get('lockr.cert_manager'),
      $container->get('string_translation'),
      $container->get('stream_wrapper_manager'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('settings')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lockr_renew_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (!$this->privateValid()) {
      // Private path not set up.
      return $form;
    }

    $form['renew_certs'] = [
      '#prefix' => '<p>',
      '#markup' => $this->t(
        'Click "Renew Certificate" button below to provision a new connection certificate from Lockr.
         This will be a drop-in replacement for the current certificate, which will have
         access to all of the same secrets. During this process, a backup of the existing certificate
         will be created for recovery purposes.'
      ),
      '#suffix' => '</p>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Renew Certificate'),
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
    // 1. Create a new private key and CSR.
    $texts = $this->createCSR();
    if (is_null($texts)) {
      $this->messenger->addError($this->t(
        'Failed to create a CSR. This could be because of an invalid
         OpenSSL installation.'
      ));
      return;
    }

    // 2. Grab the current environment.
    //    This has the side effect of verifying our current cert is valid.
    try {
      $env = $this->getEnv();
    }
    catch (LockrApiException $e) {
      $this->handleException($e);
      $this->messenger->addError($this->t(
        'An error occurred verifying the current Lockr client.
         Please try again or contact Lockr support.'
      ));
      return;
    }

    // 3. Request a new cert from Lockr.
    try {
      $cert_text = $this->renewCert($texts['csr_text']);
    }
    catch (LockrApiException $e) {
      $this->handleException($e);
      $this->messenger->addError($this->t(
        'An error occurred renewing the current Lockr certificate.
         Please try again or contact Lockr support.'
      ));
      return;
    }

    // 4. Write the new cert and private key to a new private directory.
    $dir_name = $env . '_' . (new DateTime())->format('YmdHis');
    $dir = $this->certManager->certDir($dir_name);
    $key_text = $texts['key_text'];
    if (!$this->certManager->writeCerts($dir, $cert_text, $key_text)) {
      $this->messenger->addError($this->t('Failed to write certificates.'));
      return;
    }

    // 5. Verify the new cert.
    try {
      $this->getRenewedEnv($dir);
    }
    catch (LockrApiException $e) {
      $this->handleException($e);
      $this->messenger->addError($this->t(
        'An error occurred verifying the new Lockr certificate.
         It has been saved at @certpath.
         The original certificate is still being used.
         Please try again or contact Lockr support.',
        ['@certpath' => $full_dir]
      ));
      return;
    }

    // 6. If we cannot write to the current cert location, bail out.
    if (!$this->certManager->certWritable()) {
      $this->messenger->addError($this->t(
        'The destination cert path is not writable.
         New certs have been saved at @certpath.
         The original certificate is still being used.
         Please try again or contact Lockr support.',
        ['@certpath' => $full_dir]
      ));
      return;
    }

    // 7. Make a backup of the current certificate.
    if (!$this->certManager->backupCert()) {
      $this->messenger->addError($this->t(
        'An error occurred while attempting to backup the current cert.
         In an abundance of caution, it has not been overwritten.'
      ));
      return;
    }

    // 8. Copy new cert into the current location.
    $cert_path = $this->certManager->certPath();
    $current_dir = dirname($cert_path);
    if (!$this->certManager->copyPEM($dir, $current_dir)) {
      $this->messenger->addError($this->t(
        'An error occurred while attempting to place the new cert.
         Please try again or contact Lockr support.'
      ));
    } else {
      $this->messenger->addMessage($this->t(
        'Your certificate has been successfully renewed. A backup of
         the previous certificate has been created for recovery purposes.
         Contact Lockr support if you have any questions.'
      ));
    }
  }

  /**
   * Creates a new RSA private key and CSR pair.
   */
  protected function createCSR() {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    if ($key === FALSE) {
      return NULL;
    }
    if (!openssl_pkey_export($key, $key_text)) {
      return NULL;
    }
    $dn = [
      'countryName' => 'US',
      'stateOrProvinceName' => 'Washington',
      'localityName' => 'Tacoma',
      'organizationName' => 'Lockr',
    ];
    $csr = openssl_csr_new($dn, $key);
    if ($csr === FALSE) {
      return NULL;
    }
    if (!openssl_csr_export($csr, $csr_text)) {
      return NULL;
    }
    return [
      'key_text' => $key_text,
      'csr_text' => $csr_text,
    ];
  }

  /**
   * Returns the environment of the current client.
   */
  protected function getEnv() {
    $data = $this->lockrClient->query([
      'query' => '{ self { env } }',
    ]);
    return $data['self']['env'] ?? 'unknown';
  }

  /**
   * Renews the current client.
   *
   * @param string $csr_text
   *   The CSR to sign.
   */
  protected function renewCert($csr_text) {
    $query = <<<'EOQ'
mutation Renew($input: RenewCertClient!) {
  renewCertClient(input: $input) {
    auth {
      ... on LockrCert {
        certText
      }
    }
  }
}
EOQ;
    $data = $this->lockrClient->query([
      'query' => $query,
      'variables' => [
        'input' => [
          'csrText' => $csr_text,
        ],
      ],
    ]);
    return $data['renewCertClient']['auth']['certText'];
  }

  /**
   * Gets the env of the renewed cert.
   *
   * @param string $dir
   *   The directory of the new certs.
   *
   * @return string
   */
  protected function getRenewedEnv($dir) {
    $cert_path = "{$dir}/pair.pem";
    $client_config = $this->settings->get('lockr_http_client_config');
    if (is_array($client_config)) {
      $opts = $client_config;
    }
    else {
      $opts = [];
    }
    $lockr_settings = new LockrSettings($cert_path, null, null, $opts);
    $client = LockrClient::createFromSettings($lockr_settings);
    $data = $client->query(['query' => '{ self { env } }']);
    return $data['self']['env'] ?? 'unknown';
  }

  /**
   * Logs details about the given exception.
   *
   * @param LockrApiException $e
   *   The Lockr exception.
   */
  protected function handleException(LockrApiException $e) {
    $this->logger->error(
      'Lockr error occurred [{exc_code}]: {exc_msg}',
      [
        'exc_code' => $e->getCode(),
        'exc_msg' => $e->getMessage(),
      ]
    );
  }

  /**
   * Returns TRUE if the private stream is available.
   */
  protected function privateValid() {
    return $this->streamWrapperManager->isValidScheme('private');
  }

}
