<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:24
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

class skl_MarketReference extends MarketReference implements iMarketReference {
  //Skylots is not a flemarket but an auction. Which means it is no sense to filter items by price (it will tend to grow close to auction finish).
  //So the only additional functionality Skylots supports - search in description flag.
  protected static $marketId = 'skl';
  protected static $marketName = 'SkyLots';
  protected static $domainName = 'skylots.org';
  protected static $searchPointOfEntry = 'search.php';
  protected static $wordsGlue = '+';

  /**
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
}

//Example usage:
//echo skl_MarketReference::makeRequestURL(['Parker', 'перьевая', 'ручка'], null, null, true);