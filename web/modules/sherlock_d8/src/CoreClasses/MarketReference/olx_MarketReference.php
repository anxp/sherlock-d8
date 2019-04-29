<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:11
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

class olx_MarketReference extends MarketReference implements iMarketReference {
  //Olx is most functional fleamarket - it supports filtering by price from min_price to max_price, can search in item description,
  //and many other options which are not implemented in this method.
  //We implement only filtering by price and search in description options as most needed.

  protected static $marketId = 'olx';
  protected static $marketName = 'OLX';
  protected static $domainName = 'olx.ua';
  protected static $subjectPrefix = 'list/q-';
  protected static $wordsGlue = '-';

  /**
   * This method generates full query URL from given key words, price limits (if they are passed) and search in description flag (if set).
   * @param array $keyWords keywords to search;
   * @param int|null $priceFrom minimal price limit;
   * @param int|null $priceTo max price limit;
   * @param bool $checkDescription search keywords in item description or not. By default only title checks for keywords;
   * @return string result string which looks like usual URL with parameters after question mark.
   */
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false): string {
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
}

//Example usage:
//echo olx_MarketReference::makeRequestURL(['Parker', 'перьевая', 'ручка'], 100, 800, false);
