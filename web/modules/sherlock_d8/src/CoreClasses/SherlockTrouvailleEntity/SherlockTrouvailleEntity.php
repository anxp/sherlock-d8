<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-17
 * Time: 08:51
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\Core\Database\Database;

class SherlockTrouvailleEntity implements iSherlockTrouvailleEntity {
  const DB_INSERT_CHUNK_SIZE = 100; //Max number of rows to insert per request

  protected $dbConnection;

  protected $id = 0;
  protected $uid = 0;
  protected $task_id = 0;
  protected $is_new = null;
  protected $fmkt_id = '';
  protected $item_title = '';
  protected $item_url = '';
  protected $item_price = null;
  protected $item_currency = '';
  protected $item_img = '';
  protected $url_hash = '';
  protected $url_price_hash = '';

  public static function deleteUnmatched(int $taskID, string $fieldToMatch, array $data): int {
    //TODO: Maybe rewrite to dependency injection? But this is static method, we use it independently from trouvaille object... So let it be as it is at the moment...
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = \Drupal::service('sherlock_d8.database_manager');

    if (empty($data)) {
      return 0;
    }

    $selectCondition = [
      'task_id' => [
        'comparison_value' => $taskID,
        'comparison_op' => '=',
      ],
      $fieldToMatch => [
        'comparison_value' => $data,
        'comparison_op' => 'NOT IN',
      ],
    ];

    $itemsToDelete = $dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->selectRecords($selectCondition, 'id', FALSE);

    if (empty($itemsToDelete)) {
      return 0;
    }

    $idsToDelete = array_keys($itemsToDelete);

    $deleteCondition = [
      'id' => [
        'comparison_value' => $idsToDelete,
        'comparison_op' => 'IN',
      ],
    ];

    $rowsDeleted = $dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->deleteRecords($deleteCondition, FALSE);

    return $rowsDeleted;
  }

  public static function markAsNotNew(int $taskID): int {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = \Drupal::service('sherlock_d8.database_manager');

    $rowsUpdated = $dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->setData(['is_new' => 0])->updateRecords(['task_id' => $taskID]);

    return $rowsUpdated;
  }

  public static function getRecordsForSpecifiedTask($taskID, $keyResultBy): array {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = \Drupal::service('sherlock_d8.database_manager');

    $selectedRecords = $dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->selectRecords(['task_id' => $taskID], $keyResultBy);

    return $selectedRecords;
  }

  public static function insertMultiple($userID, $taskID, $dataToInsert): int {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = \Drupal::service('sherlock_d8.database_manager');

    //Dynamically build INSERT query with placeholders. We separately build insertQuery, which contains ONLY placeholders, and
    //insertData, which contain associative array with placeholders names and their values, as discussed here -
    //https://stackoverflow.com/questions/15069962/php-pdo-insert-batch-multiple-rows-with-placeholders
    $insertQuery = [];
    $insertData = [];
    $n = 0;

    foreach ($dataToInsert as $marketID => $marketResults) {
      $marketResultsCount = count($marketResults);
      for ($i = 0; $i < $marketResultsCount; $i++) {
        $insertQuery[$n] = '(:uid'.$n.', :task_id'.$n.', :fmkt_id'.$n.', :title'.$n.', :url'.$n.', :price'.$n.', :currency'.$n.', :img_url'.$n.', :img_id'.$n.', :url_hash'.$n.', :url_price_hash'.$n.')';

        $insertData[$n][':uid'.$n] = $userID;
        $insertData[$n][':task_id'.$n] = $taskID;
        $insertData[$n][':fmkt_id'.$n] = $marketID;

        //TODO: Validate if title is not empty, or throw exception:
        $insertData[$n][':title'.$n] = $marketResults[$i]['title'];

        //TODO: Validate if this is URL, or throw exception:
        $insertData[$n][':url'.$n] = $marketResults[$i]['link'];

        //TODO: Validate if this is numeric value, or throw exception:
        $insertData[$n][':price'.$n] = $marketResults[$i]['price_value'];

        //TODO: Validate if currency contain 3 letters (throw exception if needed), take first 3 letters anyway:
        $insertData[$n][':currency'.$n] = $marketResults[$i]['price_currency'];

        //TODO: Validate if this is URL, or throw exception:
        $insertData[$n][':img_url'.$n] = $marketResults[$i]['thumbnail'];

        //TODO: ADD THIS FUNCTIONALITY:
        $insertData[$n][':img_id'.$n] = 0;

        //TODO: Validate if hashes contain 32 symbols, or throw exception:
        $insertData[$n][':url_hash'.$n] = $marketResults[$i]['url_hash'];
        $insertData[$n][':url_price_hash'.$n] = $marketResults[$i]['url_price_hash'];

        $n++;
      }
    }
    unset($marketID, $marketResults);

    //Next, instead of simply INSERT set of rows to DB, we should check if data isn't too big,
    //and if it is, -> split data into chunks. We will split data into 100 rows (self::DB_INSERT_CHUNK_SIZE) per insert-operation.
    $rowsInsertedTotal = 0;

    if (!empty($insertQuery) && !empty($insertData) && count($insertQuery) === count($insertData)) {
      $insertQueryChunked = array_chunk($insertQuery, self::DB_INSERT_CHUNK_SIZE);
      $insertDataChunked = array_chunk($insertData, self::DB_INSERT_CHUNK_SIZE);

      $numberOfChunks = count($insertQueryChunked);

      for ($i = 0; $i < $numberOfChunks; $i++) {
        $insertQueryCurrentIteration = 'INSERT INTO {' . SHERLOCK_RESULTS_TABLE . '} (uid, task_id, fmkt_id, title, url, price, currency, img_url, img_id, url_hash, url_price_hash) VALUES ';

        $insertQueryCurrentIteration .= implode(', ', $insertQueryChunked[$i]);
        $insertQueryCurrentIteration .= ';';

        $insertDataCurrentIteration = [];

        foreach ($insertDataChunked[$i] as $chunkElement) {
          $insertDataCurrentIteration = array_merge($insertDataCurrentIteration, $chunkElement);
        }
        unset($chunkElement);

        $rowsInsertedTotal += $dbConnection->query($insertQueryCurrentIteration, $insertDataCurrentIteration, ['return' => Database::RETURN_AFFECTED]);
      }
    }

    return $rowsInsertedTotal;
  }

  public static function deleteByTaskID($taskID): int {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = \Drupal::service('sherlock_d8.database_manager');

    $deletedRowsNum = $dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->deleteRecords(['task_id' => $taskID]);
    return $deletedRowsNum;
  }
}
