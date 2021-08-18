<?php

namespace Drupal\lockr;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\key\KeyRepositoryInterface;
use Lockr\SecretInfoInterface;

/**
 * SecretInfo implements secret info for Lockr secrets.
 */
class SecretInfo implements SecretInfoInterface {

  /**
   * Simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Key repository.
   *
   * @var KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a new settings factory.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param KeyRepositoryInterface $key_repository
   *   The key respository.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    KeyRepositoryInterface $key_repository
  ) {
    $this->configFactory = $config_factory;
    $this->secretStorage = $entity_type_manager->getStorage('lockr_secret');
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function getSecretInfo($name) {
    /** @var \Drupal\lockr\SecretInterface */
    $secret = $this->secretStorage->load($name);
    if (!is_null($secret)) {
      return $secret->getInfo();
    }
    $config = $this->configFactory->get('lockr.secret_info');
    $info = $config->get($name);
    if (!$info) {
      $key = $this->keyRepository->getKey($name);
      if ($key) {
        $provider = $key->getKeyProvider();
        $config = $provider->getConfiguration();
        if (isset($config['encoded'])) {
          return ['wrapping_key' => $config['encoded']];
        }
      }
    }
    return $info ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSecretInfo($name, array $info) {
    $secret = $this->secretStorage->load($name);
    if (is_null($secret)) {
      $secret = $this->secretStorage->create([
        'id' => $name,
        'key_id' => $name,
      ]);
    }
    $secret->setInfo($info);
    $secret->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllSecretInfo() {
    $info = [];
    foreach ($this->secretStorage->loadMultiple(NULL) as $secret) {
      $info[$secret->id()] = $secret->getInfo();
    }
    return $info;
  }

}
