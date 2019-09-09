<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-08-15
 * Time: 20:04
 */

namespace Drupal\sherlock_d8\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sherlock_d8\CoreClasses\SherlockDirectory\SherlockDirectory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockSearchEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity\SherlockTrouvailleEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockMailer\SherlockMailer;
use Drupal\sherlock_d8\Controller\MarketFetchController;

/**
 * @QueueWorker(
 *   id = "pending_user_requests",
 *   title = @Translation("Pending user requests"),
 *   cron = {"time" = 25}
 * )
 */
class UserRequestHandler extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  /**
   * @var DatabaseManager $dbConnection
   */
  protected $dbConnection = null; // Will be injected in constructor from service container

  /**
   * @var MarketFetchController $marketFetchController
   */
  protected $marketFetchController = null; // Will be injected in constructor from service container

  /**
   * @var SherlockMailer $sherlockMailer
   */
  protected $sherlockMailer = null; // Will be injected in constructor from service container

  /**
   * @var LoggerInterface $logger
   */
  protected $logger = null; // Will be injected in constructor from service container

  /**
   * @var iSherlockTaskEntity $taskEntity
   */
  protected $taskEntity = null; // Will be instantiated in constructor, TODO: Maybe rewrite this to Dependency Injection?

  public function __construct(array $configuration, string $plugin_id, $plugin_definition, DatabaseManager $dbConnection, MarketFetchController $marketFetchController, SherlockMailer $sherlockMailer, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dbConnection = $dbConnection;
    $this->marketFetchController = $marketFetchController;
    $this->sherlockMailer = $sherlockMailer;
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
     * @var MarketFetchController $marketFetchController
     */
    $marketFetchController = $container->get('sherlock_d8.market_fetch_controller');

    /**
     * @var SherlockMailer $sherlockMailer
     */
    $sherlockMailer = $container->get('sherlock_d8.mailer');

    /**
     * @var LoggerInterface $logger
     */
    $logger = $container->get('logger.factory')->get('sherlock_d8');

    return new static($configuration, $plugin_id, $plugin_definition, $dbConnection, $marketFetchController, $sherlockMailer, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!is_array($data) || !isset($data['user_id']) || !isset($data['task_id'])) {
      //We can't process this item, because input data is not valid.
      //TODO: Consider what to do with it...
      return;
    }

    $userID = $data['user_id'];
    $taskID = $data['task_id'];

    /**
     * @var iSherlockTaskEntity $taskEntity
     */
    $taskEntity = SherlockEntity::getInstance('TASK', $userID, $this->dbConnection);
    $taskEntity->load($taskID);
    $taskEssence = $taskEntity->getTaskEssence();

    $constructedUrlsCollection = $taskEssence['constructed_urls_collection'];
    $priceFrom = $taskEssence['price_from'];
    $priceTo = $taskEssence['price_to'];

    $currentTask_ACTUAL_results = [];
    $currentTask_NEW_results = [];

    $url_Hashpool = [];
    $urlprice_Hashpool = [];
    $hashesOfAlreadyExistingRecords = array_keys(SherlockTrouvailleEntity::getRecordsForSpecifiedTask($taskID, 'url_price_hash'));

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
      SherlockTrouvailleEntity::deleteUnmatched($taskID, 'url_price_hash', $urlprice_Hashpool);
    }

    //-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-* HERE IS PERFECT MOMENT TO SEND EMAIL -*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*
    $newResultsNumber = 0;
    $allResultsNumber = 0;

    foreach ($currentTask_NEW_results as $marketID => $resultsForGivenMarket) {
      $newResultsNumber += count($resultsForGivenMarket);
    }
    unset($marketID, $resultsForGivenMarket);

    foreach ($currentTask_ACTUAL_results as $marketID => $resultsForGivenMarket) {
      $allResultsNumber += count($resultsForGivenMarket);
    }
    unset($marketID, $resultsForGivenMarket);

    //If EOL of task minus current time is LESS than 24h, we consider this is last run of this task:
    $thisIsLastMessage = ($taskEntity->getActiveTo() - time()) <= 24*60*60 ? TRUE : FALSE;

    /**
     * @var SherlockSearchEntity $searchEntity
     */
    $searchEntity = SherlockEntity::getInstance('SEARCH', $userID, $this->dbConnection);
    $searchEntity->loadByTaskID($taskID);

    $userAccount = \Drupal\user\Entity\User::load($userID);
    $to = $userAccount->getEmail();
    $userName = $userAccount->getAccountName();

    //TODO: Refactor MarketReference, include SherlockDirectory into it (and implement abstract factory)
    $fleamarketObjects = SherlockDirectory::getAvailableFleamarkets(TRUE);

    $new_results = [];
    foreach ($currentTask_NEW_results as $mid => $mResults) {
      $new_results[$fleamarketObjects[$mid]::getMarketName()] = $mResults; //Make a new array with results, but keys are not id's (skl) but human-friendly fleamarket names (Skylots)
    }
    unset($mid, $mResults);

    $all_results = [];
    foreach ($currentTask_ACTUAL_results as $mid => $mResults) {
      $all_results[$fleamarketObjects[$mid]::getMarketName()] = $mResults;
    }
    unset($mid, $mResults);

    $renderable = [
      '#theme' => 'scheduled_email_with_search_results',
      '#number_of_new' => $newResultsNumber,
      '#number_of_all' => $allResultsNumber,
      '#user_name' => $userName,
      '#search_name' => $searchEntity->getName(),
      '#new_results' => $new_results,
      '#all_results' => $all_results,
      '#this_is_last_message' => $thisIsLastMessage,
    ];

    $subject = 'Today results for task @task_name: new - [@new_items_num]; all - [@all_items_num].';
    $subjVars = [
      '@task_name' => $searchEntity->getName(),
      '@new_items_num' => $newResultsNumber,
      '@all_items_num' => $allResultsNumber
    ];

    $this->sherlockMailer->composeMail('sherlock_d8', 'scheduled_task_completed', $userID, $subject, $subjVars, '', [], $renderable, 'text/html');
    $sendingResult = $this->sherlockMailer->sendMail();

    //-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*

    if ($sendingResult === TRUE) {
      //Select all records with current taskID (already existing in DB at the moment) and set them IS_NEW flag to 0/FALSE:
      SherlockTrouvailleEntity::markAsNotNew($taskID);

      //FINALLY, insert new records:
      $rowsInsertedNum = SherlockTrouvailleEntity::insertMultiple($userID, $taskID, $currentTask_NEW_results);

      //Update last_checked timestamp, so will not touch this task anymore next 24h:
      $taskEntity->setLastChecked(time());
      $taskEntity->save();

      $this->logger->info('Task #@tid run completed successfully. Mail notification sent to @usermail.', ['@tid' => $taskID, '@usermail' => $to]);
    } else {
      $this->logger->error('Can\'t sent mail to @usermail. User ID = @uid, task ID = @tid', ['@usermail' => $to, '@uid' => $userID, '@tid' => $taskID]);
    }
  }
}
