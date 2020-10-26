<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-11
 * Time: 08:21
 */
namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

use PhpQuery\PhpQuery as phpQuery;
use PhpQuery\PhpQueryObject as phpQueryObject;
use function PhpQuery\pq;

abstract class FleaMarket implements iMarketReference, iMarketsRegistry, iMarketSniper {

  //=========== Some basic market properties can be accessed (via getters!) without object instantiating: ==============
  protected static $marketId = ''; //Three lowercase-letter id of the market
  protected static $marketName = ''; //Human-readable market name
  protected static $domainName = ''; //Domain name, like olx.ua without protocol and ending '/'
  //====================================================================================================================

  //Just the most popular user agent, it can be overriden by setUserAgent() method:
  protected $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36';

  //This value will be set to CURLOPT_CONNECTTIMEOUT:
  protected $connectionTimeout = 2;

  //URL to fetch. We will set it up in constructor:
  protected $URL = '';

  //How deep (in pages) our script will dig. 0 for UNLIMITED.
  protected $pageLimit = 0;

  //How to find advertisement block in webpage - search string for phpQuery ('SP' stands for 'Search Pattern'):
  protected $advertBlockSP = '';

  //How to find title of given advertisement block. This 'path' is not absolute, but relative to $advertBlockSP ('SP' stands for 'Search Pattern'):
  protected $titleSP = '';

  //How to find title link (link to item page with full description, 'SP' stands for 'Search Pattern'):
  protected $titleLinkSP = '';

  //How to find price of given advertisement block. This 'path' is not absolute, but relative to $advertBlockSP ('SP' stands for 'Search Pattern'):
  protected $priceSP = '';

  //How to find link to attached thumbnail image. This 'path' is not absolute, but relative to $advertBlockSP ('SP' stands for 'Search Pattern'):
  protected $imageAddressSP = '';

  //How to find link to next page in webpage with advertisements - search string for phpQuery ('SP' stands for 'Search Pattern'):
  protected $nextPageLinkSP = '';

  //=========== Methods, individual for each fleamarket class ==========================================================
  abstract public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false) :string;

  abstract protected function getItemTitle(phpQueryObject $phpQueryNode) :string;
  abstract protected function getThumbnailAddress(phpQueryObject $phpQueryNode) :string;
  abstract protected function getItemLink(phpQueryObject $phpQueryNode) :string;
  abstract protected function getItemPrice(phpQueryObject $phpQueryNode) :array;
  abstract protected function getNextPageLink(phpQueryObject $phpQueryNode) :string;
  //====================================================================================================================

  public static function getMarketId() :string {
    return static::$marketId;
  }

  public static function getMarketName() :string {
    return static::$marketName;
  }

  public static function getBaseURL() :string {
    return (static::PROTOCOL).(static::$domainName);
  }

  /**
   * Method returns basic flea markets properties (which are not need instantiated object).
   * It can return 0-1-2 indexed array, or associative array where keys is fleamarkets IDs (like olx, bsp, skl).
   * When creating new fleamarket - just add it's class, which extends FleaMarket class,
   * make new array element in THIS function, and edit .css (add one more tab).
   *
   * @param bool $assoc
   * @return array
   */
  public static function getSupportedMarketsList($assoc = FALSE) :array {
    $marketsProperties = [

      olx_FleaMarket::getMarketId() => [
        'marketID' => olx_FleaMarket::getMarketId(),
        'marketName' => olx_FleaMarket::getMarketName(),
        'marketURL' => olx_FleaMarket::getBaseURL(),
        'marketClassName' => 'olx_FleaMarket',
      ],

      bsp_FleaMarket::getMarketId() => [
        'marketID' => bsp_FleaMarket::getMarketId(),
        'marketName' => bsp_FleaMarket::getMarketName(),
        'marketURL' => bsp_FleaMarket::getBaseURL(),
        'marketClassName' => 'bsp_FleaMarket',
      ],

      skl_FleaMarket::getMarketId() => [
        'marketID' => skl_FleaMarket::getMarketId(),
        'marketName' => skl_FleaMarket::getMarketName(),
        'marketURL' => skl_FleaMarket::getBaseURL(),
        'marketClassName' => 'skl_FleaMarket',
      ],

      izi_FleaMarket::getMarketId() => [
        'marketID' => izi_FleaMarket::getMarketId(),
        'marketName' => izi_FleaMarket::getMarketName(),
        'marketURL' => izi_FleaMarket::getBaseURL(),
        'marketClassName' => 'izi_FleaMarket',
      ],
    ];

    if (!$assoc) {
      return array_values($marketsProperties);
    }

    return $marketsProperties;
  }

  /**
   * @return array
   */
  public function grabItems() :array {
    $pgCount = 0;
    $collectedItems = []; //Here we will store all found items. This is indexed array with associative sub-arrays.
    $cii = 0; //cii == Collected Items Iterator.
    $url = $this->URL;
    do {
      $fullHTML = $this->getWebpageHtml($url);
      $doc = phpQuery::newDocument($fullHTML);
      $offerBlocks = $doc->find($this->advertBlockSP);
      foreach ($offerBlocks as $block) {
        $pq_block = pq($block);

        //Get title of the ad-block:
        $itemTitle = $this->getItemTitle($pq_block);

        //Get address of attached thumbnail:
        $itemThumbnail = $this->getThumbnailAddress($pq_block);

        //Get link to page with full description of ad:
        $itemLink = $this->getItemLink($pq_block);

        //Get item price (array of two elements - price_value and price_currency):
        $itemPrice = $this->getItemPrice($pq_block);

        $collectedItems[$cii]['title'] = trim($itemTitle);
        $collectedItems[$cii]['thumbnail'] = $itemThumbnail;
        $collectedItems[$cii]['link'] = $itemLink;
        $collectedItems[$cii]['price_value'] = $itemPrice['price_value'] ?? '';
        $collectedItems[$cii]['price_currency'] = $itemPrice['price_currency'] ?? '';
        $cii++;
      }

      //Get link to next search results page:
      $nextPageLink = $this->getNextPageLink($doc);

      phpQuery::unloadDocuments($doc);

      $url = $nextPageLink;
      $pgCount++;

      //If digging limit not reached ($pgCount < $this->pageLimit),
      //or $this->pageLimit set to 0 (Unlimited) -> set $willContinue flag to TRUE:
      $willContinue = (($pgCount < $this->pageLimit) || ($this->pageLimit === 0));

    } while (($url !== '') && $willContinue);

    return $collectedItems;
  }

  /**
   * @param string $userAgent
   */
  public function setUserAgent(string $userAgent) :void {
    $this->userAgent = $userAgent;
  }

  /**
   * @return string
   */
  public function getUserAgent() :string {
    return $this->userAgent;
  }

  /**
   * @return string
   * @param string $url
   */
  protected function getWebpageHtml($url) :string {
    $ch = curl_init();

    curl_setopt_array($ch, [
      CURLOPT_USERAGENT => $this->getUserAgent(),
      CURLOPT_CONNECTTIMEOUT => $this->connectionTimeout,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER => FALSE,
      CURLOPT_FOLLOWLOCATION => TRUE, //Follow redirects
      CURLOPT_URL => $url,
    ]);

    //Get URL content in string:
    $htmlString = curl_exec($ch);

    //Close handle to release resources
    curl_close($ch);

    return $htmlString;
  }

  //$URL - URL to fetch;
  //We do constructor protected, because we want create only objects of child classes. But actually child classes will use this constructor:
  protected function __construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP) {
    $this->URL = $URL;
    $this->pageLimit = $pageLimit;
    $this->advertBlockSP = $advertBlockSP;
    $this->titleSP = $titleSP;
    $this->titleLinkSP = $titleLinkSP;
    $this->priceSP = $priceSP;
    $this->imageAddressSP = $imageAddressSP;
    $this->nextPageLinkSP = $nextPageLinkSP;
  }
}
