<?php

namespace Drupal\lockr;

use DateTime;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Helper class for managing Lockr certificates.
 */
class CertManager {

  /**
   * The simple config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Drupal file system.
   *
   * @var FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The Drupal site root.
   *
   * @var string
   */
  protected $drupalRoot;

  /**
   * Constructs a CertManager.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The simple config factory.
   * @param FileSystemInterface $file_system
   *   The Drupal file system.
   * @param string $drupal_root
   *   The Drupal site root.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    $drupal_root
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->drupalRoot = $drupal_root;
  }

  /**
   * Returns the current custom cert path.
   *
   * @return string
   */
  public function certPath() {
    $config = $this->configFactory->get('lockr.settings');
    return $this->resolveCertPath($config->get('cert_path'));
  }

  /**
   * Returns TRUE if the current cert and its directory are writable.
   *
   * @return bool
   */
  public function certWritable() {
    $cert_path = $this->certPath();
    return is_writable($cert_path) && is_writable(dirname($cert_path));
  }

  /**
   * Backs up the current cert into the private directory.
   *
   * @return bool
   *   Returns FALSE if the backup failed.
   */
  public function backupCert() {
    $cert_path = $this->certPath();
    $cert_dir = dirname($cert_path);
    $backup_name =
      basename($cert_dir) . '_backup_' . (new DateTime())->format('YmdHis');
    $backup_dir = $this->certDir($backup_name);
    if (!@mkdir($backup_dir, 0750, TRUE)) {
      return FALSE;
    }
    return $this->copyPEM($cert_dir, $backup_dir);
  }

  /**
   * Copies all .pem files from one directory to another.
   *
   * @param string $src
   *   The source directory.
   * @param string $dst
   *   The destination directory.
   * @return bool
   *   Returns FALSE if the copy failed.
   */
  public function copyPEM($src, $dst) {
    $paths = glob("{$src}/*.pem", GLOB_MARK|GLOB_NOSORT);
    if ($paths === FALSE) {
      return FALSE;
    }
    foreach ($paths as $src_path) {
      if (substr($src_path, -1) === '/') {
        continue;
      }
      $dst_path = $dst . '/' . basename($src_path);
      if (!copy($src_path, $dst_path)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the absolute path for the given cert path.
   *
   * @param string $cert_path
   *   The Lockr cert path.
   * @return string
   *   The resolved path.
   */
  public function resolveCertPath($cert_path) {
    if (strpos($cert_path, '/') === 0) {
      return $cert_path;
    }
    if (strpos($cert_path, 'private://') === 0) {
      return $this->fileSystem->realpath($cert_path);
    }
    return $this->fileSystem->realpath("{$this->drupalRoot}/$cert_path");
  }

  /**
   * Returns the absolute path to a Lockr cert directory.
   *
   * @param string $name
   *   The name of the Lockr cert.
   *
   * @return string
   *   Returns the absolute path.
   */
  public function certDir($name) {
    return $this->fileSystem->realpath("private://lockr/{$name}");
  }

  /**
   * Writes the given key and cert files to a directory.
   *
   * @param string $dir
   *   The dir to write the files to.
   * @param string $cert_text
   *   The certificate file.
   * @param string $key_text
   *   The key file.
   * @return bool
   *   Returns TRUE if the write was successful.
   */
  public function writeCerts($dir, $cert_text, $key_text) {
    if (is_dir($dir)) {
      return FALSE;
    }
    $parent = dirname($dir);
    if (!is_dir($parent) || !is_writable($parent)) {
      return FALSE;
    }

    // The temporary directory is created in the parent of our desired
    // final direcotry because rename only works on the same filesystem.
    $tmpdir = $this->tmpdir($parent);
    if ($tmpdir === FALSE) {
      return FALSE;
    }

    $key_file = "{$tmpdir}/key.pem";
    if (file_put_contents($key_file, $key_text) === FALSE) {
      return FALSE;
    }
    if (!chmod($key_file, 0640)) {
      return FALSE;
    }

    $cert_file = "{$tmpdir}/crt.pem";
    if (file_put_contents($cert_file, $cert_text) === FALSE) {
      return FALSE;
    }
    if (!chmod($cert_file, 0640)) {
      return FALSE;
    }

    $pair_file = "{$tmpdir}/pair.pem";
    if (file_put_contents($pair_file, [$key_text, $cert_text]) === FALSE) {
      return FALSE;
    }
    if (!chmod($pair_file, 0640)) {
      return FALSE;
    }

    if (!rename($tmpdir, $dir)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Creates a temporary directory for writing cert files.
   *
   * @return string|bool
   *   Absolute path to the new temporary directory.
   */
  protected function tmpdir($dir) {
    $dir = rtrim($dir, '/');
    if (!is_dir($dir) || !is_writable($dir)) {
      return FALSE;
    }
    $prefix = "{$dir}/.lockr_tmp_";
    for ($i = 0; $i < 1000; $i++) {
      $path = $prefix . (string) mt_rand(100000, mt_getrandmax());
      if (@mkdir($path, 0750)) {
        return $path;
      }
    }
    return FALSE;
  }

}
