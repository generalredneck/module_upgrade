<?php

namespace Drupal\module_upgrade\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use \Drupal\node\Entity\Node;
use Drupal\update\UpdateProcessorInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A queue worker that grabs and processes drupal project release data.
 *
 * @QueueWorker(
 *   id = "module_upgrade_project_releases",
 *   title = @Translation("Cron Node Publisher"),
 *   cron = {"time" = 120}
 * )
 */
class ProjectReleaseWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {


  public function __construct(
    EntityTypeManager $entity_type_manager,
    QueryFactory $entity_query,
    ClientInterface $http_client,
    LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityQuery = $entity_query;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.query'),
      $container->get('http_client'),
      $container->get('logger.factory')->get('module_upgrade')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $term_storage->load($item->tid);
    if (!$term) {
      $this->logger->error(t('Project with tid %tid does not exist', ['%tid' => $item->tid]));
      return;
    }
    $url = 'http://updates.drupal.org/release-history' . '/' . $term->field_project_machine_name->value . '/' . $term->field_drupal_core_version->entity->name->value;
    try {
      $data = (string) $this->httpClient
        ->get($url, array('headers' => array('Accept' => 'text/xml')))
        ->getBody();
    }
    catch (RequestException $exception) {
      $this->logger->error($exception->getMessage());
      return;
    }
    if (empty($data)) {
      $this->logger->error(t('No such project %project for Drupal core %core', ['%project' => $term->field_project_machine_name->value, '%core' => $term->field_drupal_core_version->entity->name->value]));
      return;
    }
    $project = $this->parseXml($data);
    if (empty($project)) {
      $this->logger->error(t('The data retrieved from %url is invalid project data.', ['%url' => $url]));
      return;
    }

    $tids = $this->entityQuery->get('taxonomy_term')
      ->condition('vid', 'release_type')
      ->execute();
    $release_types = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    $release_type_map = [];
    foreach ($release_types as $type) {
      $release_type_map[$type->name->value] = $type->id();
    }

    foreach ($project['releases'] as $version => $release) {
      $release_version = str_replace($term->field_drupal_core_version->entity->name->value . '-', '', $version);
      $existing_release_ids = $this->entityQuery->get('node')
        ->condition('type', 'project_update_record')
        ->condition('field_project.target_id', $term->id())
        ->condition('field_project_version', $release_version)
        ->execute();
      var_dump($existing_release_ids);
      $release_node = NULL;
      if (!empty($existing_release_ids)) {
        $release_node = $this->entityTypeManager->getStorage('node')->load(reset($existing_release_ids));
      }
      if (empty($release_node)) {
        $release_node = Node::create([
          'type' => 'project_update_record',
          'field_project' => $term->id(),
          'field_project_version' => $release_version,
        ]);
      }
      var_dump($release_node->id());
      $release_node->title = $term->field_project_machine_name->value . ' ' . $version;
      $release_node->field_release_type = [];
      if (!empty($release['terms']['Release type'])) {
        foreach ($release['terms']['Release type'] as $release_type_string) {
          var_dump($release_type_string);
          var_dump($release_type_map);
          $release_node->field_release_type[] = $release_type_map[$release_type_string];
        }
      }
      $release_node->save();
    }
  }

  protected function parseXml($raw_xml) {
    try {
      $xml = new \SimpleXMLElement($raw_xml);
    }
    catch (\Exception $e) {
      // SimpleXMLElement::__construct produces an E_WARNING error message for
      // each error found in the XML data and throws an exception if errors
      // were detected. Catch any exception and return failure (NULL).
      return NULL;
    }
    // If there is no valid project data, the XML is invalid, so return failure.
    if (!isset($xml->short_name)) {
      return NULL;
    }
    $data = array();
    foreach ($xml as $k => $v) {
      $data[$k] = (string) $v;
    }
    $data['releases'] = array();
    if (isset($xml->releases)) {
      foreach ($xml->releases->children() as $release) {
        $version = (string) $release->version;
        $data['releases'][$version] = array();
        foreach ($release->children() as $k => $v) {
          $data['releases'][$version][$k] = (string) $v;
        }
        $data['releases'][$version]['terms'] = array();
        if ($release->terms) {
          foreach ($release->terms->children() as $term) {
            if (!isset($data['releases'][$version]['terms'][(string) $term->name])) {
              $data['releases'][$version]['terms'][(string) $term->name] = array();
            }
            $data['releases'][$version]['terms'][(string) $term->name][] = (string) $term->value;
          }
        }
      }
    }
    return $data;
  }

}
