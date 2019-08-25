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
use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;
use Drupal\Core\Database\Query\ConditionInterface;

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

  public function query(string $queryString, array $args = [], array $options = []) {
    $result = $this->dbConnection->query($queryString, $args, $options);
    return $result;
  }

  /**
   * This method is for selecting records in DB table by specified criterion ($assocWhereClause).
   * Result will be an array, keyed with values from table field/column, specified in $keyResultBy parameter ($keyResultBy is just a string with name of column),
   * and VALUES will be associative sub-arrays with all other field values.
   * Fields, need to be selected from table can be limited by setFieldsToGet() method, which need to be placed BEFORE this method in calling chain.
   *
   * Example of $assocWhereClause array:
   * $assocWhereClause = [
   *  'field_name' => [
   *    'comparison_value' => 'some_value',
   *    'comparison_op' => '=',
   *  ],
   *
   *  'other_field' => [
   *    'comparison_value' => 1000,
   *    'comparison_op' => '>',
   *  ],
   * ];
   *
   * If $useSimpleCondition == TRUE, condition can be set in more simplified way:
   *
   * $assocWhereClause = [
   *  'field_name' => 'some_value',
   *  'other_field' => '1000',
   * ];
   *
   * This implies that the comparison operator is "="
   *
   * @param array $assocWhereClause
   * @param string $keyResultBy
   * @param bool $useSimpleCondition
   * @return array
   * @throws InvalidInputData
   */
  public function selectRecords(array $assocWhereClause, string $keyResultBy, bool $useSimpleCondition = TRUE): array {
    if (!$useSimpleCondition) {
      $this->validateWhereCondition($assocWhereClause);
    }

    $query = $this->dbConnection->select($this->selectedTable); //This is equivalent of FROM table_name

    $query = $this->embedWhereConditionsIntoQuery($query, $assocWhereClause, $useSimpleCondition);

    //This is equivalent of SELECT field_1, field_2, field_5 to select SOME fields from table.
    //If not set, $this->fieldsToGet == [] by default, so ALL fields will be selected.
    $query->fields($this->selectedTable, $this->fieldsToGet);

    $result = $query->execute()->fetchAllAssoc($keyResultBy, PDO::FETCH_ASSOC);

    return $result;
  }

  public function insertRecord() {
    //TODO: Wrap in try-catch
    //TODO: Investigate, what returns this function when inserting many records.
    $recordID = $this->dbConnection->insert($this->selectedTable)->fields($this->mappedData)->execute();
    return $recordID;
  }

  public function updateRecords(array $assocWhereClause, bool $useSimpleCondition = TRUE): int {
    if (!$useSimpleCondition) {
      $this->validateWhereCondition($assocWhereClause);
    }

    $query = $this->dbConnection->update($this->selectedTable); //This is equivalent of FROM table_name

    $query = $this->embedWhereConditionsIntoQuery($query, $assocWhereClause, $useSimpleCondition);

    $query->fields($this->mappedData); //This is equivalent of SET field_name=field_value

    $numberOfRows = $query->execute();

    return $numberOfRows;
  }

  public function deleteRecords(array $assocWhereClause, bool $useSimpleCondition = TRUE): int {
    if (!$useSimpleCondition) {
      $this->validateWhereCondition($assocWhereClause);
    }

    $query = $this->dbConnection->delete($this->selectedTable); //This is equivalent of FROM table_name

    $query = $this->embedWhereConditionsIntoQuery($query, $assocWhereClause, $useSimpleCondition);

    $numberOfRows = $query->execute();

    return $numberOfRows;
  }

  /**
   * This method intended for deleting records from table, but with more flexibility than simple deleteRecords() method.
   * Instead of using "condition" method under the hood, it uses "where", allowing user put virtually any valid SQL condition statement literally.
   * If SQL statement contains variables, they should be replaced with placeholders (like :variable), and array with placeholders as keys
   * and their values should be passed as part of corresponded value of $whereClausesSet array
   * (so every element of $whereClausesSet should looks like $whereClausesSet[0]['where_condition'] and $whereClausesSet[0]['args']).
   * If no variables needed to be passed, just empty array should be passed under 'args' key (but 'args' key should be set!).
   *
   * More details about $query->where($snippet, $args = array()): https://www.drupal.org/node/310086
   *
   * Example of client code:
   *
   * $currentTimestamp = time();
   *
   * $whereClause = [
   *  0 => [
   *    'where_condition' => '(`changed` + `keep_alive_days`*24*60*60) < :current_timestamp',
   *    'args' => [':current_timestamp' => $currentTimestamp],
   *  ],
   * ];
   *
   * $deletedRecords = $dbConnection->selectTable('sherlock_user_input')->deleteRecordsExtended($whereClause);
   *
   * Please note! Table field names should be enclosed in backquotes.
   *
   * @param $whereClausesSet - array where each value is also array with SQL "where" statement and variables. If this array contain
   * more than one element - these SQL statements will be combined through the operator AND (default Drupal DB API behavior).
   * @return int - number of deleted records, or 0 if no records were deleted.
   * @throws InvalidInputData
   */
  public function deleteRecordsExtended(array $whereClausesSet): int {
    //Check input data:
    foreach ($whereClausesSet as $whereClause) {
      if (!isset($whereClause['where_condition']) || !isset($whereClause['args']) || !is_string($whereClause['where_condition']) || !is_array($whereClause['args'])) {
        throw new InvalidInputData('Improperly filled $whereClausesSet. Each value of this array should be also array with keys \'where_condition\' and \'args\'. Where \'where_condition\' is any legal SQL WHERE fragment, and \'args\' is array where keys are placeholders (like :var), which values will be substituted into the where_condition.');
      }
    }
    unset($whereClause);

    $query = $this->dbConnection->delete($this->selectedTable); //This is equivalent of FROM table_name

    foreach ($whereClausesSet as $whereClause) {
      $query->where($whereClause['where_condition'], $whereClause['args']);
    }
    unset($whereClause);

    $numberOfRows = $query->execute();

    return $numberOfRows;
  }

  /**
   * This method intended to check if record already exists in specified table.
   * Specified table need to be set by selectTable method.
   * Input parameter is associative array, where keys are names of table fields, and values are corresponding values,
   * example of input data array: ['uid' => 1, 'title' => 'Hello world!', 'is_published' => 1]
   * @param $mappedData array
   * @return int
   */
  public function checkIfRecordExists(array $mappedData): int {
    $query = $this->dbConnection->select($this->selectedTable); //This is equivalent of FROM table_name

    //Here we'll add as many '=' conditions as number of values in $mappedData array.
    foreach ($mappedData as $fieldName => $fieldValue) {
      $query->condition($this->selectedTable.'.'.$fieldName, $fieldValue, '=');
    }
    unset ($fieldName, $fieldValue);

    $query->fields($this->selectedTable); //This is equivalent of SELECT * to select all fields from table.

    $numberOfRows = $query->countQuery()->execute()->fetchField(); //This is equivalent of COUNT aggregation function.

    return $numberOfRows;
  }

  /**
   * Performs validation of associative array with complex WHERE condition. Throws exception if array structure is not correct.
   * @param array $assocWhereClause
   * @throws InvalidInputData
   */
  protected function validateWhereCondition(array $assocWhereClause) {
    //Check input data:
    foreach ($assocWhereClause as $clause) {
      if (!isset($clause['comparison_value']) || !isset($clause['comparison_op']) || !is_string($clause['comparison_op']) || !in_array($clause['comparison_op'], ['=', '>', '<', 'IN', 'NOT IN'])) {
        throw new InvalidInputData('Improperly filled $assocWhereClause. Each value of this array should be also array with keys \'comparison_value\' and \'comparison_op\'.');
      }
    }
    unset($clause);
  }

  /**
   * @param ConditionInterface $query
   * @param array $assocWhereClause
   * @param bool $useSimpleCondition
   * @return ConditionInterface
   */
  protected function embedWhereConditionsIntoQuery(ConditionInterface $query, array $assocWhereClause, bool $useSimpleCondition) {
    //Here we'll add as many conditions as number of values in $assocWhereClause array.
    switch (TRUE) {
      case ($useSimpleCondition):
        foreach ($assocWhereClause as $fieldName => $fieldValue) {
          $query->condition($this->selectedTable.'.'.$fieldName, $fieldValue, '=');
        }
        unset($fieldName, $fieldValue);

        break;

      case (!$useSimpleCondition):
        foreach ($assocWhereClause as $fieldName => $clause) {
          $query->condition($this->selectedTable.'.'.$fieldName, $clause['comparison_value'], $clause['comparison_op']);
        }
        unset ($fieldName, $clause);

        break;
    }

    return $query;
  }
}

