<?php

namespace Drupal\module_upgrade_migrate_projects\Plugin\migrate\source;

use Drupal\migrate_source_csv\Plugin\migrate\source\CSV as CSVBase;
use Drupal\migrate\Row;

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
      ->condition('vid', 'project')
      ->condition('field_project_machine_name', $row->project_machine_name)
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
