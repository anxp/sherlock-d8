<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:24
 */
namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

use PhpQuery\PhpQueryObject as phpQueryObject;

class skl_FleaMarket extends FleaMarket {

  protected static $marketId = 'skl';
  protected static $marketName = 'SkyLots';
  protected static $domainName = 'skylots.org';
  protected static $searchPointOfEntry = 'search.php';
  protected static $wordsGlue = '+';

  public function __construct($URL, $pageLimit, string $advertBlockSP = 'div.search_lot', string $titleSP = 'div.search_lot_title', string $titleLinkSP = 'a', string $priceSP = 'div.search_lot_price', string $imageAddressSP = 'div.searchimg', string $nextPageLinkSP = 'div.rpagination a:last') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  /**
   * Skylots is not a flemarket but an auction. Which means it is no sense to filter items by price (it will tend to grow close to auction finish).
   * So the only additional functionality Skylots supports - search in description flag.
   *
   * Example usage: skl_MarketReference::makeRequestURL(['Parker', 'перьевая', 'ручка'], null, null, true);
   *
   * @param array $keyWords keywords to search;
   * @param int|null $priceFrom not supported by SkyLots;
   * @param int|null $priceTo not supported by SkyLots;
   * @param bool $checkDescription search keywords in item description or not. By default only title checks for keywords;
   * @return string result string which looks like usual URL, but unlike OLX - all parameters (including keywords string) are passed as GET-parameters here, after question mark.
   */
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false): string {
    $queryParameters = [];
    $queryParameters['search'] = implode(self::$wordsGlue, $keyWords);
    $queryParameters['desc_check'] = $checkDescription ? 1 : 0;

    $generatedQueryString = http_build_query($queryParameters);

    //               https://skylots.org  /                           search.php                  ?                        search=keyword_1+keyword_2&desc_check=1
    $fullQueryURL = (self::getBaseURL()).(self::URL_PARTS_SEPARATOR).(self::$searchPointOfEntry).(self::QUERY_IDENTIFIER).($generatedQueryString);
    return $fullQueryURL;
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode): string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode): string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('data-src');
    if (empty($thumbAddress)) {return '';}

    $thumbAddress = explode('?', $thumbAddress)[0];

    return $thumbAddress;
  }

  protected function getItemLink(phpQueryObject $phpQueryNode): string {
    return (self::getBaseURL() . $phpQueryNode->find($this->titleLinkSP)->attr('href'));
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode): array {
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());
    $itemPriceWithoutCommaAndSpace = (string) preg_replace('/\s|\,/', '', $itemPriceRaw);
    $priceComponents = explode('.', $itemPriceWithoutCommaAndSpace);
    $itemPrice['price_value'] = (string) $priceComponents[0];
    $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9]+)$/', '$2', $priceComponents[1]); //Filter out digits, leave only currency ID.
    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode): string {
    $internalURL = $phpQueryNode->find($this->nextPageLinkSP)->attr('href');
    //Construct real full path only if found "next page" link is NOT empty, else -> just return '' (empty string):
    $path = ($internalURL === '') ? '' : (self::getBaseURL() . self::URL_PARTS_SEPARATOR . self::$searchPointOfEntry . $internalURL);
    return ($path);
  }
}
