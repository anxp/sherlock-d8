<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-12
 * Time: 22:48
 */
namespace Drupal\sherlock_d8\CoreClasses\SherlockDirectory;

use Drupal\sherlock_d8\CoreClasses\MarketReference\olx_MarketReference;
use Drupal\sherlock_d8\CoreClasses\MarketReference\bsp_MarketReference;
use Drupal\sherlock_d8\CoreClasses\MarketReference\skl_MarketReference;

class SherlockDirectory {
  //This function returns actual list of available fleamarket objects anywhere in the module.
  //It can return 0-1-2 indexed array, or associative array where keys is fleamarkets IDs (like olx, bsp, skl).
  //When creating new fleamarket - just add it's class, which extends MarketReference class, make new array element in THIS function, and edit .css (add one more tab).
  public static function getAvailableFleamarkets($assoc = FALSE) {
    $fleamarket_objects = [];

    //These classes have ONLY static methods,
    //but we really need to create objects of them, to have ability to pass the object reference to somewhere such as array element:
    $fleamarket_objects[0] = new olx_MarketReference();
    $fleamarket_objects[1] = new bsp_MarketReference();
    $fleamarket_objects[2] = new skl_MarketReference();

    if ($assoc) {
      $fleamarket_objects_assoc = [];
      foreach ($fleamarket_objects as $object) {
        $fleamarket_objects_assoc[$object::getMarketId()] = $object;
      }
      unset($object);

      $fleamarket_objects = $fleamarket_objects_assoc;
    }
    return $fleamarket_objects;
  }
}

