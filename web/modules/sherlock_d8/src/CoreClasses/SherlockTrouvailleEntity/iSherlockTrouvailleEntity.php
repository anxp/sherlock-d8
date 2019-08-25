<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-17
 * Time: 08:33
 */
namespace Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity;

interface iSherlockTrouvailleEntity {
  public static function deleteUnmatched(int $taskID, string $urlPriceHash, array $hashpool): int;
  public static function markAsNotNew(int $taskID): int;
  public static function getRecordsForSpecifiedTask(int $taskID, string $keyResultBy): array;
  public static function insertMultiple(int $userID, int $taskID, array $dataToInsert): int;
  public static function deleteByTaskID($taskID): int;
}
