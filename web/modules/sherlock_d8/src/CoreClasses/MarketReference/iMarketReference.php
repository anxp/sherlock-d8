<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-11
 * Time: 08:22
 */
namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

interface iMarketReference {
  const PROTOCOL = 'https://';
  const URL_PARTS_SEPARATOR = '/';
  const QUERY_IDENTIFIER = '?';
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false): string ;
  public static function getMarketId(): string;
  public static function getMarketName(): string;
  public static function getBaseURL(): string;
}