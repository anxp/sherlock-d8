<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-15
 * Time: 20:04
 */

namespace Drupal\sherlock_d8\Plugin\QueueWorker;

use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\TaskLauncher\TaskLauncher;
use Psr\Log\LoggerInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;

use Drupal\sherlock_d8\CoreClasses\Exceptions\UnexpectedProcessInterruption;
use Drupal\Core\Queue\RequeueException;

/**
 * @QueueWorker(
 *   id = "pending_user_requests",
 *   title = @Translation("Pending user requests"),
 *   cron = {"time" = 120}
 * )
 */
class UserRequestHandler extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  /**
   * @var DatabaseManager $dbConnection
   */
  protected $dbConnection = null;

  /**
   * @var TaskLauncher $taskLauncher
   */
  protected $taskLauncher = null;

  /**
   * @var LoggerInterface $logger
   */
  protected $logger = null; // Will be injected in constructor from service container

  /**
   * @var iSherlockTaskEntity $taskEntity
   */
  protected $taskEntity = null; // Will be instantiated in constructor, TODO: Maybe rewrite this to Dependency Injection?

  public function __construct(array $configuration, string $plugin_id, $plugin_definition, DatabaseManager $dbConnection, TaskLauncher $taskLauncher, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dbConnection = $dbConnection;
    $this->taskLauncher = $taskLauncher;
    $this->logger = $logger;

    $this->taskEntity = SherlockEntity::getInstance('TASK', 0, $this->dbConnection);
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /**
     * @var DatabaseManager $dbConnection
     */
    $dbConnection = $container->get('sherlock_d8.database_manager');

    /**
     * @var TaskLauncher $taskLauncher
     */
    $taskLauncher = $container->get('sherlock_d8.task_launcher');

    /**
     * @var LoggerInterface $logger
     */
    $logger = $container->get('logger.factory')->get('sherlock_d8');

    return new static($configuration, $plugin_id, $plugin_definition, $dbConnection, $taskLauncher, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data)
  {
    if (!is_array($data) || !isset($data['user_id']) || !isset($data['task_id'])) {
      //We can't process this item, because input data is not valid.
      //TODO: Consider what to do with it...
      return;
    }

    $userID = $data['user_id'];
    $taskID = $data['task_id'];

    $newResultsNumber = -1;

    try {
      $newResultsNumber = $this->taskLauncher->runTask($userID, $taskID, TRUE);
    } catch (UnexpectedProcessInterruption $exception) {
      $problemFilePath = $exception->getFile();
      $lineNumber = $exception->getLine();
      $description = $exception->getMessage();

      $this->logger->error('Problem file: ' . $problemFilePath . '<br>' . 'Line number: ' . $lineNumber . '<br>' . 'Error description: ' . $description);

      unset($problemFilePath, $lineNumber, $description);

      //And re-throw other exception in order to requeue current task:
      throw new RequeueException('Task cannot be finished, requeue initiated.');
    }

    $mailNotificationStatus = $this->taskLauncher->getMailNotificationStatus();

    $userAccount = \Drupal\user\Entity\User::load($userID);
    $to = $userAccount->getEmail();

    if ($newResultsNumber >= 0 && $mailNotificationStatus) {
      $this->logger->info('Task #@tid run completed successfully. New results from this task: [@res_number]. Mail notification sent to @usermail.', ['@tid' => $taskID, '@res_number' => $newResultsNumber, '@usermail' => $to]);
    }

    if ($newResultsNumber >= 0 && !$mailNotificationStatus) {
      $this->logger->info('Task #@tid run completed successfully. New results from this task: [@res_number].', ['@tid' => $taskID, '@res_number' => $newResultsNumber]);
    }

    if ($newResultsNumber < 0) {
      $this->logger->error('Error occurred on executing task #@tid. Process interrupted. Check thrown exceptions too.', ['@tid' => $taskID]);
    }
  }
}
