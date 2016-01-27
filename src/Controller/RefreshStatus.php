<?php

/**
 * @file
 * Contains \Drupal\acquia_search_multi_subs\Controller\RefreshStatus.
 */

namespace Drupal\acquia_search_multi_subs\Controller;
use Drupal\acquia_connector\Subscription;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller routines to refresh the subscription status.
 */
class RefreshStatus {
  public function refresh() {
    // Code lifted from acquia_connector.

    $config = \Drupal::config('acquia_connector.settings');
    // Don't send data if site is blocked or missing components.
    if ($config->get('spi.blocked') || (is_null($config->get('spi.site_name')) && is_null($config->get('spi.site_machine_name')))) {
      return;
    }
    Subscription::update();

    // Return to the settings page for acquia_connector, or to the destination if it was set.
    $destination = \Drupal::destination()->getAsArray();
    if (isset($destination['destination'])) {
      return new RedirectResponse(Url::fromUri($destination['destination']));
    }
    else {
      return $this->redirect('acquia_connector.settings');
    }
  }
}
