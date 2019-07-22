<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-07-21
 * Time: 16:55
 */

namespace Drupal\sherlock_d8\CoreClasses\Exceptions;

class InvalidInputData extends \Exception {
  private $errorDetails;

  public function __construct(string $errorDetails) {
    parent::__construct('Invalid input data: ' . $errorDetails);
    $this->errorDetails = $errorDetails;
  }
}
