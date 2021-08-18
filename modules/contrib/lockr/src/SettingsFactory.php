<?php

namespace Drupal\lockr;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;

use Lockr\LockrSettings;

/**
 * Creates settings objects for lockr clients.
 */
class SettingsFactory {

  /**
   * Drupal simple config factory.
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
   * Drupal site settings.
   *
   * @var Settings
   */
  protected $settings;

  /**
   * Constructs a new settings factory.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param FileSystemInterface $file_system
   *   The Drupal file system interface.
   * @param Settings $settings
   *   The Drupal site settings.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    Settings $settings
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->settings = $settings;
  }

  /**
   * Creates a new Lockr settings object from Drupal settings.
   *
   * @return \Lockr\SettingsInterface
   *   The created Lockr settings object.
   */
  public function getSettings() {
    $config = $this->configFactory->get('lockr.settings');
    if ($config->get('custom')) {
      $cert_path = $this->fileSystem->realpath($config->get('cert_path'));
    }
    else {
      $partner = $this->getPartner();
      $cert_path = isset($partner['cert']) ? $partner['cert'] : NULL;
    }
    switch ($config->get('region')) {
      case 'us':
        $host = 'us.api.lockr.io';
        break;
      case 'eu':
        $host = 'eu.api.lockr.io';
        break;
      default:
        $host = 'api.lockr.io';
        break;
    }
    $client_config = $this->settings->get('lockr_http_client_config');
    if (is_array($client_config)) {
      $opts = $client_config;
    }
    else {
      $opts = [];
    }
    return new LockrSettings($cert_path, $host, null, $opts);
  }

  /**
   * Gets the detected Lockr partner information.
   *
   * @return array|null
   *   The partner information, or NULL if no partner is detected.
   */
  public function getPartner() {
    if (isset($_ENV['PANTHEON_ENVIRONMENT'])) {
      $cert_path = '/certs/binding.pem';
      if (!is_file($cert_path) && defined('PANTHEON_BINDING')) {
        $cert_path = '/srv/bindings/' . PANTHEON_BINDING . '/certs/binding.pem';
      }
      return [
        'name' => 'pantheon',
        'title' => 'Pantheon',
        'description' => "The Pantheor is strong with this one.
          We're detecting you're on Pantheon and a friend of theirs is a friend of ours.
          Welcome to Lockr.",
        'cert' => $cert_path,
      ];
    }

    return NULL;
  }

}
