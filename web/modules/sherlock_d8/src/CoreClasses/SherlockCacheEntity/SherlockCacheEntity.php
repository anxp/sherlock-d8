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

  public function __construct(DatabaseManager $dbConnection) {
    $this->dbConnection = $dbConnection;
  }

  public function load(string $urlQueryHash): array {
    if (strlen($urlQueryHash) !== 32) {
      //This is not TOP-critical exception, we need just write in to log, but continue normal program execution after it!
      throw new InvalidInputData('Incorrect hash length gotten in SherlockCacheEntity::load');
    }

    return [];
  }

  public function save(string $hashAsName, array $queryUrlResults, bool $overwriteExpiredCache = TRUE): int {

    return 0;
  }

  public function deleteExpired(): int {

    return 0;
  }
}
