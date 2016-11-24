<?php

namespace Drupal\module_upgrade_migrate_projects\Plugin\migrate\source;

use Drupal\migrate_source_csv\Plugin\migrate\source\CSV as CSVBase;
use Drupal\migrate\Row;
use Drupal\taxonomy\Entity\Term;

/**
 * Source for CSV.
 *
 * @MigrateSource(
 *   id = "migrate_upgrade_csv"
 * )
 */
class CSV extends CSVBase {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $tids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'drupal_core_version')
      ->condition('name', $row->api)
      ->execute();
    if (empty($tids)) {
      $term = Term::create([
        'vid' => 'drupal_core_version',
        'name' => $row->api,
      ]);
      $term->save();
      $tids[] = $term->id();
    }
    $drupal_core_version_tid = reset($tids);
    $tids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'project')
      ->condition('field_project_machine_name', $row->project_machine_name)
      ->condition('field_drupal_core_version', $drupal_core_version_tid)
      ->execute();
    if (!empty($tids)) {
      $this->idMap->saveIdMapping($row, array(), MigrateIdMapInterface::STATUS_IGNORED);
      $this->currentRow = NULL;
      $this->currentSourceIds = NULL;
      return FALSE;
    }
    return parent::prepareRow($row);
  }

}
