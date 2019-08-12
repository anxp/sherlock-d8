<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-07-30
 * Time: 21:01
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\Core\Form\FormStateInterface;

abstract class SherlockEntity implements iSherlockEntity {
  /**
   * @var int
   */
  protected static $uid = 0;

  /**
   * @var DatabaseManager|null
   */
  protected static $dbConnection = null;

  /**
   * @var FormStateInterface|null
   */
  protected static $shared_form_state = null;

  //SherlockSearchEntity object state flags:
  protected static $searchCreated = FALSE;
  protected static $searchUpdated = FALSE;
  protected static $searchDeleted = FALSE;

  //SherlockTaskEntity object state flags:
  protected static $taskCreated = FALSE;
  protected static $taskUpdated = FALSE;
  protected static $taskDeleted = FALSE;

  /**
   * This method instantiates object of needed class, depending on $entityType argument.
   * @param string $entityType - type of "entity" to handle. Possible values: TASK || SEARCH. This is not entity in Drupal terminology, and maybe will be refactored to one.
   * @param int $userID
   * @param DatabaseManager $dbConnection
   * @return SherlockEntity
   */
  static public function getInstance(string $entityType, int $userID, DatabaseManager $dbConnection): iSherlockEntity {
    switch ($entityType) {
      case ('TASK'):
        return new SherlockTaskEntity($userID, $dbConnection);
        break;

      case ('SEARCH'):
        return new SherlockSearchEntity($userID, $dbConnection);
        break;

      default:
        return null;
    }
  }

  public static function initializeSharedVariables(FormStateInterface $form_state) {
    if (!isset(self::$shared_form_state)) {
      self::$shared_form_state = $form_state;
    }
  }

  public static function resetFlags() {
    self::$searchCreated = FALSE;
    self::$searchUpdated = FALSE;
    self::$searchDeleted = FALSE;
    self::$taskCreated = FALSE;
    self::$taskUpdated = FALSE;
    self::$taskDeleted = FALSE;
  }

  protected function __construct(int $userID, DatabaseManager $dbConnection) {
    self::$uid = $userID;
    self::$dbConnection = $dbConnection;
  }

  /**
   * @return bool
   */
  public static function isSearchCreated(): bool {
    return self::$searchCreated;
  }

  /**
   * @return bool
   */
  public static function isSearchUpdated(): bool {
    return self::$searchUpdated;
  }

  /**
   * @return bool
   */
  public static function isSearchDeleted(): bool {
    return self::$searchDeleted;
  }

  /**
   * @return bool
   */
  public static function isTaskCreated(): bool {
    return self::$taskCreated;
  }

  /**
   * @return bool
   */
  public static function isTaskUpdated(): bool {
    return self::$taskUpdated;
  }

  /**
   * @return bool
   */
  public static function isTaskDeleted(): bool {
    return self::$taskDeleted;
  }

  abstract public function fillObjectWithFormData(FormStateInterface $form_state): void ;
  abstract public function save(): int;
  abstract public function load(int $recordID, bool $ignoreOwnership = FALSE): ?iSherlockEntity;
  abstract public function delete(int $recordID, bool $ignoreOwnership = FALSE): bool;
}
