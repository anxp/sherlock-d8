<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-09
 * Time: 22:17
 */

namespace Drupal\sherlock_d8\CoreClasses\SherlockEntity;

interface iSherlockSearchEntity extends iSherlockEntity {
  public function getId(): int;
  public function getCreated(): int;
  public function getModified(): int;
  public function getName(): string;
  public function getNameHash(): string;
  public function getFormStructure(): array;
  public function getFormValues(): array;
  public function getTaskId(): int;
}
