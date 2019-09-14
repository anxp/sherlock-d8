<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-14
 * Time: 21:52
 */

namespace Drupal\sherlock_d8\CoreClasses\MarketReference;

interface iMarketDirectory {
  public static function getAvailableFleamarkets($assoc = FALSE): array;
}
