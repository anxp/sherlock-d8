<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-09
 * Time: 07:58
 */
namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\Core\Form\FormStateInterface;

interface iSherlockEntity {
  const SHERLOCK_SEARCH_SAVED_NOTIFICATION = 'Search settings and parameters have been successfully saved.';
  const SHERLOCK_SEARCH_UPDATED_NOTIFICATION = 'Search has been updated.';
  const SHERLOCK_SEARCH_DELETED_NOTIFICATION = 'Search has been deleted.';

  const SHERLOCK_TASK_SAVED_NOTIFICATION = 'New task has been successfully added to schedule.';
  const SHERLOCK_TASK_UPDATED_NOTIFICATION = 'Task has been updated.';
  const SHERLOCK_TASK_DELETED_NOTIFICATION = 'Scheduled task has been deleted.';

  /**
   * This method instantiates object of needed class, depending on $entityType argument.
   * @param string $entityType - type of "entity" to handle. Possible values: TASK || SEARCH. This is not entity in Drupal terminology, and maybe will be refactored to one.
   * @param int $userID
   * @param DatabaseManager $dbConnection
   * @return SherlockEntity
   */
  static public function getInstance(string $entityType, int $userID, DatabaseManager $dbConnection): self;
  public function fillObjectWithFormData(FormStateInterface $form_state): void ;
  public function save(): int;
  public function delete(int $searchID, bool $ignoreOwnership = FALSE): bool;

  /**
   * @param int $searchID
   * @param bool $ignoreOwnership
   * @return iSherlockSearchEntity | iSherlockTaskEntity
   */
  public function load(int $searchID, bool $ignoreOwnership = FALSE);
}
