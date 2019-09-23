<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-22
 * Time: 14:38
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockCacheEntity;

interface iSherlockCacheEntity {
  const CACHE_LIFE_HOURS = 23;

  public function load(string $urlQueryHash, int $loadNotOlderThanInHours = self::CACHE_LIFE_HOURS): array;
  public function save(string $hashAsName, array $queryUrlResults, bool $overwriteExpiredCache = TRUE, int $expirationTimeInHours = self::CACHE_LIFE_HOURS): int;
  public function deleteExpired(int $expirationTimeInHours = self::CACHE_LIFE_HOURS): int;
}
