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

    // Define the override form.
    $form['acquia_override_subscription'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Configure Acquia Search'),
      '#description' => $this->t('This is usually not necessary unless you really
        want this search environment to connect to a different Acquia search subscription.
        By default it uses your subscription that was configured for the
        <a href="@url">Acquia Connector</a>.', array('@url' => Url::fromRoute('acquia_connector.settings')->toString())),
      '#collapsed' => FALSE,
      '#collapsible' => TRUE,
      '#tree' => TRUE,
      '#weight' => -10,
      '#element_validate' => array('acquia_search_multi_subs_form_validate'),
    );

    // Add a checkbox to auto switch per environment.
    $form['acquia_override_subscription']['acquia_override_auto_switch'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically switch when an Acquia Environment is detected'),
      '#description' => $this->t('Based on the detection of the AH_SITE_NAME and
        AH_SITE_ENVIRONMENT header we can detect which environment you are currently
        using and switch the Acquia Search Core automatically if there is a corresponding core.
        Make sure to <a href="@url">update your locally cached subscription information</a> if your core does not show up.',
        array('@url' => Url::fromRoute('acquia_connector.refresh_status')->toString())),
      '#default_value' => $this->configuration['acquia_override_auto_switch'],
    );

    $options = array('default' => t('Default'), 'other' => t('Other'));

    $subscription = \Drupal::config('acquia_connector.settings')->get('subscription_data');
    $search_cores = $subscription['heartbeat_data']['search_cores'];

    $failover_exists = NULL;
    $failover_region = NULL;
    if (is_array($search_cores)) {
      foreach ($search_cores as $search_core) {
        $options[$search_core['core_id']] = $search_core['core_id'];
        if (strstr($search_core['core_id'], '.failover')) {
          $failover_exists = TRUE;
          $matches = array();
          preg_match("/^([^-]*)/", $search_core['balancer'], $matches);
          $failover_region = reset($matches);
        }
      }
    }
    $form['acquia_override_subscription']['acquia_override_selector'] = array(
      '#type' => 'select',
      '#title' => t('Acquia Search Core'),
      '#options' => $options,
      '#default_value' => $this->configuration['acquia_override_selector'],
      '#description' => t('Choose a search core to connect to.'),
      '#states' => array(
        'visible' => array(
          ':input[name*="acquia_override_auto_switch"]' => array('checked' => FALSE),
        ),
      ),
    );

    // Show a warning if there are not enough cores available to make the auto
    // switch possible.
    if (count($options) <= 2) {
      $t_args = array('!refresh' => l(t('refresh'), 'admin/config/system/acquia-search-multi-subs/refresh-status', array('query' => array( 'destination' => current_path()))));
      drupal_set_message(t('It seems you only have 1 Acquia Search index. To find out if you are eligible for a search core per environment it is recommended you open a support ticket with Acquia. Once you have that settled, !refresh your subscription so it pulls in the latest information to connect to your indexes.', $t_args), 'warning', FALSE);
    }

    // Generate the custom form.
    $form['acquia_override_subscription']['acquia_override_subscription_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Enter your Acquia Subscription Identifier'),
      '#description' => t('Prefilled with the identifier of the Acquia Connector. You can find your details in Acquia Insight.'),
      '#default_value' => $this->configuration['acquia_override_subscription_id'],
      '#states' => array(
        'visible' => array(
          ':input[name*="acquia_override_selector"]' => array('value' => 'other'),
          ':input[name*="acquia_override_auto_switch"]' => array('checked' => FALSE),
        ),
      ),
    );

    $form['acquia_override_subscription']['acquia_override_subscription_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Enter your Acquia Subscription key'),
      '#description' => t('Prefilled with the key of the Acquia Connector. You can find your details in Acquia Insight.'),
      '#default_value' => $this->configuration['acquia_override_subscription_key'],
      '#states' => array(
        'visible' => array(
          ':input[name*="acquia_override_selector"]' => array('value' => 'other'),
          ':input[name*="acquia_override_auto_switch"]' => array('checked' => FALSE),
        ),
      ),
    );

    $form['acquia_override_subscription']['acquia_override_subscription_corename'] = array(
      '#type' => 'textfield',
      '#description' => t('Please enter the name of the Acquia Search core you want to connect to that belongs to the above identifier and key. In most cases you would want to use the dropdown list to get the correct value.'),
      '#title' => t('Enter your Acquia Search Core Name'),
      '#default_value' => $this->configuration['acquia_override_subscription_corename'],
      '#states' => array(
        'visible' => array(
          ':input[name*="acquia_override_selector"]' => array('value' => 'other'),
          ':input[name*="acquia_override_auto_switch"]' => array('checked' => FALSE),
        ),
      ),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $identifier = $values['acquia_override_subscription']['acquia_override_subscription_id'];
    $key = $values['acquia_override_subscription']['acquia_override_subscription_key'];
    $corename = $values['acquia_override_subscription']['acquia_override_subscription_corename'];

      // Set our solr path
    $this->options['path'] = '/solr/' . $corename;

      // Set the derived key for this environment.
      // Subscription already cached by configurationFormValidate().
      $subscription = $this->getAcquiaSubscription($identifier, $key);
      $derived_key_salt = $subscription['derived_key_salt'];
      $derived_key = _acquia_search_multi_subs_create_derived_key($derived_key_salt, $corename, $key);
      $this->options['derived_key'] = $derived_key;

      $search_host = acquia_search_multi_subs_get_hostname($corename);
      $this->options['host'] = $search_host;

    parent::submitConfigurationForm($form, $form_state);
    // Since the form is nested into another, we can't simply use #parents for
    // doing this array restructuring magic. (At least not without creating an
    // unnecessary dependency on internal implementation.)
    $values += $values['http'];
    $values += $values['advanced'];
    $values += !empty($values['autocomplete']) ? $values['autocomplete'] : array();
    unset($values['http'], $values['advanced'], $values['autocomplete']);

    // Highlighting retrieved data only makes sense when we retrieve data.
    $values['highlight_data'] &= $values['retrieve_data'];

    // For password fields, there is no default value, they're empty by default.
    // Therefore we ignore empty submissions if the user didn't change either.
    if ($values['http_pass'] === ''
      && isset($this->configuration['http_user'])
      && $values['http_user'] === $this->configuration['http_user']) {
      $values['http_pass'] = $this->configuration['http_pass'];
    }

    foreach ($values as $key => $value) {
      $form_state->setValue($key, $value);
    }

    parent::submitConfigurationForm($form, $form_state);
  }
}
