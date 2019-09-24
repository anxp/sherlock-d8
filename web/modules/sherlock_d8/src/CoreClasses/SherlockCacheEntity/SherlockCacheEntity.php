<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-22
 * Time: 14:45
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockCacheEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;

class SherlockCacheEntity implements iSherlockCacheEntity {
  /**
   * @var DatabaseManager|null
   */
  protected $dbConnection = null;
  protected $tableName = '';
  protected $cacheLifeHours = 0;

  public function __construct(DatabaseManager $dbConnection, string $tableName = SHERLOCK_RESULTS_CACHE_TABLE, int $cacheLifeHours = self::CACHE_LIFE_HOURS) {
    $this->dbConnection = $dbConnection;
    $this->tableName = $tableName;
    $this->cacheLifeHours = $cacheLifeHours;
  }

  public function load(string $urlQueryHash): array {
    if (strlen($urlQueryHash) !== 32) {
      //This is not TOP-critical exception, we need just write in to log, but continue normal program execution after it!
      throw new InvalidInputData('Incorrect hash length gotten in SherlockCacheEntity::load');
    }

    $condition = [
      'url_query_hash' => $urlQueryHash,
    ];

    $cachedResults = $this->dbConnection->selectTable($this->tableName)->selectRecords($condition, 'id');

    if (empty($cachedResults)) {
      return [];
    }

    $cachedResults = array_shift($cachedResults);
    //If cache is older than we need, just return empty array too:
    if (intval($cachedResults['created']) < (time() - $this->cacheLifeHours * 60 * 60)) {
      return [];
    }

    $resultsIndexedArray = array_values(unserialize($cachedResults['serialized_results']));
    return $resultsIndexedArray;
  }

  public function save(string $hashAsName, array $queryUrlResults, bool $overwriteExpiredCache = TRUE): int {
    $oneRecord = [
      'url_query_hash' => $hashAsName,
      'serialized_results' => serialize($queryUrlResults),
      'created' => time(),
    ];

    //Preparation step - try to clean up expired record, if one exists:
    if ($overwriteExpiredCache) {
      $condition = [
        'created' => [
          'comparison_value' => time() - $this->cacheLifeHours * 60 * 60,
          'comparison_op' => '<',
        ],

        'url_query_hash' => [
          'comparison_value' => $hashAsName,
          'comparison_op' => '=',
        ],
      ];

      $deletedRowsNum = $this->dbConnection->selectTable($this->tableName)->deleteRecords($condition, FALSE);
    }

    //If record has been deleted, or never exists -> replace it with newer one:
    if (!$this->dbConnection->selectTable($this->tableName)->checkIfRecordExists(['url_query_hash' => $hashAsName])) {
      return $this->dbConnection->selectTable($this->tableName)->setData($oneRecord)->insertRecord();
    }

    return 0;
  }

  public function deleteExpired(): int {
    $condition = [
      'created' => [
        'comparison_value' => time() - $this->cacheLifeHours * 60 * 60,
        'comparison_op' => '<',
      ],
    ];

    $deletedRowsNum = $this->dbConnection->selectTable($this->tableName)->deleteRecords($condition, FALSE);

    return $deletedRowsNum;
  }
}
