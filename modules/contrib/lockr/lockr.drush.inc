<?php

/**
 * @file
 * Hooks and callbacks for drush.
 */

use Lockr\Exception\LockrClientException;
use Lockr\Exception\LockrServerException;

/**
 * Implements hook_drush_command().
 */
function lockr_drush_command() {
  $info['lockr-register'] = [
    'description' => 'Register a site for Lockr.',
    'aliases' => ['lr'],
    'core' => ['8'],
    'scope' => 'site',
    'arguments' => [
      'email' => 'The email to register with.',
    ],
    'options' => [
      'password' => 'The password to match given email (if applicable).',
    ],
  ];

  return $info;
}

/**
 * Registers the site with lockr.
 */
function drush_lockr_register($email) {
  $name = variable_get('site_name');
  $client = \Drupal::service('lockr.client');
  $password = drush_get_option('password');

  try {
    $client->register($email, $password, $name);
  }
  catch (LockrClientException $e) {
    if (
      $e->title === 'Missing header value' &&
      $e->description === 'The Auth header is required.'
    ) {
      $msg = 'Email is already registered, please provide a password.';
    }
    elseif ($e->title === 'Partner mismatch') {
      $msg = "We didn't recognize your certificate, please ensure the provide path is a valid Lockr certificate.";
    }
    elseif ($e->title === 'Site exists') {
      $msg = 'This site is already registered. If you are experiencing issues, please contact support@lockr.io.';
    }
    elseif ($e->title === 'Credentials invalid') {
      $msg = 'The username and password did not match, please try again.';
    }
    else {
      $msg = 'An unknown error occurred, please try again later.';
    }

    \Drupal::logger($msg, 'error');
  }
  catch (LockrServerException $e) {
    \Drupal::logger('An unknown error occurred, please try again.', 'error');
  }
}
