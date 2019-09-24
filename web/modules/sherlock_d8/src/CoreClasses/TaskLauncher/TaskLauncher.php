<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-10
 * Time: 21:27
 */
namespace Drupal\sherlock_d8\CoreClasses\TaskLauncher;

use Drupal\sherlock_d8\CoreClasses\SherlockMailer\SherlockMailer;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\Controller\MarketFetchController;

use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockSearchEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity\SherlockTrouvailleEntity;
use Drupal\sherlock_d8\CoreClasses\MarketReference\MarketReference;

use Drupal\sherlock_d8\CoreClasses\Exceptions\UnexpectedProcessInterruption;
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

  public function __construct(SherlockMailer $sherlockMailer, DatabaseManager $dbConnection, MarketFetchController $marketFetchController, SherlockTrouvailleEntity $trouvailleEntity) {
    $this->sherlockMailer = $sherlockMailer;
    $this->dbConnection = $dbConnection;
    $this->marketFetchController = $marketFetchController;
    $this->trouvailleEntity = $trouvailleEntity;
  }

  /**
   * @param int $userID
   * @param int $taskID
   * @param bool $sendEmailNotification
   * @return int
   * @throws UnexpectedProcessInterruption | InvalidInputData
   */
  public function runTask(int $userID, int $taskID, $sendEmailNotification = TRUE): int {

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
      foreach ($currentMarketResults as $numericKey => &$oneResult) {
        //Store result hashes in separate arrays:
        $urlHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $oneResult['link']);
        $urlPriceHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $oneResult['link'] . $oneResult['price_value']);

        $url_Hashpool[] = $urlHash;
        $urlprice_Hashpool[] = $urlPriceHash;

        //These elements with hashes didn't exist before, we add them here dynamically:
        $oneResult['url_hash'] = $urlHash;
        $oneResult['url_price_hash'] = $urlPriceHash;

        if(!in_array($urlPriceHash, $hashesOfAlreadyExistingRecords)) {
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

    //If mail sent successfully, OR we didn't requested to send email:
    if ($this->mailSentSuccessfully === TRUE || $sendEmailNotification === FALSE) {
      //Select all records with current taskID (already existing in DB at the moment) and set them IS_NEW flag to 0/FALSE:
      $this->trouvailleEntity->markAsNotNew($taskID);

      //FINALLY, insert new records:
      $rowsInsertedNum = $this->trouvailleEntity->insertMultiple($userID, $taskID, $currentTask_NEW_results);

      //Update last_checked timestamp, so will not touch this task anymore next 24h:
      $this->taskEntity->setLastChecked(time());
      $this->taskEntity->save();

      return $rowsInsertedNum;
    } else {
      throw new UnexpectedProcessInterruption('Requested notification email cannot be send, so we can\'t properly complete task executing process.');
    }
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

    $subject = 'Today results for task @task_name: new - [@new_items_num]; all - [@all_items_num].';
    $subjVars = [
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
