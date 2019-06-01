<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-02-17
 * Time: 13:41
 */

namespace Drupal\sherlock_d8\CoreClasses\ItemSniper;

use phpquery\PhpQuery as phpQuery;
use phpquery\phpQueryObject as phpQueryObject;
use function phpQuery\pq;

abstract class ItemSniper {
  //Just the most popular user agent, it can be overriden by setUserAgent() method:
  protected $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36';

  //This value will be set to CURLOPT_CONNECTTIMEOUT. Can be overriden by setConnectionTimeout() method:
  //TODO: Read about connection timeout in cURL, and set something more correct (2 sec?)
  protected $connectionTimeout = 10;

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

  //$URL - URL to fetch;
  //We do constructor protected, because we want create only objects of child classes.
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

  /**
   * @param string $userAgent
   */
  public function setUserAgent(string $userAgent): void {
    $this->userAgent = $userAgent;
  }

  /**
   * @param int $connectionTimeout
   */
  public function setConnectionTimeout(int $connectionTimeout): void {
    $this->connectionTimeout = $connectionTimeout;
  }

  /**
   * @return string
   */
  public function getUserAgent(): string {
    return $this->userAgent;
  }

  /**
   * @return int
   */
  public function getConnectionTimeout(): int {
    return $this->connectionTimeout;
  }

  /**
   * @return string
   * @param string $url
   */
  protected function getWebpageHtml($url) {
    $ch = curl_init();

    curl_setopt_array($ch, [
      CURLOPT_USERAGENT => $this->getUserAgent(),
      CURLOPT_CONNECTTIMEOUT => $this->getConnectionTimeout(),
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

  abstract protected function getItemTitle(phpQueryObject $phpQueryNode) : string;
  abstract protected function getThumbnailAddress(phpQueryObject $phpQueryNode) : string;
  abstract protected function getItemLink(phpQueryObject $phpQueryNode) : string;
  abstract protected function getItemPrice(phpQueryObject $phpQueryNode) : array;
  abstract protected function getNextPageLink(phpQueryObject $phpQueryNode) : string;

  /**
   * @return array
   */
  public function grabItems(): array {
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
        $collectedItems[$cii]['price_value'] = $itemPrice['price_value'];
        $collectedItems[$cii]['price_currency'] = $itemPrice['price_currency'];
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
}
