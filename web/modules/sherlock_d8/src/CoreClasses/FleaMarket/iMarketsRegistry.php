<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-14
 * Time: 21:52
 */

namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

/**
 * Interface iMarketsRegistry
 *
 * Contains methods to work with registry of markets.
 *
 * @package Drupal\sherlock_d8\CoreClasses\FleaMarket
 */
interface iMarketsRegistry {
  public static function getSupportedMarketsList($assoc = FALSE): array;
}
