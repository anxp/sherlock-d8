<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-07-30
 * Time: 21:01
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

use Drupal\Core\Form\FormStateInterface;

class SherlockTaskEntity extends SherlockEntity implements iSherlockTaskEntity {
  //These variables represent fields in SHERLOCK_TASKS_TABLE:
  protected $id = 0;
  protected $task_essence = [];
  protected $created = 0;
  protected $modified = 0;
  protected $last_checked = 0;
  protected $active_to = 0;

  public function fillObjectWithFormData(FormStateInterface $form_state): void {
    self::initializeSharedVariables($form_state);

    $this->task_essence = $form_state->get(['sherlock_tmp_storage']);

    $servingPeriod = intval($form_state->getValue(['save_search_block', 'serving_period_selector'])); //Number of DAYS
    $this->active_to = time() + $servingPeriod * 24 * 60 * 60;
  }

  public function save(): int {
    if ($this->id === 0) {
      $name = self::$shared_form_state->getValue(['save_search_block', 'search_name_textfield']);
      $nameHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $name);

      $condition = [
        'uid' => self::$uid,
        'name_hash' => $nameHash,
      ];

      $loadAttempt = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);
      $this->id = !empty($loadAttempt) ? array_shift($loadAttempt)['task_id'] : 0;

      unset($loadAttempt);
    }

    $doesTaskExist = self::$dbConnection->selectTable(SHERLOCK_TASKS_TABLE)->checkIfRecordExists(['id' => $this->id]);

    $newData = [
      'serialized_task' => serialize($this->task_essence),
      'modified' => time(),
      'active_to' => $this->active_to,
    ];

    //Update last_checked field in DB only if it has been explicitly set by setLastChecked(), FOR EXISTING TASK ONLY:
    if ($this->last_checked !== 0) {
      $newData['last_checked'] = $this->last_checked;
    }

    //If this is new insertion, NOT an update of existing, let's add some info:
    if (!$doesTaskExist) {
      $newData['created'] = time();
      $newData['last_checked'] = time();
    }

    switch ($doesTaskExist) {
      case (TRUE):
        if (self::$dbConnection->setData($newData)->selectTable(SHERLOCK_TASKS_TABLE)->updateRecords(['id' => $this->id])) {
          self::$taskUpdated = TRUE;
        } else {
          $this->id = 0;
        }
        break;

      case (FALSE):
        $newTaskID = self::$dbConnection->setData($newData)->selectTable(SHERLOCK_TASKS_TABLE)->insertRecord();
        if ($newTaskID) {
          if (self::$dbConnection->setData(['task_id' => $newTaskID])->selectTable(SHERLOCK_MAIN_TABLE)->updateRecords($condition)) {
            self::$taskCreated = TRUE;
            $this->id = $newTaskID;
          }
        }
        break;
    }

    //Initialize some object properties, if save has been successfull:
    //TODO: Maybe remove "modified" field from tasks table as not used?
    if ($this->id) {
      $this->modified = time();
    }

    return $this->id;
  }

  //Delete task if it exists:
  public function delete(int $taskID, bool $ignoreOwnership = FALSE): bool {
    if ($taskID <= 0) {return FALSE;}

    $condition['task_id'] = $taskID;

    if ($ignoreOwnership === FALSE) {
      $condition['uid'] = self::$uid;
    }

    //Attempt to load saved search by given condition (maybe we don't have access to it!):
    $loadAttempt = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);
    if (count($loadAttempt) > 1 || empty($loadAttempt)) {
      //If we got > 1 task records - something went wrong, so it's better to do nothing;
      //If we got empty array it does mean that current user does not own requested record.
      return FALSE;
    }

    if (self::$dbConnection->setData(['task_id' => 0])->selectTable(SHERLOCK_MAIN_TABLE)->updateRecords($condition)) {
      //If update records in main table has been successful (read: we have enough rights), we can proceed:
      self::$searchUpdated = TRUE;
      if (self::$dbConnection->selectTable(SHERLOCK_TASKS_TABLE)->deleteRecords(['id' => $taskID])) {
        self::$taskDeleted = TRUE;
        return TRUE;
      }
    }

    return FALSE;
  }

  public function load(int $taskID, bool $ignoreOwnership = FALSE): ?iSherlockEntity {
    self::resetFlags();

    $condition['task_id'] = $taskID;

    if ($ignoreOwnership === FALSE) {
      $condition['uid'] = self::$uid;
    }

    //Attempt to load saved search by given condition (maybe we don't have access to it!):
    $loadAttempt = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);
    if (count($loadAttempt) > 1 || empty($loadAttempt)) {
      //If we got > 1 task records - something went wrong, so it's better to do nothing;
      //If we got empty array it does mean that current user does not own requested record.
      return null;
    }

    $savedSearchRecord = array_shift($loadAttempt);
    $taskID = isset($savedSearchRecord['task_id']) ? intval($savedSearchRecord['task_id']) : 0;

    $loadTaskAttempt = self::$dbConnection->selectTable(SHERLOCK_TASKS_TABLE)->selectRecords(['id' => $taskID], 'id', TRUE);
    if (empty($loadTaskAttempt)) {
      return null;
    }

    $requestedTask = array_shift($loadTaskAttempt);

    if (empty($requestedTask)) {
      return null;
    }

    $this->id = intval($requestedTask['id']);
    $this->task_essence = unserialize($requestedTask['serialized_task']);
    $this->created = intval($requestedTask['created']);
    $this->modified = intval($requestedTask['modified']);
    $this->last_checked = intval($requestedTask['last_checked']);
    $this->active_to = intval($requestedTask['active_to']);

    return $this;
  }

  /**
   * @return int
   */
  public function getId(): int {
    return $this->id;
  }

  /**
   * @return string
   */
  public function getTaskEssence(): array {
    return $this->task_essence;
  }

  /**
   * @return int
   */
  public function getCreated(): int {
    return $this->created;
  }

  /**
   * @return int
   */
  public function getModified(): int {
    return $this->modified;
  }

  /**
   * @return int
   */
  public function getLastChecked(): int {
    return $this->last_checked;
  }

  /**
   * @return int
   */
  public function getActiveTo(): int {
    return $this->active_to;
  }

  /**
   * @param int $last_checked
   */
  public function setLastChecked(int $last_checked): void {
    $this->last_checked = $last_checked;
  }
}
