<?php
namespace Drupal\lockr\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

use Drupal\lockr\SecretInterface;

/**
 * Defines the Secret entity.
 *
 * @ConfigEntityType(
 *   id = "lockr_secret",
 *   label = @Translation("Lockr Secret"),
 *   entity_keys = {
 *     "id" = "id"
 *   },
 *   lookup_keys = {
 *     "id",
 *     "key_id"
 *   },
 *   config_export = {
 *     "id",
 *     "key_id",
 *     "info"
 *   }
 * )
 */
class Secret extends ConfigEntityBase implements SecretInterface {

  /**
   * Entity ID.
   *
   * @var string
   */
  protected $id;

  /**
   * Referenced Key ID.
   *
   * @var string
   */
  protected $keyId;

  /**
   * Lockr secret info.
   *
   * @var array
   */
  protected $info = [];

  /**
   * {@inheritdoc}
   */
  public function getKeyId() {
    return $this->keyId;
  }

  /**
   * {@inheritdoc}
   */
  public function setKeyId($key_id) {
    $this->keyId = $key_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return $this->info;
  }

  /**
   * {@inheritdoc}
   */
  public function setInfo(array $info) {
    $this->info = $info;
  }

}
