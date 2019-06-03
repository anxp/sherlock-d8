<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-28
 * Time: 22:57
 */

namespace Drupal\sherlock_d8\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\sherlock_d8\CoreClasses\ItemSniper\{ItemSniper, olx_ItemSniper, bsp_ItemSniper, skl_ItemSniper};
use Drupal\sherlock_d8\CoreClasses\ArrayFiltration_2D\ArrayFiltration_2D;

class MarketFetchController extends ControllerBase {
  public function fetchMarkets() {
    if (!isset($_POST['market_id'])) {
      $response = new JsonResponse();
      $response->setData([]); //TODO: Make this error return more informative, maybe...
      return $response;
    }

    $itemSniperFullNamespacePath = '\Drupal\sherlock_d8\CoreClasses\ItemSniper\\';

    //Get marketID to fetch. This ID is passed from frontend by Ajax/POST request. So we check $_POST for it:
    $marketID = $_POST['market_id'];
    unset($_POST['market_id']);

    //Next step - get collection of search URL queries for given $marketID,
    //which are stored in $_SESSION['sherlock_tmp_storage']['constructed_urls_collection'][$marketID]:
    $searchURLs_by_marketID = $_SESSION['sherlock_tmp_storage']['constructed_urls_collection'][$marketID];

    //Construct Object Name.
    // As a result we will have Fully Qualified Object Name like "\Drupal\sherlock_d8\CoreClasses\ItemSniper\olx_ItemSniper":
    $objName = $itemSniperFullNamespacePath.$marketID . '_ItemSniper';

    $snipeRawResults = [];
    for ($i = 0; $i < count($searchURLs_by_marketID); $i++) {
      $sniperObject = new $objName($searchURLs_by_marketID[$i], 5); //Create new object of somemarket_ItemSniper.
      $oneQueryResult = $sniperObject->grabItems();
      unset($sniperObject);
      $snipeRawResults[$searchURLs_by_marketID[$i]] = $oneQueryResult;
    }

    //TODO - Here is perfect place to cache search results ($snipeRawResults)
    //TODO; for given searchURL ($snipeRawResults['key']) in DB.

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
    $price_from = $_SESSION['sherlock_tmp_storage']['price_from'];
    $price_to = $_SESSION['sherlock_tmp_storage']['price_to'];
    $filteredData = ArrayFiltration_2D::selectSubArrays_byFieldValueRange($filteredData, 'price_value', $price_from, $price_to);

    //At the very last step - cache images (or use already cached if they are exists):
    /**
     * TODO: Implement Caching.
     */

    $response = new JsonResponse();
    $response->setData($filteredData);

    return $response;

  }
}
