<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-01
 * Time: 14:49
 */
namespace Drupal\sherlock_d8\CoreClasses\BlackMagic;

use Drupal\sherlock_d8\CoreClasses\ArrayFiltration_2D\ArrayFiltration_2D;

class BlackMagic {
  public static function generateAllPossibleCombinations (array $block_values): array {
    //FIRST STEP: If we found string as keyword -> we explode it to array of words, so it will be more easier to implode back.
    //If string contains only one word -> we also do array from it, just array with only one element:
    $block_values_count = count($block_values); //Number of blocks/terms. We don't take into consideration term variations here. Just count number of blocks with term (terms variations) inside.
    $block_values_rebuilt = [];

    for ($i = 0; $i < $block_values_count; $i++) {
      $current_term_count = count($block_values[$i]);

      for ($j = 0; $j < $current_term_count; $j++) {
        $tmp = explode(' ', $block_values[$i][$j]);
        $tmp_count = count($tmp);

        for ($k = 0; $k < $tmp_count; $k++) {
          $tmp[$k] = trim($tmp[$k], ' .,?!');
        }

        $block_values_rebuilt[$i][$j] = $tmp;
      }
    }
    //--------------------------------------------------------------------------------------------------------------------

    //SECOND STEP: Compose all possible combinations from keywords:
    $combinations_array = [];
    $combinations_array = array_shift($block_values_rebuilt);

    while (!empty($block_values_rebuilt)) {
      $array_A = $combinations_array;
      $combinations_array = [];
      $array_B = array_shift($block_values_rebuilt);

      foreach ($array_A as $value_A) {
        foreach ($array_B as $value_B) {
          $combinations_array[] = array_merge($value_A, $value_B);
        }
        unset($value_B);
      }
      unset($value_A);
    }
    //--------------------------------------------------------------------------------------------------------------------

    //THIRD STEP: Remove duplicates. a) remove duplicates inside each set; b) remove duplicated sets (if any):
    //Every element in $combinations_array is an array of keywords which will form a one string (one search query).
    //Let's check every set of keywords (every element of $combinations_array) for duplicates:
    $combinations_array_count = count($combinations_array);
    for ($i = 0; $i < $combinations_array_count; $i++) {
      $combinations_array[$i] = array_unique($combinations_array[$i]);
    }

    //And finally, let's check that all elements of $combinations_array are unique comparing with each other. Yes, we need to compare arrays!
    $filtered_array = ArrayFiltration_2D::selectUniqueSubArrays_byTotalCompare($combinations_array);

    return $filtered_array;
  }
}