<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-10
 * Time: 21:27
 */
namespace Drupal\sherlock_d8\CoreClasses\TaskLauncher;

use Psr\Log\LoggerInterface;

use Drupal\sherlock_d8\CoreClasses\SherlockMailer\SherlockMailer;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\Controller\MarketFetchController;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockSearchEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity\SherlockTrouvailleEntity;
use Drupal\sherlock_d8\CoreClasses\MarketReference\MarketReference;

use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;

class TaskLauncher implements iTaskLauncher {
  protected $sherlockMailer = null;
  protected $dbConnection = null;
  protected $marketFetchController = null;
  protected $trouvailleEntity = null;

  /**
   * @var iSherlockTaskEntity $taskEntity
   */
  private $taskEntity = null;

  /**
   * @var SherlockSearchEntity $searchEntity
   */
  private $searchEntity = null;
  private $userID = 0;
  private $taskID = 0;
  private $mailSentSuccessfully = FALSE;

  /**
   * @var LoggerInterface $logger
   */
  protected $logger = null;

  public function __construct(SherlockMailer $sherlockMailer, DatabaseManager $dbConnection, MarketFetchController $marketFetchController, SherlockTrouvailleEntity $trouvailleEntity, LoggerInterface $logger) {
    $this->sherlockMailer = $sherlockMailer;
    $this->dbConnection = $dbConnection;
    $this->marketFetchController = $marketFetchController;
    $this->trouvailleEntity = $trouvailleEntity;
    $this->logger = $logger;
  }

  /**
   * @param int $userID
   * @param int $taskID
   * @param bool $sendEmailNotification
   * @return int
   * @throws InvalidInputData
   */
  public function runTask(int $userID, int $taskID, bool $sendEmailNotification = TRUE): int {

    $this->taskEntity = SherlockEntity::getInstance('TASK', $userID, $this->dbConnection);
    $this->taskEntity->load($taskID);

    $this->searchEntity = SherlockEntity::getInstance('SEARCH', $userID, $this->dbConnection);
    $this->searchEntity->loadByTaskID($taskID);

    //Let's init private class properties:
    $this->userID = $userID;
    $this->taskID = $taskID;
    $this->mailSentSuccessfully = FALSE;

    $taskEssence = $this->taskEntity->getTaskEssence();

    //If task appears empty (by unknown reason) -> just do nothing, but finish process as normal task.
    //This is very important part, because it prevents 'ghosts' of nonexistent\empty tasks to create bottleneck in Queue
    if (empty($taskEssence)) {
      return 0;
    }

    $constructedUrlsCollection = $taskEssence['constructed_urls_collection'];
    $priceFrom = $taskEssence['price_from'];
    $priceTo = $taskEssence['price_to'];

    $currentTask_ACTUAL_results = [];
    $currentTask_NEW_results = [];

    $url_Hashpool = [];
    $urlprice_Hashpool = [];
    $hashesOfAlreadyExistingRecords = array_keys($this->trouvailleEntity->getRecordsForSpecifiedTask($taskID, 'url_price_hash'));

    foreach ($constructedUrlsCollection as $marketID => $urlsSetForGivenMarket) {
      //Request and get results from remote resources:
      $currentMarketResults = $this->marketFetchController->fetchMarketCore($marketID, $urlsSetForGivenMarket, $priceFrom, $priceTo);

      //Check and filter gotten results. We'll split results into 2 arrays -
      //$currentTask_ACTUAL_results (all results gotten from remote) and $currentTask_NEW_results (results, which are not exist in our DB):
      foreach ($currentMarketResults as $numericKey => $oneResult) {

        //Store result hashes in separate arrays:
        $url_Hashpool[] = $oneResult['url_hash'];
        $urlprice_Hashpool[] = $oneResult['url_price_hash'];

        if(!in_array($oneResult['url_price_hash'], $hashesOfAlreadyExistingRecords)) {
          //We've caught NEW item! Let's store it in array for new items only:
          $currentTask_NEW_results[$marketID][] = $oneResult;
        }
      }
      unset($numericKey, $oneResult);

      $currentTask_ACTUAL_results[$marketID] = $currentMarketResults;
    }
    unset($marketID, $urlsSetForGivenMarket);

    //Actualize data in our DB: check already existing records, and DELETE records, which are NOT IN $currentTask_ACTUAL_results:
    if (!empty($urlprice_Hashpool)) {
      $this->trouvailleEntity->deleteUnmatched($taskID, 'url_price_hash', $urlprice_Hashpool);
    }

    //-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-* HERE IS PERFECT MOMENT TO SEND EMAIL -*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*
    if ($sendEmailNotification) {
      $this->mailSentSuccessfully = $this->sendMailNotification($currentTask_NEW_results, $currentTask_ACTUAL_results);
    }
    //-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*

    //Either mail sent successfully, OR not ->
    //we should finish current task (mark EXISTING results as old, and insert new (automatically marked as new)):

    //Select all records with current taskID (already existing in DB at the moment) and set them IS_NEW flag to 0/FALSE:
    $this->trouvailleEntity->markAsNotNew($taskID);

    //FINALLY, insert new records:
    $rowsInsertedNum = $this->trouvailleEntity->insertMultiple($userID, $taskID, $currentTask_NEW_results);

    //Update last_checked timestamp, so will not touch this task anymore next 24h:
    $this->taskEntity->setLastChecked(time());
    $this->taskEntity->save();

    //Log this event to watchdog:
    $userAccount = \Drupal\user\Entity\User::load($userID);
    $to = $userAccount->getEmail();

    if ($rowsInsertedNum >= 0 && $this->mailSentSuccessfully) {
      $this->logger->info('Task #@tid run completed successfully. New results from this task: [@res_number]. Mail notification sent to @usermail.', ['@tid' => $taskID, '@res_number' => $rowsInsertedNum, '@usermail' => $to,]);
    }

    if ($rowsInsertedNum >= 0 && $sendEmailNotification === FALSE) {
      $this->logger->info('Task #@tid run completed successfully. New results from this task: [@res_number]. Mail notification HAS NOT been requested.', ['@tid' => $taskID, '@res_number' => $rowsInsertedNum,]);
    }

    if ($rowsInsertedNum >= 0 && $sendEmailNotification === TRUE && $this->mailSentSuccessfully === FALSE) {
      $this->logger->error('Task #@tid run completed successfully. New results from this task: [@res_number]. But REQUESTED mail notification HAS NOT been sent (to @usermail).', ['@tid' => $taskID, '@res_number' => $rowsInsertedNum, '@usermail' => $to,]);
    }

    return $rowsInsertedNum;
  }

  protected function sendMailNotification(array $newResults, array $allResults): bool {
    $newResultsNumber = 0;
    $allResultsNumber = 0;

    foreach ($newResults as $marketID => $resultsForGivenMarket) {
      $newResultsNumber += count($resultsForGivenMarket);
    }
    unset($marketID, $resultsForGivenMarket);

    foreach ($allResults as $marketID => $resultsForGivenMarket) {
      $allResultsNumber += count($resultsForGivenMarket);
    }
    unset($marketID, $resultsForGivenMarket);

    //If EOL of task minus current time is LESS than 24h, we consider this is last run of this task:
    $thisIsLastMessage = ($this->taskEntity->getActiveTo() - time()) <= 24*60*60 ? TRUE : FALSE;

    $userAccount = \Drupal\user\Entity\User::load($this->userID);
    $userName = $userAccount->getAccountName();

    $fleamarketObjects = MarketReference::getAvailableFleamarkets(TRUE);

    $new_results = [];
    foreach ($newResults as $mid => $mResults) {
      $new_results[$fleamarketObjects[$mid]::getMarketName()] = $mResults; //Make a new array with results, but keys are not id's (skl) but human-friendly fleamarket names (Skylots)
    }
    unset($mid, $mResults);

    $all_results = [];
    foreach ($allResults as $mid => $mResults) {
      $all_results[$fleamarketObjects[$mid]::getMarketName()] = $mResults;
    }
    unset($mid, $mResults);

    $renderable = [
      '#theme' => 'scheduled_email_with_search_results',
      '#number_of_new' => $newResultsNumber,
      '#number_of_all' => $allResultsNumber,
      '#user_name' => $userName,
      '#search_name' => $this->searchEntity->getName(),
      '#new_results' => $new_results,
      '#all_results' => $all_results,
      '#this_is_last_message' => $thisIsLastMessage,
    ];

    $subject =  '@current_date, search results for @task_name: new - [@new_items_num]; all - [@all_items_num].';
    $subjVars = [
      '@current_date' => date('j \of F'),
      '@task_name' => $this->searchEntity->getName(),
      '@new_items_num' => $newResultsNumber,
      '@all_items_num' => $allResultsNumber
    ];

    $this->sherlockMailer->composeMail('sherlock_d8', 'scheduled_task_completed', $this->userID, $subject, $subjVars, '', [], $renderable, 'text/html');
    $sendingResult = $this->sherlockMailer->sendMail();
    return $sendingResult;
  }

  public function getMailNotificationStatus():bool {
    return $this->mailSentSuccessfully;
  }
}
