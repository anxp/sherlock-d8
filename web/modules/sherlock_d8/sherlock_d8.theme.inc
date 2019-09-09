<?php
/**
 * Preprocess functions for all hook_themes
 */

/**
 * Implements template_preprocess_HOOK for display_queries.
 * @param $variables
 */
function template_preprocess_display_queries(&$variables) {
}

/**
 * Implements template_preprocess_HOOK for display_results.
 * @param $variables
 */
function template_preprocess_display_results(&$variables) {
}

/**
 * Implements template_preprocess_HOOK for scheduled_email_with_search_results.
 * @param $variables
 */
function template_preprocess_scheduled_email_with_search_results(&$variables) {
  global $base_url;
  $modulePath = \Drupal::moduleHandler()->getModule('sherlock_d8')->getPath();

  $noImageThumbnailPath = $base_url . '/' . $modulePath . '/templates/img/sherlock-no-image.jpg';

  foreach ($variables as $key => &$value) {
    if ($key === 'new_results' || $key === 'all_results') {
      foreach ($value as &$someMarketResultsSet) {
        for ($i = 0; $i < count($someMarketResultsSet); $i++) {
          if ($someMarketResultsSet[$i]['thumbnail'] === '') {
            $someMarketResultsSet[$i]['thumbnail'] = $noImageThumbnailPath;
          }
        }
      }
      unset($someMarketResultsSet);
    }
  }
  unset($key, $value);
}
