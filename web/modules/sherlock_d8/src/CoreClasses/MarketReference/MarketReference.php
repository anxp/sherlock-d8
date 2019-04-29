<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-11
 * Time: 08:21
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

abstract class MarketReference implements iMarketReference {
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
}