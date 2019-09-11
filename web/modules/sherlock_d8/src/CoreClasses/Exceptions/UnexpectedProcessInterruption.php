<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-10
 * Time: 22:57
 */
namespace Drupal\sherlock_d8\CoreClasses\Exceptions;

class UnexpectedProcessInterruption extends \Exception {
  private $errorDetails;

  public function __construct(string $errorDetails) {
    parent::__construct('Workflow interrupted and cannot be continued: ' . $errorDetails);
    $this->errorDetails = $errorDetails;
  }
}
