<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-05-01
 * Time: 14:55
 */
namespace Drupal\sherlock_d8\CoreClasses\ArrayFiltration_2D;

interface iArrayFiltration_2D {
  public static function selectUniqueSubArrays_byField(array $inputArray, string $fieldToCheck): array;
  public static function selectUniqueSubArrays_byTotalCompare(array $inputArray): array;

  /**
   * @param array $inputArray
   * @param string $fieldToCheck
   * @param float | null $minValue
   * @param float | null $maxValue
   * @return array
   */
  public static function selectSubArrays_byFieldValueRange(array $inputArray, string $fieldToCheck, $minValue, $maxValue): array;
}