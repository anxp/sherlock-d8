<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-07-30
 * Time: 21:01
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

use Drupal\Core\Form\FormStateInterface;
use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;

class SherlockSearchEntity extends SherlockEntity implements iSherlockSearchEntity {
  //These variables represent fields in SHERLOCK_MAIN_TABLE (except uid (user ID) - it declared as static variable at parent class):
  protected $id = 0;
  protected $created = 0;
  protected $modified = 0;
  protected $name = '';
  protected $name_hash = '';
  protected $form_structure = [];
  protected $form_values = [];
  protected $task_id = 0;

  //These variables reflects checkbox states (form step 2):
  protected $overwriteExisting = FALSE;
  protected $subscribeForUpdates = FALSE;

  public function fillObjectWithFormData(FormStateInterface $form_state): void {
    self::initializeSharedVariables($form_state);

    //Overwrite existing checkbox:
    $this->overwriteExisting = boolval($form_state->getValue(['save_search_block', 'overwrite_existing_search']));

    //Subscribe for updates checkbox:
    $this->subscribeForUpdates = boolval($form_state->getValue(['save_search_block', 'subscribe_to_updates']));

    //Get structure of user-configured block with keywords (this is only structure, values we'll get in next step...):
    $this->form_structure = $form_state->get('user_added');

    //Get values (not only of user-configured block with keywords but of whole form):
    $this->form_values = $form_state->get('form_state_values_snapshot');

    //Get user-given name for search:
    $this->name = $form_state->getValue(['save_search_block', 'search_name_textfield']);

    //Calculate md5 hash of name of the search:
    $this->name_hash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $this->name);
  }

  /**
   * @throws InvalidInputData
   */
  public function save(): int {
    $name = self::$shared_form_state->getValue(['save_search_block', 'search_name_textfield']);
    $nameHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $name);

    $condition = [
      'uid' => self::$uid,
      'name_hash' => $nameHash,
    ];

    $loadAttempt = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);

    $searchRecord = array_shift($loadAttempt);

    $doesRecordExist = isset($searchRecord['id']) ? intval($searchRecord['id']) : 0;
    $taskID = isset($searchRecord['task_id']) ? intval($searchRecord['task_id']) : 0;

    unset($loadAttempt);

    $newData = [
      'modified' => time(),
      'serialized_form_structure' => serialize($this->form_structure),
      'serialized_form_values' => serialize($this->form_values),
    ];

    //If this is new insertion, NOT an update of existing, let's add some info:
    if (!$doesRecordExist) {
      $newData['uid'] = self::$uid;
      $newData['created'] = time();
      $newData['name'] = $this->name;
      $newData['name_hash'] = $this->name_hash;
    }

    $recordID = 0;

    switch (TRUE) {
      case ($doesRecordExist && $this->overwriteExisting): //If record exists and SHOULD be overwriten -> we do UPDATE method:
        if (self::$dbConnection->setData($newData)->selectTable(SHERLOCK_MAIN_TABLE)->updateRecords($condition)) {
          self::$searchUpdated = TRUE;
          $recordID = $doesRecordExist;
        }
        break;

      case (!$doesRecordExist): //If record does not exist, just insert new record, ignoring value of checkbox (INSERT method):
        $recordID = self::$dbConnection->setData($newData)->selectTable(SHERLOCK_MAIN_TABLE)->insertRecord();
        self::$searchCreated = $recordID > 0 ? TRUE : FALSE;
        break;

      default:
        throw new InvalidInputData('Invalid combination of $doesRecordExist and $overwriteExisting. $doesRecordExist == TRUE, but $overwriteExisting == FALSE. We can\'t continue doing it this way. This situation should be filtered out at validation step.');
    }

    //If search has been successfully created OR updated, let's update related task too:
    if (self::$searchUpdated || self::$searchCreated) {
      $taskEntity = new SherlockTaskEntity(self::$uid, self::$dbConnection);

      if ($this->subscribeForUpdates) {
        $taskEntity->fillObjectWithFormData(self::$shared_form_state);
        $taskID = $taskEntity->save();
      } else {
        $taskEntity->delete($taskID);
      }
    }

    //Initialize some object properties, if save has been successful:
    if ($recordID) {
      $this->id = $recordID;
      $this->modified = time();
      $this->task_id = $taskID;
    }

    return $recordID;
  }

  public function delete(int $searchID, bool $ignoreOwnership = FALSE): bool {
    $condition['id'] = $searchID;

    if ($ignoreOwnership === FALSE) {
      $condition['uid'] = self::$uid;
    }

    //First, we need to check if search has attached task to it, and if it does - delete this task first:
    $requestedSearch = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);
    $requestedSearch = array_shift($requestedSearch);
    $taskID = intval($requestedSearch['task_id']);

    if ($taskID > 0) {
      //We found attached task, so let's delete it:
      $attachedTaskObject = new SherlockTaskEntity(self::$uid, self::$dbConnection);
      $attachedTaskObject->delete($taskID, $ignoreOwnership);
    }

    //Second, delete search record from main table:
    $numRowsDeleted = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->deleteRecords($condition);

    //Set flag, which indicates, has record been deleted or not:
    self::$searchDeleted = $numRowsDeleted > 0 ? TRUE : FALSE;

    return (bool) $numRowsDeleted;
  }

  public function load(int $searchID, bool $ignoreOwnership = FALSE): ?iSherlockEntity {
    self::resetFlags();

    $condition['id'] = $searchID;

    if ($ignoreOwnership === FALSE) {
      $condition['uid'] = self::$uid;
    }

    $loadAttempt = self::$dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($condition, 'id', TRUE);
    if (count($loadAttempt) > 1 || empty($loadAttempt)) {
      //If we got > 1 task records - something went wrong, so it's better to do nothing;
      //If we got empty array it does mean that current user does not own requested record.
      return null;
    }

    $requestedSearch = array_shift($loadAttempt);

    if (empty($requestedSearch)) {
      return null;
    }

    $this->id = intval($requestedSearch['id']);
    $this->created = intval($requestedSearch['created']);
    $this->modified = intval($requestedSearch['modified']);
    $this->name = $requestedSearch['name'];
    $this->name_hash = $requestedSearch['name_hash'];
    $this->form_structure = unserialize($requestedSearch['serialized_form_structure']);
    $this->form_values = unserialize($requestedSearch['serialized_form_values']);
    $this->task_id = intval($requestedSearch['task_id']);

    return $this;
  }

  /**
   * @return int
   */
  public function getId(): int {
    return $this->id;
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
   * @return string
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * @return string
   */
  public function getNameHash(): string {
    return $this->name_hash;
  }

  /**
   * @return array
   */
  public function getFormStructure(): array {
    return $this->form_structure;
  }

  /**
   * @return array
   */
  public function getFormValues(): array {
    return $this->form_values;
  }

  /**
   * @return int
   */
  public function getTaskId(): int {
    return $this->task_id;
  }
}
