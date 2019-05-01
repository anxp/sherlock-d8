<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-01
 * Time: 14:53
 */
namespace Drupal\sherlock_d8\CoreClasses\ArrayFiltration_2D;

class ArrayFiltration_2D implements iArrayFiltration_2D {
  public static function selectUniqueSubArrays_byField(array $inputArray, string $fieldToCheck): array {
    if (!is_array($inputArray)) {return [];} //TODO: Maybe throw exception here?

    $tmpArr = [];
    foreach ($inputArray as $key => $value) {
      $tmpArr[$key] = $value[$fieldToCheck];
    }
    unset($key, $value);

    $tmpArr = array_unique($tmpArr);

    $filtered_arr = [];
    foreach ($tmpArr as $key => $value) {
      $filtered_arr[] = $inputArray[$key];
    }
    unset($key, $value);

    return $filtered_arr;
  }

  public static function selectUniqueSubArrays_byTotalCompare(array $inputArray): array {
    if (!is_array($inputArray)) {return [];} //TODO: Maybe throw exception here?

    $inputArrayCount = count($inputArray);

    for ($i = 0; $i < $inputArrayCount; $i++) {
      for ($j = 0; $j < $inputArrayCount; $j++) {
        if ($i === $j) {continue;} //Obviously, we don't want compare element with itself :)
        if (array_diff($inputArray[$i], $inputArray[$j]) === [] && count($inputArray[$i]) === count($inputArray[$j])) {
          $inputArray[$j] = []; //If duplicate found -> replace it with empty array.
        }
      }
    }
    //Select only NOT EMPTY elements:
    $filteredArray = array_filter($inputArray, function ($value) {return empty($value) ? FALSE : TRUE;});

    return $filteredArray;
  }

  public static function selectSubArrays_byFieldValueRange(array $inputArray, string $fieldToCheck, $minValue, $maxValue): array {
    $filteredArray = [];
    $inputArrayCount = count($inputArray);

    for ($i = 0; $i < $inputArrayCount; $i++) {
      //This strange construction intended to correctly process case, when one or both limits not explicitly set (actually when they === NULL),
      //If limit === NULL, we consider comparison with this limit is not important to user and return TRUE in this case, otherwise we do real comparison
      //of given value with limit and return TRUE or FALSE.
      //Element passes filtration only when both $comparisonToMin === TRUE && $comparisonToMax === TRUE.
      $comparisonToMin = ($minValue === null) ? TRUE : ((floatval($inputArray[$i][$fieldToCheck]) >= $minValue) ? TRUE : FALSE);
      $comparisonToMax = ($maxValue === null) ? TRUE : ((floatval($inputArray[$i][$fieldToCheck]) <= $maxValue) ? TRUE : FALSE);

      if ($comparisonToMin === TRUE && $comparisonToMax === TRUE) {
        $filteredArray[] = $inputArray[$i];
      }
    }
    return $filteredArray;
  }
}