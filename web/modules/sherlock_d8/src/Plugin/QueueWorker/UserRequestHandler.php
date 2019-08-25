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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockTrouvailleEntity\SherlockTrouvailleEntity;
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
   * @var iSherlockTaskEntity $taskEntity
   */
  protected $taskEntity = null; // Will be instantiated in constructor, TODO: Maybe rewrite this to Dependency Injection?

  public function __construct(array $configuration, string $plugin_id, $plugin_definition, DatabaseManager $dbConnection, MarketFetchController $marketFetchController) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dbConnection = $dbConnection;
    $this->marketFetchController = $marketFetchController;

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

    return new static($configuration, $plugin_id, $plugin_definition, $dbConnection, $marketFetchController);
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

    $this->taskEntity->load($taskID, TRUE);

    $taskEssence = $this->taskEntity->getTaskEssence();

    $constructedUrlsCollection = $taskEssence['constructed_urls_collection'];
    $priceFrom = $taskEssence['price_from'];
    $priceTo = $taskEssence['price_to'];

    $resultsForCurrentTask = []; //Finally, we will got here ONLY NEW results (all old results, which are already in DB will be filtered out).
    $url_Hashpool = [];
    $urlprice_Hashpool = [];
    $hashesOfAlreadyExistingRecords = array_keys(SherlockTrouvailleEntity::getRecordsForSpecifiedTask($taskID, 'url_price_hash'));

    foreach ($constructedUrlsCollection as $marketID => $urlsSetForGivenMarket) {
      //Request and get results from remote resources:
      $currentMarketResults = $this->marketFetchController->fetchMarketCore($marketID, $urlsSetForGivenMarket, $priceFrom, $priceTo);

      //Check and filter gotten results. If record already exists in DB, delete it from gotten results array -> so we will insert only NEW results in DB:
      foreach ($currentMarketResults as $numericKey => &$oneResult) {
        //Store result hashes in separate arrays:
        $urlHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $oneResult['link']);
        $urlPriceHash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $oneResult['link'] . $oneResult['price_value']);

        $url_Hashpool[] = $urlHash;
        $urlprice_Hashpool[] = $urlPriceHash;

        if (in_array($urlPriceHash, $hashesOfAlreadyExistingRecords)) { //Remove item from array if it already exists in DB!
          unset($currentMarketResults[$numericKey]);
        } else { //If item is not in array, we consider this is NEW item (old item with changed price also considered as new),
                 //so let's upgrade it with new info (add hashes, because we need hashes to properly insert in table):
          $oneResult['url_hash'] = $urlHash;
          $oneResult['url_price_hash'] = $urlPriceHash;
        }
      }
      unset($numericKey, $oneResult);

      //Reindex currentMarketResults array:
      $currentMarketResults = array_values($currentMarketResults);

      $resultsForCurrentTask[$marketID] = $currentMarketResults;
    }
    unset($marketID, $urlsSetForGivenMarket);

    //------------- Now it's time to write new search results to DB. We will do this in 3 steps: -----------------------

    //1st, we need to check already existing records in DB, and DELETE records, which are NOT IN $resultsForCurrentTask:
    if (!empty($urlprice_Hashpool)) {
      SherlockTrouvailleEntity::deleteUnmatched($taskID, 'url_price_hash', $urlprice_Hashpool);
    }

    //2nd, select all records with current taskID (already existing in DB at the moment) and set them IS_NEW flag to 0/FALSE:
    SherlockTrouvailleEntity::markAsNotNew($taskID);

    //FINALLY, insert new records:
    $rowsInsertedNum = SherlockTrouvailleEntity::insertMultiple($userID, $taskID, $resultsForCurrentTask);

    //------------- New search results for current task are now in DB! -------------------------------------------------

    //Update last_checked timestamp, so will not touch this task anymore next 24h:
    $this->taskEntity->setLastChecked(time());
    $this->taskEntity->save();
  }

}
