<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-11
 * Time: 08:22
 */
namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

/**
 * Interface iMarketReference
 *
 * Contains methods which returns basic info about markets (market ID, market name...),
 * and also has method to construct search-request-URL.
 * All these methods should be designed to work without object instantiation (be abstract).
 *
 * @package Drupal\sherlock_d8\CoreClasses\FleaMarket
 */
interface iMarketReference {
  const PROTOCOL = 'https://';
  const URL_PARTS_SEPARATOR = '/';
  const QUERY_IDENTIFIER = '?';
  public static function makeRequestURL(array $keyWords, int $priceFrom = null, int $priceTo = null, bool $checkDescription = false) :string;
  public static function getMarketId() :string;
  public static function getMarketName() :string;
  public static function getBaseURL() :string;
}
