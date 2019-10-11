<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-10-10
 * Time: 21:30
 */

namespace Drupal\sherlock_d8\CoreClasses\MessageCollector;

class MessageCollector {
  static $instance = null;

  protected $highMessages = [];
  protected $normalMessages = [];
  protected $lowMessages = [];

  private function __construct() {
  }

  public static function getInstance() {
    if (empty(self::$instance)) {
      self::$instance = new self();

      return self::$instance;
    }

    return self::$instance;
  }

  /**
   * @param string $message - the body of the message, required.
   * @param string $type - type of message, required. Standard Drupal types aka 'error' or 'status' etc.
   * @param string $priority == 'H' || 'N' || 'L', for High, Normal, and Low. Other priority values will be ignored and message will NOT be added.
   */
  public function addMessage(string $message, string $type, string $priority = 'N') {
    if ($priority === 'H') {
      $this->highMessages[] = [
        'type' => $type,
        'message' => $message,
      ];
    }

    if ($priority === 'N') {
      $this->normalMessages[] = [
        'type' => $type,
        'message' => $message,
      ];
    }

    if ($priority === 'L') {
      $this->lowMessages[] = [
        'type' => $type,
        'message' => $message,
      ];
    }
  }

  public function getMessagesSorted(int $limit = 0): array {
    if ($limit === 0) {
      return array_merge($this->highMessages, $this->normalMessages, $this->lowMessages);
    }

    return array_slice(array_merge($this->highMessages, $this->normalMessages, $this->lowMessages), 0, $limit);
  }
}
