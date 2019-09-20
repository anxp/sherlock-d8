<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-17
 * Time: 08:51
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;
use Drupal\sherlock_d8\Traits\SqlNative;

class SherlockTrouvailleEntity implements iSherlockTrouvailleEntity {
  use SqlNative;

  /**
   * @var DatabaseManager $dbConnection
   */
  protected $dbConnection = null;

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

  protected function getDbConnection(): DatabaseManager {
    return $this->dbConnection;
  }

  public function __construct(DatabaseManager $dbConnection) {
    $this->dbConnection = $dbConnection;
  }

  public function deleteUnmatched(int $taskID, string $fieldToMatch, array $data): int {
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

    $itemsToDelete = $this->dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->selectRecords($selectCondition, 'id', FALSE);

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

    $rowsDeleted = $this->dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->deleteRecords($deleteCondition, FALSE);

    return $rowsDeleted;
  }

  public function markAsNotNew(int $taskID): int {
    $rowsUpdated = $this->dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->setData(['is_new' => 0])->updateRecords(['task_id' => $taskID]);

    return $rowsUpdated;
  }

  public function getRecordsForSpecifiedTask($taskID, $keyResultBy): array {
    $selectedRecords = $this->dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->selectRecords(['task_id' => $taskID], $keyResultBy);

    return $selectedRecords;
  }

  public function insertMultiple(int $userID, int $taskID, array $dataToInsert): int {
    //Prepare data for insertion:
    $insertData = [];

    foreach ($dataToInsert as $marketID => $marketResults) {
      $marketResultsCount = count($marketResults);
      for ($i = 0; $i < $marketResultsCount; $i++) {
        $oneRecord = [];
        $oneRecord['uid'] = $userID;
        $oneRecord['task_id'] = $taskID;
        $oneRecord['fmkt_id'] = $marketID;

        //Item title:
        $oneRecord['title'] = $marketResults[$i]['title'];

        //Item URL:
        $oneRecord['url'] = $marketResults[$i]['link'];

        //Check if price is integer, if not - assign it to NULL:
        $oneRecord['price'] = intval($marketResults[$i]['price_value']) > 0 ? intval($marketResults[$i]['price_value']) : null;

        //Usually, currency code is 3-letters long, but anyway for reinsurance we take only first 3 letters (because in DB this field is CHAR(3)):
        $oneRecord['currency'] = mb_substr($marketResults[$i]['price_currency'], 0, 3);

        //Thumbnail URL:
        $oneRecord['img_url'] = $marketResults[$i]['thumbnail'];

        //TODO: RESERVED FOR FUTURE, maybe to make images permanent:
        $oneRecord['img_id'] = 0;

        //Validate if hashes contain 32 symbols, or throw exception:
        if (strlen($marketResults[$i]['url_hash']) === 32 && strlen($marketResults[$i]['url_price_hash']) === 32) {
          $oneRecord['url_hash'] = $marketResults[$i]['url_hash'];
          $oneRecord['url_price_hash'] = $marketResults[$i]['url_price_hash'];
        } else {
          $urlHashLen = strlen($marketResults[$i]['url_hash']);
          $urlPriceHashLen = strlen($marketResults[$i]['url_price_hash']);
          $exceptionMessage = 'Cannot write set of records to DB, because url_hash should be 32 sym, [' . $urlHashLen . '] detected instead; url_price_hash should be 32 sym, [' . $urlPriceHashLen . '] detected instead.';
          throw new InvalidInputData($exceptionMessage);
        }

        $insertData[] = $oneRecord;
      }
    }
    unset($marketID, $marketResults);

    $rowsInsertedTotal = $this->insert(SHERLOCK_RESULTS_TABLE, $insertData);

    return $rowsInsertedTotal;
  }

  public function deleteByTaskID($taskID): int {
    $deletedRowsNum = $this->dbConnection->selectTable(SHERLOCK_RESULTS_TABLE)->deleteRecords(['task_id' => $taskID]);
    return $deletedRowsNum;
  }
}

