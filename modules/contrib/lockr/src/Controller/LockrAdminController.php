<?php

namespace Drupal\lockr\Controller;

use DateTime;
use DateTimeImmutable;
use DateInterval;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

use Lockr\Exception\LockrApiException;
use Lockr\Lockr;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\lockr\SettingsFactory;
use Drupal\lockr\Form\LockrAdvancedForm;
use Drupal\lockr\Form\LockrMigrateForm;
use Drupal\lockr\Form\LockrRegisterForm;
use Drupal\lockr\Form\LockrRenewForm;

/**
 * Controller for the Lockr admin status and configuration page.
 */
class LockrAdminController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Lockr library client.
   *
   * @var Lockr
   */
  protected $lockr;

  /**
   * Generates Lockr settings.
   *
   * @var SettingsFactory
   */
  protected $settingsFactory;

  /**
   * Drupal simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal form builder.
   *
   * @var FormBuilderInterface
   */
  protected $formBuilder;

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
   * Root directory of the Drupal installation.
   *
   * @var string
   */
  protected $drupalRoot;

  /**
   * Constructs a new LockrAdminForm.
   *
   * @param Lockr $lockr
   *   The Lockr library client.
   * @param SettingsFactory $settings_factory
   *   The Lockr settings factory.
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param FormBuilderInterface $form_builder
   *   The Drupal form builder.
   * @param StreamWrapperManagerInterface
   *   The Drupal stream wrapper manager.
   * @param MessengerInterface $messenger
   *   The Drupal messenger.
   * @param TranslationInterface $translation
   *   The Drupal translator.
   * @param string $drupal_root
   *   The Drupal site root.
   */
  public function __construct(
    Lockr $lockr,
    SettingsFactory $settings_factory,
    ConfigFactoryInterface $config_factory,
    FormBuilderInterface $form_builder,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    MessengerInterface $messenger,
    TranslationInterface $translation,
    $drupal_root
  ) {
    $this->lockr = $lockr;
    $this->settingsFactory = $settings_factory;
    $this->configFactory = $config_factory;
    $this->formBuilder = $form_builder;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->messenger = $messenger;
    $this->drupalRoot = $drupal_root;
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
      $container->get('form_builder'),
      $container->get('stream_wrapper_manager'),
      $container->get('messenger'),
      $container->get('string_translation'),
      $container->get('app.root')
    );
  }

  /**
   * Renders the Lockr status page.
   */
  public function overview() {
    try {
      $info = $this->lockr->getInfo();
    }
    catch (LockrApiException $e) {
      if ($e->getCode() >= 500) {
        watchdog_exception('lockr', $e);
        $this->messenger->addMessage($this->t('The Lockr service has returned an error. Please try again.'), 'error');
        return [];
      }
      $info = [];
    }

    $text_config = $this->configFactory->get('lockr.ui_text');

    $ra['header'] = [
      '#prefix' => '<p>',
      '#markup' => $info
        ? $text_config->get('admin_page.header.registered')
        : $text_config->get('admin_page.header.not_registered'),
      '#suffix' => '</p>',
    ];

    $ra['status'] = $this->getStatus($info);

    $partner = $this->settingsFactory->getPartner();
    if ($partner) {
      $ra['description'] = [
        '#prefix' => '<p>',
        '#markup' => $partner['description'],
        '#suffix' => '</p>',
      ];
    }
    elseif ($info && $info['env'] === 'dev') {
      $ra['migrate'] = $this->formBuilder->getForm(LockrMigrateForm::class, $info);
    }

    // If register is going to attempt writing certs to the private
    // directory (when there is no partner), then only allow registration
    // if the private directory is set.
    if (!$info && ($partner || $this->privateValid())) {
      $ra['register'] = $this->formBuilder->getForm(LockrRegisterForm::class);
    }

    $auth_info = $this->extractExpires($info);
    $sev = $this->shouldRenew($auth_info);
    if (
        $sev !== REQUIREMENT_OK ||
        ($_GET['lockr_force_renew'] ?? '') !== ''
    ) {
      $ra['renew_cert'] = $this->formBuilder->getForm(LockrRenewForm::class);
    }

    $ra['advanced'] = $this->formBuilder->getForm(LockrAdvancedForm::class);

    return $ra;
  }

  /**
   * Renders the Lockr status table.
   */
  public function getStatus(array $info) {
    require_once "{$this->drupalRoot}/core/includes/install.inc";

    $text_config = $this->configFactory->get('lockr.ui_text');

    $reqs = [];

    if ($info) {
      $reqs[] = [
        'title' => $this->t('Certificate Valid'),
        'value' => $this->t('Yes'),
        'description' => $text_config->get('admin_page.status.registered'),
        'severity' => REQUIREMENT_OK,
      ];
      $reqs[] = [
        'title' => $this->t('Environment'),
        'value' => ucfirst($info['env']),
        'severity' => REQUIREMENT_INFO,
      ];
    }
    else {
      $private = $this->streamWrapperManager->getViaScheme('private');
      $value = $this->t('Unknown');
      if ($private) {
        $realpath = $private->realpath();
        if ($realpath) {
          $value = $realpath;
        }
      }
      $reqs[] = [
        'title' => $this->t('Private Directory'),
        'value' => $realpath,
        'description' => $private
          ? $text_config->get('admin_page.status.path.exists')
          : $text_config->get('admin_page.status.path.invalid'),
        'severity' => $private ? REQUIREMENT_OK : REQUIREMENT_ERROR,
      ];
      $reqs[] = [
        'title' => $this->t('Certificate Valid'),
        'value' => $this->t('No'),
        'description' => $text_config->get('admin_page.status.not_registered'),
      ];
    }

    if ($info) {
      $reqs[] = [
        'title' => $this->t('Connected KeyRing'),
        'value' => $this->t('Yes'),
        'description' => $this->t("You are currently connected to the @label KeyRing.", ['@label' => $info['keyring']['label']]),
        'severity' => REQUIREMENT_OK,
      ];

      $has_cc = $info['keyring']['hasCreditCard'];

      if (isset($info['keyring']['trialEnd'])) {
        $trial_end = DateTime::createFromFormat(DateTime::RFC3339, $info['keyring']['trialEnd']);
        if ($trial_end > (new DateTime())) {
          $reqs[] = [
            'title' => $this->t('Trial Expiration Date'),
            'value' => $trial_end->format('M jS, Y'),
            'severity' => REQUIREMENT_INFO,
          ];
        }
        elseif (!$has_cc) {
          $reqs[] = [
            'title' => $this->t('Trial Expiration Date'),
            'value' => $trial_end->format('M jS, Y'),
            'severity' => REQUIREMENT_ERROR,
          ];
        }
      }
      $reqs[] = [
        'title' => $this->t('Credit Card on File'),
        'value' => $has_cc ? 'Yes' : 'No',
        'description' => $has_cc
          ? $text_config->get('admin_page.status.cc.has')
          : $text_config->get('admin_page.status.cc.missing.required'),
        'severity' => $has_cc ? REQUIREMENT_OK : REQUIREMENT_ERROR,
      ];
      $auth_info = $this->extractExpires($info);
      $sev = $this->shouldRenew($auth_info);
      if ($sev !== REQUIREMENT_OK) {
        $reqs[] = [
          'title' => $this->t('Certificate Expiration'),
          'value' => $auth_info['expires']->format('M jS, Y'),
          'description' => $this->t('Your certificate is close to expiration.'),
          'severity' => $sev,
        ];
      }
    }

    lockr_preprocess_status_report($reqs);

    return ['#type' => 'status_report', '#requirements' => $reqs];
  }

  /**
   * Returns TRUE if the private stream is available.
   */
  protected function privateValid() {
    return $this->streamWrapperManager->isValidScheme('private');
  }

  /**
   * Returns a two element array of type and expires.
   */
  protected function extractExpires(array $info) {
    $type = $info['auth']['__typename'] ?? '';
    $expires = NULL;
    switch ($type) {
    case 'LockrCert':
      if (isset($info['auth']['expires'])) {
        $expires_text = $info['auth']['expires'];
        try {
          $expires = DateTime::createFromFormat(DateTime::RFC3339, $expires_text) ?: NULL;
        }
        catch (\Exception $e) {
        }
      }
    }
    return ['type' => $type, 'expires' => $expires];
  }

  /**
   * Returns how urgent renewal is.
   */
  protected function shouldRenew(array $auth_info) {
    switch ($auth_info['type']) {
    case 'LockrCert':
      if (is_null($auth_info['expires'])) {
        // Not sure what the default here should be.
        return REQUIREMENT_OK;
      }
      $now = new DateTimeImmutable();
      $err = new DateInterval('P1W');
      if ($now->add($err) > $auth_info['expires']) {
        return REQUIREMENT_ERROR;
      }
      $warn = new DateInterval('P1M');
      if ($now->add($warn) > $auth_info['expires']) {
        return REQUIREMENT_WARNING;
      }
      return REQUIREMENT_OK;
    case 'LegacyCert':
      // Legacy certs should always be renewed.
      return REQUIREMENT_ERROR;
    default:
      return REQUIREMENT_OK;
    }
  }

}

/**
 * Copied from previous version of core.
 *
 * Ensures that the status table requirements are properly formatted.
 */
function lockr_preprocess_status_report(&$reqs) {
  $severities = [
    REQUIREMENT_INFO => [
      'title' => t('Info'),
      'status' => 'info',
    ],
    REQUIREMENT_OK => [
      'title' => t('OK'),
      'status' => 'ok',
    ],
    REQUIREMENT_WARNING => [
      'title' => t('Warning'),
      'status' => 'warning',
    ],
    REQUIREMENT_ERROR => [
      'title' => t('Error'),
      'status' => 'error',
    ],
  ];
  foreach ($reqs as $i => $requirement) {
    // Always use the explicit requirement severity, if defined. Otherwise,
    // default to REQUIREMENT_OK in the installer to visually confirm that
    // installation requirements are met. And default to REQUIREMENT_INFO to
    // denote neutral information without special visualization.
    if (isset($requirement['severity'])) {
      $severity = $severities[(int) $requirement['severity']];
    }
    elseif (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === 'install') {
      $severity = $severities[REQUIREMENT_OK];
    }
    else {
      $severity = $severities[REQUIREMENT_INFO];
    }
    $reqs[$i]['severity_title'] = $severity['title'];
    $reqs[$i]['severity_status'] = $severity['status'];
  }
}
