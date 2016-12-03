<?php

namespace Drupal\module_upgrade\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SiteProjectsController.
 *
 * @package Drupal\module_upgrade\Controller
 */
class SiteProjectsController extends ControllerBase {

  /**
   * The entity.query service.
   *
   * @var Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The entity_type.manager service.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The database service.
   *
   * @var Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    QueryFactory $entity_query,
    EntityTypeManager $entity_type_manager,
    Connection $database
    ) {
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Hello.
   *
   * @return string
   *   Return Hello string.
   */
  public function listProjects($site_uuid) {
    // TODO: Check for access to site node.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_view_builder = $this->entityTypeManager->getViewBuilder('node');

    $project_install_ids = $this->entityQuery->get('node')
      ->condition('type', 'project_installed_record')
      ->condition('field_site.entity.uuid', $site_uuid)
      ->execute();
    $project_install_nodes = $node_storage->loadMultiple($project_install_ids);
    // Now that we have the installed projects, grab the project releases
    // that are newer than current release. Later add logic for recommended
    // releases and what not.
    $output = [];
    foreach ($project_install_nodes as $node) {
      $update_ids = $this->entityQuery->get('node')
        ->condition('type', 'project_update_record')
        ->condition('field_project_version_major', $node->field_project_version_major->value, '>=')
        ->condition('field_project_version_patch', $node->field_project_version_patch->value, '>=')
        ->condition('field_project_version_extra_tran', $node->field_project_version_extra_tran->value, '>=')
        ->condition('field_project_version_extra_num', $node->field_project_version_extra_num->value, '>=')
        ->condition('field_project', $node->field_project->target_id)
        ->condition('field_project_version', $node->field_project_version->value, '!=')
        ->execute();
      if (!empty($update_ids)) {
        $message = $this->t('Newer Version Availiable');
      }
      else {
        $message = $this->t('Most up to date.');
      }
      $output[] = [
        '#markup' => $message,
      ];
      $output[] = $node_view_builder->view($node, 'teaser');
    }
    return $output;
  }

}
