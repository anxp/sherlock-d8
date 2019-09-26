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

  //Add "no image thumbnail", and break titles lines by 20 symbols for all items in new_results array:
  foreach ($variables as $key => &$value) {
    if ($key === 'new_results') {
      foreach ($value as &$someMarketResultsSet) {
        $marketResultsCount = count($someMarketResultsSet);
        for ($i = 0; $i < $marketResultsCount; $i++) {
          if ($someMarketResultsSet[$i]['thumbnail'] === '') {
            $someMarketResultsSet[$i]['thumbnail'] = $noImageThumbnailPath;
          }

          $someMarketResultsSet[$i]['title'] = \Drupal\sherlock_d8\CoreClasses\TextUtilities\TextUtilities::chunkSplitUnicode($someMarketResultsSet[$i]['title'], 18);
        }
      }
      unset($someMarketResultsSet);
    }
  }
  unset($key, $value);
}


