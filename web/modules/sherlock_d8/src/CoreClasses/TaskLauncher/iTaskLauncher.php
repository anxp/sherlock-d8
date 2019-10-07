<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-10
 * Time: 21:17
 */

namespace Drupal\sherlock_d8\CoreClasses\TaskLauncher;

use Psr\Log\LoggerInterface;

use Drupal\sherlock_d8\CoreClasses\SherlockMailer\SherlockMailer;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\Controller\MarketFetchController;
use Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity\SherlockTrouvailleEntity;

use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;

interface iTaskLauncher {
  public function __construct(SherlockMailer $sherlockMailer, DatabaseManager $dbConnection, MarketFetchController $marketFetchController, SherlockTrouvailleEntity $trouvailleEntity, LoggerInterface $logger);

  /**
   * @param int $userID
   * @param int $taskID
   * @param bool $sendEmailNotification
   * @return int - a number of inserted new records into table
   * @throws InvalidInputData
   */
  public function runTask(int $userID, int $taskID, bool $sendEmailNotification): int;
  public function getMailNotificationStatus():bool;
}
