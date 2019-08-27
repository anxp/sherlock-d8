<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-10
 * Time: 21:52
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

interface iSherlockTaskEntity extends iSherlockEntity {
  public function getId(): int;
  public function getTaskEssence(): array;
  public function getCreated(): int;
  public function getLastChecked(): int;
  public function getActiveTo(): int;
  public function setLastChecked(int $timestamp);
  public function load(int $taskID, bool $ignoreOwnership = FALSE): ?iSherlockTaskEntity;
}
