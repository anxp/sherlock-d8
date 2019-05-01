<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-01
 * Time: 14:10
 */
namespace Drupal\sherlock_d8\CoreClasses\TextUtilities;

class TextUtilities {
  public static function startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
  }

  public static function endsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
      return true;
    }

    return (substr($haystack, -$length) === $needle);
  }
}