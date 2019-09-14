<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-11
 * Time: 08:21
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

abstract class MarketReference implements iMarketReference, iMarketDirectory {
  protected static $marketId = ''; //Three lowercase-letter id of the market
  protected static $marketName = ''; //Human-readable market name
  protected static $domainName = ''; //Domain name, like olx.ua without protocol and ending '/'

  public static function getMarketId(): string {
    return static::$marketId;
  }

  public static function getMarketName(): string {
    return static::$marketName;
  }

  public static function getBaseURL(): string {
    return (static::PROTOCOL).(static::$domainName);
  }

  //This function returns actual list of available fleamarket objects anywhere in the module.
  //It can return 0-1-2 indexed array, or associative array where keys is fleamarkets IDs (like olx, bsp, skl).
  //When creating new fleamarket - just add it's class, which extends MarketReference class,
  //make new array element in THIS function, and edit .css (add one more tab).
  public static function getAvailableFleamarkets($assoc = FALSE): array {
    $availableFleamarkets = [];

    //These classes have ONLY static methods,
    //but we really need to create objects of them, to have ability to pass the object reference to somewhere such as array element:
    $availableFleamarkets[olx_MarketReference::getMarketId()] = new olx_MarketReference();
    $availableFleamarkets[bsp_MarketReference::getMarketId()] = new bsp_MarketReference();
    $availableFleamarkets[skl_MarketReference::getMarketId()] = new skl_MarketReference();

    if (!$assoc) {
      return array_values($availableFleamarkets);
    }

    return $availableFleamarkets;
  }
}
