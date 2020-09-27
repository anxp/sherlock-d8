<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:11
 */
namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

use PhpQuery\PhpQuery as phpQuery;
use PhpQuery\PhpQueryObject as phpQueryObject;

class olx_FleaMarket extends FleaMarket {
  //Olx is most functional fleamarket - it supports filtering by price from min_price to max_price, can search in item description,
  //and many other options which are not implemented in this method.
  //We implement only filtering by price and search in description options as most needed.

  protected static $marketId = 'olx';
  protected static $marketName = 'OLX';
  protected static $domainName = 'olx.ua';
  protected static $subjectPrefix = 'list/q-';
  protected static $wordsGlue = '-';

  public function __construct($URL, $pageLimit, $advertBlockSP = 'div.offer-wrapper', $titleSP = 'h3', $titleLinkSP = 'h3 > a', $priceSP = 'p.price', $imageAddressSP = 'img.fleft', $nextPageLinkSP = 'div.pager span.next a') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  /**
   * This method generates full query URL from given key words, price limits (if they are passed) and search in description flag (if set).
   * Usage example: olx_FleaMarket::makeRequestURL(['Parker', 'перьевая', 'ручка'], 100, 800, false);
   * @param array $keyWords keywords to search;
   * @param int|null $priceFrom minimal price limit;
   * @param int|null $priceTo max price limit;
   * @param bool $checkDescription search keywords in item description or not. By default only title checks for keywords;
   * @return string result string which looks like usual URL with parameters after question mark.
   */
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false) :string {
    $queryParameters = [];
    $generatedQueryString = '';

    if ($priceFrom !== null) {
      $queryParameters['search']['filter_float_price:from'] = $priceFrom;
    }

    if ($priceTo !== null) {
      $queryParameters['search']['filter_float_price:to'] = $priceTo;
    }

    if ($checkDescription !== false) {
      $queryParameters['search']['description'] = 1;
    }

    if (!empty($queryParameters)) {
      $generatedQueryString = http_build_query($queryParameters);
      $generatedQueryString = self::QUERY_IDENTIFIER.$generatedQueryString;
    }

    //               https://olx.ua       /                           list/q-              what-we-looking-for                    /                          ?search[x]=y&search[a]=b
    $fullQueryURL = (self::getBaseURL()).(self::URL_PARTS_SEPARATOR).(self::$subjectPrefix.implode(self::$wordsGlue, $keyWords)).(self::URL_PARTS_SEPARATOR).$generatedQueryString;
    return $fullQueryURL;
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode) :string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode) :string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('src');
    return (empty($thumbAddress) ? '' : $thumbAddress);
  }

  protected function getItemLink(phpQueryObject $phpQueryNode) :string {
    //We parse link to item and then gather only required parts.
    //For example, usually links from OLX ends with smth like "#1b78919f15;promoted" and we don't need such trash.
    $itemLink = $phpQueryNode->find($this->titleLinkSP)->attr('href');
    $linkParts = parse_url($itemLink);
    $itemLinkClean = $linkParts['scheme'].'://'.$linkParts['host'].$linkParts['path']; //Link now clean.
    return $itemLinkClean;
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode) :array {
    //Get price. At the moment this is 'raw' price, with number and currency ID, like 5 000 грн. We'll parse it later:
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());
    $itemPrice = [];
    $itemPrice['price_value'] = (string) preg_replace('/\D/', '', $itemPriceRaw); //Filter out ANYTHING THAT NOT a digit.
    $itemPriceRawNoSpaces = (string) preg_replace('/\s/', '', $itemPriceRaw);
    $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9|\.]+)(\.)?$/', '$2', $itemPriceRawNoSpaces); //Filter out digits, leave only currency ID.
    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode) :string {
    return ($phpQueryNode->find($this->nextPageLinkSP)->attr('href'));
  }

  private function isAdsRelevant(phpQueryObject $phpQueryNode) :bool {
    //Check if 'no items found' message present. If yes - IGNORE ALL return from OLX because it is NOT relevant.
    $noAdsFoundMessage = $phpQueryNode->find('div.emptynew > p')->text(); //Empty string OR 'Не найдено ни одного объявления, соответствующего параметрам поиска. Проверьте правильность написания или введите другие параметры поиска'
    $noAdsFoundMessage = trim($noAdsFoundMessage);
    return empty($noAdsFoundMessage) ? TRUE : FALSE;
  }

  public function grabItems(): array {
    $fullHTML = $this->getWebpageHtml($this->URL);
    $doc = phpQuery::newDocument($fullHTML);
    $isAdsRelevant = $this->isAdsRelevant($doc);
    phpQuery::unloadDocuments($doc);
    return $isAdsRelevant ? parent::grabItems() : [];
  }
}
