<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:11
 */
namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

use PhpQuery\PhpQueryObject as phpQueryObject;

class kleinanzeigen_FleaMarket extends FleaMarket {

  protected static $marketId = 'kleinanzeigen';
  protected static $marketName = 'eBay-Kleinanzeigen';
  protected static $domainName = 'ebay-kleinanzeigen.de';
  protected static $advertisementType = 'anzeige:angebote';
  protected static $subjectPrefix = 's-';
  protected static $wordsGlue = '-';
  protected static $suffix = 'k0';

  public function __construct($URL, $pageLimit, $advertBlockSP = 'article.aditem', $titleSP = 'h2', $titleLinkSP = 'h2 > a', $priceSP = 'p.aditem-main--middle--price-shipping--price', $imageAddressSP = 'div.imagebox', $nextPageLinkSP = 'div.pagination-nav > a.pagination-next') {
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

    $urlComponents = [
      self::getBaseURL(),
      self::$subjectPrefix . self::$advertisementType,
    ];

    if ($priceFrom !== null || $priceTo !== null) {
      $urlComponents[] = 'preis:' . $priceFrom . ':' . $priceTo;
    }

    $urlComponents[] = implode(self::$wordsGlue, $keyWords);
    $urlComponents[] = self::$suffix;

    return implode(self::URL_PARTS_SEPARATOR, $urlComponents);
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode) :string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode) :string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('data-imgsrc');
    return (empty($thumbAddress) ? '' : $thumbAddress);
  }

  protected function getItemLink(phpQueryObject $phpQueryNode) :string {
    return (self::getBaseURL() . $phpQueryNode->find($this->titleLinkSP)->attr('href'));
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode) :array {
    //Get price. At the moment this is 'raw' price, with number and currency ID, like "180 € VB" We'll parse it later:
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());
    $itemPrice = [];

    // 18.000 € VB -> 18000
    $itemPrice['price_value'] = (string) preg_replace('/\D/', '', $itemPriceRaw); //Filter out ANYTHING THAT NOT a digit.

    // 18.000 € VB -> 18000€VB
    $itemPriceRawNoSpacesNoDots = (string) preg_replace('/[\s\.]/', '', $itemPriceRaw);

    $matches = [];
    preg_match('/(\d+)?(€{1})?([A-Z]*)$/', $itemPriceRawNoSpacesNoDots, $matches);

    // $matches[0] => '18000€VB' $matches[1] => '18000', $matches[2] => '€', $matches[3] => 'VB'
    $itemPrice['price_currency'] = !empty($matches[2]) ? $matches[2] : (!empty($matches[3]) ? $matches[3] : '');

    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode) :string {
    return (self::getBaseURL() . $phpQueryNode->find($this->nextPageLinkSP)->attr('href'));
  }
}
