<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-22
 * Time: 14:38
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockCacheEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;

interface iSherlockCacheEntity {
  const CACHE_LIFE_HOURS = 23;

  /*
   * Available tables for cache:
   * SHERLOCK_CACHE_CONTENT_TABLE,
   * SHERLOCK_CACHE_INDEX_TABLE;
   */

  public function __construct(DatabaseManager $dbConnection);
  public function load(string $urlQueryHash): array;
  public function save(string $hashAsName, array $queryUrlResults, bool $overwriteExpiredCache = TRUE): int;
  public function deleteExpired(): int;
}
