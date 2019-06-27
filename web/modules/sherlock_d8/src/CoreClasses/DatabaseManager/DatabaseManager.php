<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-06-16
 * Time: 22:18
 */

namespace Drupal\sherlock_d8\CoreClasses\DatabaseManager;

use Drupal\Core\Database\Connection;
use PDO;

class DatabaseManager {
  protected $mappedData = [];
  protected $selectedTable = null;
  protected $fieldsToGet = [];

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

  public function setFieldsToGet($fieldsToGet) {
    $this->fieldsToGet = $fieldsToGet;
    return $this;
  }

  /**
   * This method is for selecting records in DB table by specified criterion ($assocWhereClause).
   * Result will be an array, keyed with values from table field/column, specified in $keyResultBy parameter ($keyResultBy is just a string with name of column),
   * and VALUES will be associative sub-arrays with all other field values.
   * Fields, need to be selected from table can be limited by setFieldsToGet() method, which need to be placed BEFORE this method in calling chain.
   * @param array $assocWhereClause
   * @param string $keyResultBy
   * @return array
   */
  public function selectRecords(array $assocWhereClause, string $keyResultBy): array {
    $query = $this->dbConnection->select($this->selectedTable); //This is equivalent of FROM table_name

    //Here we'll add as many '=' conditions as number of values in $assocWhereClause array.
    foreach ($assocWhereClause as $fieldName => $fieldValue) {
      $query->condition($this->selectedTable.'.'.$fieldName, $fieldValue, '=');
    }
    unset ($fieldName, $fieldValue);

    //This is equivalent of SELECT field_1, field_2, field_5 to select SOME fields from table.
    //If not set, $this->fieldsToGet == [] by default, so ALL fields will be selected.
    $query->fields($this->selectedTable, $this->fieldsToGet);

    $result = $query->execute()->fetchAllAssoc($keyResultBy, PDO::FETCH_ASSOC);

    return $result;
  }

  public function insertRecord() {
    //TODO: Wrap in try-catch
    $this->dbConnection->insert($this->selectedTable)->fields($this->mappedData)->execute();
    //TODO: Rewrite this function to return TRUE or FALSE depending on was save successful or not.
    return TRUE;
  }

  public function updateRecords($assocWhereClause) {
    $query = $this->dbConnection->update($this->selectedTable); //This is equivalent of FROM table_name

    //Here we'll add as many '=' conditions as number of values in $assocWhereClause array.
    foreach ($assocWhereClause as $fieldName => $fieldValue) {
      $query->condition($this->selectedTable.'.'.$fieldName, $fieldValue, '=');
    }
    unset ($fieldName, $fieldValue);

    $query->fields($this->mappedData); //This is equivalent of SET field_name=field_value

    $numberOfRows = $query->execute();

    if ($numberOfRows > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
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

