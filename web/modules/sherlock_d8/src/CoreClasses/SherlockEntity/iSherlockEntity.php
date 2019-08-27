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
