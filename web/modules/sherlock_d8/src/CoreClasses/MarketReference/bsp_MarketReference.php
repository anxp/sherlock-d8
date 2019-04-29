<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:25
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

class bsp_MarketReference extends MarketReference implements iMarketReference {
  //Besplatka has simplest fleamarket functionality - it does not support any extra filtration options, only search by given keywords.

  protected static $marketId = 'bsp';
  protected static $marketName = 'Besplatka';
  protected static $domainName = 'besplatka.ua';
  protected static $subjectPrefix = 'all/q-';
  protected static $wordsGlue = '+';

  /**
   * This method generates full query URL from given key words. Price limits and search in description flag ARE NOT SUPPORTED IN BESPLATKA.
   * @param array $keyWords keywords to search;
   * @param int|null $priceFrom not supported by Besplatka;
   * @param int|null $priceTo not supported by Besplatka;
   * @param bool $checkDescription not supported by Besplatka;
   * @return string result string, which looks like usual URL;
   */
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false): string {
    //               https://besplatka.ua /                           all/q-               what-we-looking-for
    $fullQueryURL = (self::getBaseURL()).(self::URL_PARTS_SEPARATOR).(self::$subjectPrefix.implode(self::$wordsGlue, $keyWords));
    return $fullQueryURL;
  }
}

//Example usage:
//echo bsp_MarketReference::makeRequestURL(['Parker', 'перьевая', 'ручка']);