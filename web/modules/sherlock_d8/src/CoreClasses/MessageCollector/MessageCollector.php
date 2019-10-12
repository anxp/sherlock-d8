<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-10-11
 * Time: 19:50
 */

namespace Drupal\sherlock_d8\CoreClasses\MessageCollector;

class MessageCollector {
  private $instance = null;

  public function __construct() {
    $this->instance = MessageCollectorSingleton::getInstance();
  }

  public function msgCollectorObject() {
    return $this->instance;
  }
}