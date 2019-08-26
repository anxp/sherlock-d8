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
    //-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*

    //Select all records with current taskID (already existing in DB at the moment) and set them IS_NEW flag to 0/FALSE:
    SherlockTrouvailleEntity::markAsNotNew($taskID);

    //FINALLY, insert new records:
    $rowsInsertedNum = SherlockTrouvailleEntity::insertMultiple($userID, $taskID, $currentTask_NEW_results);

    //------------- New search results for current task are now in DB! -------------------------------------------------

    //Update last_checked timestamp, so will not touch this task anymore next 24h:
    $this->taskEntity->setLastChecked(time());
    $this->taskEntity->save();
  }

}
