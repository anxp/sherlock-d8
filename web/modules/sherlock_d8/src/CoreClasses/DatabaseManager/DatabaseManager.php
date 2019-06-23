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
    //TODO: Wrap in try-catch
    $this->dbConnection->insert($this->selectedTable)->fields($this->mappedData)->execute();
  }

  /**
   * This method intended to check if record already exists in specified table.
   * Specified table need to be set by selectTable method.
   * Input parameter is associative array, where keys are names of table fields, and values are corresponding values,
   * example of input data array: ['uid' => 1, 'title' => 'Hello world!', 'is_published' => 1]
   * @param $mappedData array
   * @return bool
   */
  public function checkIfRecordExists(array $mappedData): bool {
    $query = $this->dbConnection->select($this->selectedTable); //This is equivalent of FROM table_name

    //Here we'll add as many '=' conditions as number of values in $mappedData array.
    foreach ($mappedData as $fieldName => $fieldValue) {
      $query->condition($this->selectedTable.'.'.$fieldName, $fieldValue, '=');
    }
    unset ($fieldName, $fieldValue);

    $query->fields($this->selectedTable); //This is equivalent of SELECT * to select all fields from table.

    $numberOfRows = $query->countQuery()->execute()->fetchField(); //This is equivalent of COUNT aggregation function.

    if ($numberOfRows > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }
}

