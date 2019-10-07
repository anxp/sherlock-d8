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

//use Drupal\sherlock_d8\CoreClasses\Exceptions\UnexpectedProcessInterruption;
//use Drupal\Core\Queue\RequeueException;

/**
 * @QueueWorker(
 *   id = "pending_user_requests",
 *   title = @Translation("Pending user requests"),
 *   cron = {"time" = 50}
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

    $timeStampBeforeExecution = time();

    $newResultsNumber = $this->taskLauncher->runTask($userID, $taskID, TRUE);

    $spentTime = time() - $timeStampBeforeExecution;

    $this->logger->info('Task #@tid executed in @time sec. as queue item. Number of new results for this task: [@newres]. Owner of task user #@uid.', ['@tid' => $taskID, '@newres' => $newResultsNumber, '@uid' => $userID, '@time' => $spentTime]);
  }
}
