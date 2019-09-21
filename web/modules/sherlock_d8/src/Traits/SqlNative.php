<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-20
 * Time: 09:22
 */

namespace Drupal\sherlock_d8\Traits;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\Core\Database\Database;

trait SqlNative {
  private $chunkSize = 100; //Max number of rows to insert per request

  abstract protected function getDbConnection(): DatabaseManager;

  public function fastInsert(string $tableName, array $insertData): int {
    //Dynamically build INSERT query with placeholders. We separately build $queryPlaceholders, which contains ONLY placeholders, and
    //$queryData, which is array of associative arrays with placeholders names and their values, as discussed here -
    //https://stackoverflow.com/questions/15069962/php-pdo-insert-batch-multiple-rows-with-placeholders
    $queryPlaceholders = [];
    $queryData = [];

    $insertData = array_values($insertData); //Just to be sure array is re-indexed and starting from 0!

    if (!isset($insertData[0])) {
      return 0;
    }
    
    $fieldNamesAsString = implode(', ', array_keys($insertData[0]));

    $insertRowsNum = count($insertData);

    for ($i = 0; $i < $insertRowsNum; $i++) {
      $placeholdersString = '';

      foreach ($insertData[$i] as $fieldName => $fieldValue) {
        /*
         * Construct string with placeholders.
         * After foreach loop $placeholdersString will look like ":uid1,:task_id1,:fmkt_id1,:title1,:url1,"
         */
        $placeholdersString .= ':' . $fieldName . $i . ',';

        /*
         * Assemble each element of $queryData[i] (array where keys are names of placeholders and values - are their values)
         */
        $queryData[$i][':' . $fieldName . $i] = $fieldValue;
      }
      unset($fieldName, $fieldValue);

      $placeholdersString = rtrim($placeholdersString, ','); //Remove ending comma from placeholders string
      $placeholdersString = '(' . $placeholdersString . ')'; //Wrap CURRENT SET of placeholders in parentheses

      $queryPlaceholders[$i] = $placeholdersString;
    }

    //Next, instead of simply INSERT set of rows to DB, we should check if data isn't too big,
    //and if it is, -> split data into chunks. We will split data into 100 rows ($this->chunkSize = 100) per insert-operation.
    $rowsInsertedTotal = 0;

    if (!empty($queryPlaceholders) && !empty($queryData) && count($queryPlaceholders) === count($queryData)) {
      $queryPlaceholdersChunked = array_chunk($queryPlaceholders, $this->chunkSize);
      $queryDataChunked = array_chunk($queryData, $this->chunkSize);

      $numberOfChunks = count($queryPlaceholdersChunked);

      for ($i = 0; $i < $numberOfChunks; $i++) {
        $insertQueryCurrentIteration = 'INSERT INTO {' . $tableName . '} (' . $fieldNamesAsString . ') VALUES ';
        $insertQueryCurrentIteration .= implode(', ', $queryPlaceholdersChunked[$i]);
        $insertQueryCurrentIteration .= ';';

        $insertDataCurrentIteration = [];
        foreach ($queryDataChunked[$i] as $chunkElement) {
          $insertDataCurrentIteration = array_merge($insertDataCurrentIteration, $chunkElement);
        }
        unset($chunkElement);

        $rowsInsertedTotal += $this->getDbConnection()->query($insertQueryCurrentIteration, $insertDataCurrentIteration, ['return' => Database::RETURN_AFFECTED]);
      }
    }

    return $rowsInsertedTotal;
  }
}
