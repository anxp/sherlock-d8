<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-28
 * Time: 22:57
 */

namespace Drupal\sherlock_d8\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\sherlock_d8\CoreClasses\ItemSniper\{ItemSniper, olx_ItemSniper, bsp_ItemSniper, skl_ItemSniper};
use Drupal\sherlock_d8\CoreClasses\ArrayFiltration_2D\ArrayFiltration_2D;
use Drupal\sherlock_d8\CoreClasses\FileManager\FileManager;
use Drupal\sherlock_d8\CoreClasses\SherlockCacheEntity\SherlockCacheEntity;

class MarketFetchController extends ControllerBase {
  /**
   * @var SherlockCacheEntity $sherlockCache
   */
  protected $sherlockCache = null;

  public function __construct($sherlockCache) {
    $this->sherlockCache = $sherlockCache;
  }

  public static function create(ContainerInterface $container) {
    /**
     * @var SherlockCacheEntity $sherlockCache
     */
    $sherlockCache = $container->get('sherlock_d8.cache_entity');

    return new static($sherlockCache);
  }

  public function fetchMarketCore(string $marketID, array $urlsSetForGivenMarket, int $priceFrom = null, int $priceTo = null): array {
    $itemSniperFullNamespacePath = '\Drupal\sherlock_d8\CoreClasses\ItemSniper\\';

    //Construct Object Name.
    // As a result we will have Fully Qualified Object Name like "\Drupal\sherlock_d8\CoreClasses\ItemSniper\olx_ItemSniper":
    $objName = $itemSniperFullNamespacePath.$marketID . '_ItemSniper';

    $snipeRawResults = [];
    for ($i = 0; $i < count($urlsSetForGivenMarket); $i++) {
      //Try to get cache (if it exists!):
      $hashAsName = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $urlsSetForGivenMarket[$i]);

      $oneQueryResult = $this->sherlockCache->load($hashAsName);

      if (empty($oneQueryResult)) {
        $sniperObject = new $objName($urlsSetForGivenMarket[$i], 5); //Create new object of somemarket_ItemSniper.
        $oneQueryResult = $sniperObject->grabItems();
        unset($sniperObject);

        $this->calculateAndInjectChecksums($oneQueryResult); //Upgrade incoming data with important checksums

        $this->sherlockCache->save($hashAsName, $oneQueryResult);
      }

      $snipeRawResults[$urlsSetForGivenMarket[$i]] = $oneQueryResult;
    }

    $rawResultsFlattened = [];
    foreach ($snipeRawResults as $resultsPerURL) {
      foreach ($resultsPerURL as $oneResult) {
        $rawResultsFlattened[] = $oneResult;
      }
      unset($oneResult);
    }
    unset($resultsPerURL);

    //And finally - in $filteredData we'll have filtered and unique collection of [title, link, price] for given marketID,
    //and this data we'll return to frontend as JSON.
    $filteredData = ArrayFiltration_2D::selectUniqueSubArrays_byField($rawResultsFlattened, 'link');

    //If user specified price range - let's do such filtration!
    $filteredData = ArrayFiltration_2D::selectSubArrays_byFieldValueRange($filteredData, 'price_value', $priceFrom, $priceTo);

    //At the very last step - cache images (or use already cached if they are exists):
    foreach ($filteredData as &$value) {
      $pic = new FileManager('public://sherlock/img_cache');
      $picExtUrl = $value['thumbnail'];
      $locUrl = $pic->loadRemoteFile($picExtUrl)->saveFileManaged()->getLocalFileUrl();
      $value['thumbnail'] = $locUrl ? $locUrl : '';
      unset($pic);
    }
    unset($value);

    return $filteredData;
  }

  public function fetchGivenMarket() {
    if (!isset($_POST['market_id'])) {
      $response = new JsonResponse();
      $response->setData([]); //TODO: Make this error return more informative, maybe...
      return $response;
    }

    //Get marketID to fetch. This ID is passed from frontend by Ajax/POST request. So we check $_POST for it:
    $marketID = $_POST['market_id'];
    unset($_POST['market_id']);

    //Next step - get collection of search URL queries for given $marketID,
    //which are stored in $_SESSION['sherlock_tmp_storage']['constructed_urls_collection'][$marketID]:
    $searchURLs_by_marketID = $_SESSION['sherlock_tmp_storage']['constructed_urls_collection'][$marketID];

    $priceFrom = $_SESSION['sherlock_tmp_storage']['price_from'];
    $priceTo = $_SESSION['sherlock_tmp_storage']['price_to'];

    $filteredData = $this->fetchMarketCore($marketID, $searchURLs_by_marketID, $priceFrom, $priceTo);

    $response = new JsonResponse();
    $response->setData($filteredData);

    return $response;

  }

  protected function calculateAndInjectChecksums(array &$data) {
    $elementsNum = count($data);

    for ($i = 0; $i < $elementsNum; $i++) {
      $data[$i]['url_hash'] = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $data[$i]['link']);
      $data[$i]['url_price_hash'] = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $data[$i]['link'] . $data[$i]['price_value']);
    }
  }
}
