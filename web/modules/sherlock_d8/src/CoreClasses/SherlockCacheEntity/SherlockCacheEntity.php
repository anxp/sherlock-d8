<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-22
 * Time: 14:45
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockCacheEntity;

use Drupal\Core\Database\Database;
use PDO;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;
use Drupal\sherlock_d8\Traits\SqlNative;

class SherlockCacheEntity implements iSherlockCacheEntity {

  use SqlNative;

  /**
   * @var DatabaseManager|null
   */
  protected $dbConnection = null;

  public function __construct(DatabaseManager $dbConnection) {
    $this->dbConnection = $dbConnection;
  }

  protected function getDbConnection(): DatabaseManager {
    return $this->dbConnection;
  }

  public function load(string $urlQueryHash): array {
    if (strlen($urlQueryHash) !== 32) {
      //This is not TOP-critical exception, we need just write in to log, but continue normal program execution after it!
      throw new InvalidInputData('Incorrect hash length gotten in SherlockCacheEntity::load');
    }

    $mostOldButStillActual = time() - self::CACHE_LIFE_HOURS * 60 * 60;

    $query = $this->dbConnection->getDrupalNativeDBC()->select(SHERLOCK_CACHE_INDEX_TABLE, 'idx');
    $query->join(SHERLOCK_CACHE_CONTENT_TABLE, 'cnt', 'idx.id = cnt.url_query_id');
    $query->fields('cnt', [])->condition('idx.url_query_hash', $urlQueryHash, '=')->condition('idx.created', $mostOldButStillActual, '>=');
    $result = $query->execute()->fetchAllAssoc('id', PDO::FETCH_ASSOC);

    return array_values($result); //Return re-indexed from 0 array
  }

  public function save(string $urlQueryHash, array $queryUrlResults, bool $overwriteExpiredCache = TRUE): int {
    if (strlen($urlQueryHash) !== 32) {
      //This is not TOP-critical exception, we need just write in to log, but continue normal program execution after it!
      throw new InvalidInputData('Incorrect hash length gotten in SherlockCacheEntity::save');
    }

    //Check if record already exists in index table:
    $condition = [
      'url_query_hash' => $urlQueryHash,
    ];

    $alreadyExistingIndexRecord = $this->dbConnection->selectTable(SHERLOCK_CACHE_INDEX_TABLE)->selectRecords($condition, 'id');

    //============================== NEW CACHE INSERTION ===============================================================
    //If no record with given $urlQueryHash has been found in index table ($alreadyExistingIndexRecord IS EMPTY ARRAY),
    //we guess that cache for this result is not yet created, and simply write it:
    if (empty($alreadyExistingIndexRecord)) {
      return $this->saveCore($urlQueryHash, $queryUrlResults); //return number of inserted cache records (for given url query);
    }
    //==================================================================================================================

    //============================== EXISTING CACHE PROCESSING =========================================================

    //Record already exists, let's get the record itself:
    $alreadyExistingIndexRecord = array_shift($alreadyExistingIndexRecord);

    //TODO: Maybe remove first condition, so if first results will appear after a period of completely NO results, such results will be IMMEDIATELY cached (ignoring cache lifetime):
    //If index\cache is not yet expired, or we don't want to overwrite expired cache -> just do nothing and return 0:
    if ((intval($alreadyExistingIndexRecord['created']) > (time() - self::CACHE_LIFE_HOURS * 60 * 60)) || $overwriteExpiredCache === FALSE) {
      return 0;
    }

    //So, we already have record in index table (and maybe some records in content table), but they are expired and should be overwritten:
    //1. Get url_query_id:
    $urlQueryID = intval($alreadyExistingIndexRecord['id']);

    //2. Now we should delete from DB all records which are NOT in input array (data actualization):
    $this->deleteUnmatched($urlQueryID, $queryUrlResults);

    //3. Now, we should delete from incoming data all records which are already in DB (and leave only new data):
    $onlyNewData = $this->selectNewOnly($urlQueryID, $queryUrlResults);

    //4. Update array with new records - add url_query_id field to each record:
    for ($i = 0; $i < count($onlyNewData); $i++) {
      $onlyNewData[$i]['url_query_id'] = $urlQueryID;
    }

    //5. Write new data to DB:
    $numInserted = $this->bulkInsert(SHERLOCK_CACHE_CONTENT_TABLE, $onlyNewData);

    //6. Update index timestamp:
    $this->dbConnection->selectTable(SHERLOCK_CACHE_INDEX_TABLE)->setData(['created' => time()])->updateRecords(['id' => $urlQueryID]);

    return $numInserted;
  }

  public function deleteForsaken(): int {
    //The Database API doesn't support the multiple tables:
    //https://www.drupal.org/forum/support/module-development-and-code-questions/2019-03-08/how-to-delete-from-multiple-tables
    //so we will use 'query' method

    $rowsAffected = $this->dbConnection->getDrupalNativeDBC()->query(
      'DELETE idx, cnt FROM {sherlock_cache_index} idx LEFT JOIN {sherlock_cache_content} cnt ON idx.id = cnt.url_query_id WHERE created < :notActualThreshold;',
      [':notActualThreshold' => (time() - self::CONSIDER_CACHE_FORSAKEN_HOURS * 60 * 60),],
      ['return' => Database::RETURN_AFFECTED,]
    );

    return $rowsAffected;
  }

  /**
   * This is UNSAFE save method (it does not check anything and just simply tries to perform insert records to DB).
   * Thus method intended to use ONLY inside class!
   * @param string $urlQueryHash
   * @param array $queryUrlResults
   * @return int
   * @throws InvalidInputData
   */
  protected function saveCore(string $urlQueryHash, array $queryUrlResults): int {
    //First, create index:
    $mappedData = [
      'url_query_hash' => $urlQueryHash,
      'created' => time(),
    ];

    $newCacheResultsID = $this->dbConnection->selectTable(SHERLOCK_CACHE_INDEX_TABLE)->setData($mappedData)->insertRecord();

    //Second, edit input array with data, so it would reflect structure of SHERLOCK_CACHE_CONTENT_TABLE
    //(we will use it for bulk insert data to table):
    $queryUrlResultsCount = count($queryUrlResults);

    /*
     * SHERLOCK_CACHE_CONTENT_TABLE full structure:
     * [id][url_query_id][title][link][price_value][price_currency][thumbnail][url_hash][url_price_hash]
     */
    for ($i = 0; $i < $queryUrlResultsCount; $i++) {
      $queryUrlResults[$i]['url_query_id'] = intval($newCacheResultsID);
      $queryUrlResults[$i]['title'] = mb_substr($queryUrlResults[$i]['title'], 0, 255); //Trim to 255 symbols, if it actually longer
      $priceInt = intval($queryUrlResults[$i]['price_value']);
      $queryUrlResults[$i]['price_value'] = $priceInt > 0 ? $priceInt : null;
      $queryUrlResults[$i]['price_currency'] = mb_substr($queryUrlResults[$i]['price_currency'], 0, 3);

      //Check if checksums are ok, before writing them to DB:
      if (strlen($queryUrlResults[$i]['url_hash']) !== 32 || strlen($queryUrlResults[$i]['url_price_hash']) !== 32) {
        $urlHashLen = strlen($queryUrlResults[$i]['url_hash']);
        $urlPriceHashLen = strlen($queryUrlResults[$i]['url_price_hash']);
        $exceptionMessage = 'Cannot write set of records to DB, because url_hash should be 32 sym, [' . $urlHashLen . '] detected instead; url_price_hash should be 32 sym, [' . $urlPriceHashLen . '] detected instead.';
        throw new InvalidInputData($exceptionMessage);
      }
    }

    $numInserted = 0;

    //Third, bulk insert results to results table:
    $numInserted = $this->bulkInsert(SHERLOCK_CACHE_CONTENT_TABLE, $queryUrlResults);

    return $numInserted;
  }

  protected function deleteUnmatched(int $urlQueryID, array $queryUrlResults): int {
    $elementsNum = count($queryUrlResults);
    $incomeResultsHashes = [];

    for ($i = 0; $i < $elementsNum; $i++) {
      $incomeResultsHashes[$i] = $queryUrlResults[$i]['url_price_hash']; //We use url_price_hash because it more 'strict' than url_hash
    }

    $deleteCondition = [
      'url_query_id' => [
        'comparison_value' => $urlQueryID,
        'comparison_op' => '=',
      ],
      'url_price_hash' => [
        'comparison_value' => $incomeResultsHashes,
        'comparison_op' => 'NOT IN',
      ],
    ];

    return $this->dbConnection->selectTable(SHERLOCK_CACHE_CONTENT_TABLE)->deleteRecords($deleteCondition, FALSE);
  }

  protected function selectNewOnly(int $urlQueryID, array $queryUrlResults): array {
    $condition = [
      'url_query_id' => $urlQueryID,
    ];

    //Load cache records from DB keyed by url_price_hash:
    $existingCacheRecords = $this->dbConnection->selectTable(SHERLOCK_CACHE_CONTENT_TABLE)->selectRecords($condition, 'url_price_hash');

    //Reformat incoming data to new array, where keys are hashes (url_price_hash):
    $incomingDataKeyedByHashes = [];

    $queryUrlResultsCount = count($queryUrlResults);

    for ($i = 0; $i < $queryUrlResultsCount; $i++) {
      $incomingDataKeyedByHashes[$queryUrlResults[$i]['url_price_hash']] = $queryUrlResults[$i];
    }

    unset($queryUrlResults); //Free some memory

    $onlyNewData = [];
    $onlyNewData = array_diff_key($incomingDataKeyedByHashes, $existingCacheRecords);
    $onlyNewData = array_values($onlyNewData); //Reindex array

    return $onlyNewData;
  }
}
