<?php

namespace Drupal\acquia_search_multi_subs\Plugin\search_api\backend;

use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\acquia_search\EventSubscriber\SearchSubscriber;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\acquia_connector\Client;


/**
 * @SearchApiBackend(
 *   id = "search_api_solr_acquia_multi_subs",
 *   label = @Translation("Acquia Solr Multi Sub"),
 *   description = @Translation("Index items using a specific Acquia Apache Solr search server.")
 * )
 */
class SearchApiSolrAcquiaMultiSubsBackend extends SearchApiSolrBackend {

  protected $eventDispatcher = FALSE;
  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ModuleHandlerInterface $module_handler, Config $search_api_solr_settings, LanguageManagerInterface $language_manager) {
    if ($configuration['scheme'] == 'https') {
      $configuration['port'] = 443;
    }
    else {
      $configuration['port'] = 80;
    }
    $configuration['host'] = acquia_search_get_search_host();
    $configuration['path'] = '/solr/' . \Drupal::config('acquia_connector.settings')->get('identifier');


    $subscription = \Drupal::config('acquia_connector.settings')->get('subscription_data');
    $core_id = $subscription['heartbeat_data']['search_cores'][0]['core_id'];
    $configuration['path'] = '/solr/' . $core_id;
    return parent::__construct($configuration, $plugin_id, $plugin_definition, $module_handler, $search_api_solr_settings, $language_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('config.factory')->get('search_api_solr.settings'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = array();

    $options = $this->options;

    $auto_detection = (isset($this->configuration['acquia_override_auto_switch']) && $this->configuration['acquia_override_auto_switch']);
    $auto_detection_state = ($auto_detection) ? $this->t('enabled') : $this->t('disabled');

    $info[] = array(
      'label' => $this->t('Acquia Search Auto Detection'),
      'info' => $this->t('Auto detection of your environment is <strong>@state</strong>', array('@state' => $auto_detection_state)),
    );

    return parent::viewSettings();
  }

  /**
   * Creates a connection to the Solr server as configured in $this->configuration.
   */
  protected function connect() {
    parent::connect();
    if (!$this->eventDispatcher) {
      $this->eventDispatcher = $this->solr->getEventDispatcher();
      $plugin = new SearchSubscriber();
      $this->solr->registerPlugin('acquia_solr_search_subscriber', $plugin);
      // Don't use curl.
      $this->solr->setAdapter('Solarium\Core\Client\Adapter\Http');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['host']['#disabled'] = TRUE;
    $form['port']['#disabled'] = TRUE;
    $form['path']['#disabled'] = TRUE;

    return $form;
  }

}
