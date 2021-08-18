<?php
namespace Drupal\lockr;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Secrets represent metadata about secrets stored in Lockr.
 */
interface SecretInterface extends ConfigEntityInterface {

  /**
   * Gets the ID of the key associated with this secret.
   */
  public function getKeyId();

  /**
   * Sets the key ID for this secret.
   */
  public function setKeyId($key_id);

  /**
   * Gets the info for this secret.
   */
  public function getInfo();

  /**
   * Sets the info for this secret.
   */
  public function setInfo(array $info);

}
