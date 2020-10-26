<?php

namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

use PhpQuery\PhpQueryObject as phpQueryObject;

class izi_FleaMarket extends FleaMarket {
  protected static $marketId = 'izi';
  protected static $marketName = 'iZi';
  protected static $domainName = 'izi.ua';
  protected static $searchPointOfEntry = 'search';
  protected static $wordsGlue = '+';

  //Альтернативный вариант поиска ссылки на следующую страницу в результатах поиска для IZI: 'ul.ek-grid > li > a.ek-button:contains("Вперед")'
  public function __construct($URL, $pageLimit, $advertBlockSP = 'li.b-catalog__item', $titleSP = 'div.ek-box > span.ek-text > a.ek-link', $titleLinkSP = 'div.ek-box > span.ek-text > a.ek-link', $priceSP = 'div.ek-box > span.ek-text_weight_black', $imageAddressSP = 'picture.ek-picture > img', $nextPageLinkSP = 'div[data-bazooka="ProductsList"] > div.ek-box > ul.ek-grid > li.ek-grid__item:last > a.ek-button') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  /**
   * This method generates full query URL from given key words, and price limits (if they are passed).
   *
   * Usage example: izi_FleaMarket::makeRequestURL(['Parker', 'перьевая', 'ручка'], 100, 800);
   *
   * @param array $keyWords keywords to search;
   * @param int|null $priceFrom minimal price limit;
   * @param int|null $priceTo max price limit;
   * @param bool $checkDescription not supported by IZI;
   * @return string result string which looks like usual URL with parameters after question mark.
   */
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false): string {
    $queryParameters = [];
    $queryParameters['search_text'] = implode(self::$wordsGlue, $keyWords);

    if ($priceFrom !== null) {
      $queryParameters['price__gte'] = $priceFrom;
    }

    if ($priceTo !== null) {
      $queryParameters['price__lte'] = $priceTo;
    }

    $generatedQueryString = http_build_query($queryParameters);

    //      https://izi.ua       /                           search                      ?                        search_text=keyword_1+keyword_2&price__gte=200&price__lte=400
    return (self::getBaseURL()).(self::URL_PARTS_SEPARATOR).(self::$searchPointOfEntry).(self::QUERY_IDENTIFIER).($generatedQueryString);
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode): string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode): string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('data-srcset');
    return (empty($thumbAddress) ? '' : $thumbAddress);
  }

  protected function getItemLink(phpQueryObject $phpQueryNode): string {
    return (self::getBaseURL().$phpQueryNode->find($this->titleLinkSP)->attr('href'));
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode): array {
    //Get price. At the moment this is 'raw' price, with number and currency ID, like 50.99 грн. We'll parse it later:
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());

    $matches = [];
    preg_match('/(\d+\.\d+|\d+)/', $itemPriceRaw, $matches);
    $priceValue = $matches[0] ?? ''; // Here we have a "cleaned" price like 100 or 100.99
    $priceMainPart = explode('.', $priceValue)[0]; // Leave only main part of price.

    $itemPriceRawNoSpaces = (string) preg_replace('/\s/', '', $itemPriceRaw);
    $priceCurrency = (string) preg_replace('/^(\d+\.\d+|\d+)([^0-9]+)$/', '$2', $itemPriceRawNoSpaces); //Filter out digits, leave only currency ID.

    return [
      'price_value' => $priceMainPart,
      'price_currency' => $priceCurrency,
    ];
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode): string {
    $internalURL = $phpQueryNode->find($this->nextPageLinkSP)->attr('href');
    $path = ($internalURL === '') ? '' : (self::getBaseURL() . $internalURL);

    return $path;
  }
}
