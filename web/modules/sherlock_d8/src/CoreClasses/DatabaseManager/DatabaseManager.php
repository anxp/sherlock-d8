<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-06-16
 * Time: 22:18
 */

namespace Drupal\sherlock_d8\CoreClasses\DatabaseManager;

use Drupal\Core\Database\Connection;

class DatabaseManager {
  protected $mappedData = null;
  protected $selectedTable = null;

  /**
   * @var \Drupal\Core\Database\Connection $dbConnection
   */
  protected $dbConnection;

  /**
   * Constructs a new DatabaseManager object.
   * @param \Drupal\Core\Database\Connection $dbConnection
   */
  public function __construct(Connection $dbConnection) {
    $this->dbConnection = $dbConnection;
  }

  public function setData($mappedData) {
    $this->mappedData = $mappedData;
    return $this;
  }

  public function selectTable($tableName) {
    $this->selectedTable = $tableName;
    return $this;
  }

  public function insertRecord() {
    $this->dbConnection->insert($this->selectedTable)->fields($this->mappedData)->execute();
  }

  public function checkIfRecordExists() {
  }
}

