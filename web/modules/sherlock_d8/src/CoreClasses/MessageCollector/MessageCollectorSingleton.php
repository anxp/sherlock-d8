<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-10-10
 * Time: 21:30
 */

namespace Drupal\sherlock_d8\CoreClasses\MessageCollector;

use Drupal\Core\Messenger\MessengerTrait;

class MessageCollectorSingleton {

  use MessengerTrait;

  static $instance = null;

  protected $highMessages = [];
  protected $normalMessages = [];
  protected $lowMessages = [];

  private function __construct() {
  }

  public static function getInstance() {
    if (empty(self::$instance)) {
      self::$instance = new self();
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

  public function displayAllMessages() {
    $messages = $this->getMessagesSorted();

    foreach ($messages as $messageBundle) {
      if (!isset($messageBundle['type']) || !isset($messageBundle['message'])) {continue;}

      switch (TRUE) {
        case ($messageBundle['type'] === 'status'):
          $this->messenger()->addStatus($messageBundle['message']);
          break;

        case ($messageBundle['type'] === 'error'):
          $this->messenger()->addError($messageBundle['message']);
          break;
      }
    }
  }

  protected function getMessagesSorted(int $limit = 0): array {
    if ($limit === 0) {
      return array_merge($this->highMessages, $this->normalMessages, $this->lowMessages);
    }

    return array_slice(array_merge($this->highMessages, $this->normalMessages, $this->lowMessages), 0, $limit);
  }
}
