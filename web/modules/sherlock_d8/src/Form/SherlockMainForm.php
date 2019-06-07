<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-10
 * Time: 22:02
 */
namespace Drupal\sherlock_d8\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\sherlock_d8\CoreClasses\BlackMagic\BlackMagic;
use Drupal\sherlock_d8\CoreClasses\SherlockDirectory\SherlockDirectory;

class SherlockMainForm extends FormBase {
  public function getFormId() {
    return 'sherlock_main_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    //---------------- Authentication check ----------------------------------------------------------------------------
    $userAuthenticated = false;
    if (\Drupal::currentUser()->isAuthenticated()) {
      $userAuthenticated = true;
    }
    //------------------------------------------------------------------------------------------------------------------

    $step = $this->getCurrentStep($form_state);

    $form['#tree'] = TRUE;
    $form['#cache'] = ['max-age' => 0]; // Disable caching for the form
    $form['#attributes'] = ['id' => 'sherlock-main-form'];

    //Depending on which step we are on, show corresponding part of the form:
    switch ($step) {
      // ----- STEP 1. Here we show constructor and give user ability to make his own search queries. ------------------
      case 1:
        $form['#title'] = $this->t('What are you looking for? Create your perfect search query!');

        //---------------- LOAD or DELETE saved search -----------------------------------------------------------------
        $form['saved_search_selector_block'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => 'Load saved search',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        if($userAuthenticated) {

          $form['saved_search_selector_block']['saved_search_selector'] = [
            '#type' => 'select',
            '#options' => [0 => 'First option', 1 => 'Second option', 2 => 'Third more long option than previous...',],
            '#title' => $this->t('Select and load saved search'),
            '#default_value' => null,
            '#empty_option' => $this->t('Select one of...'),
            '#empty_value' => '',
          ];

          $form['saved_search_selector_block']['btn_load'] = [
            '#type' => 'submit',
            '#value' => $this->t('Load'),
            '#name' => 'btn_loadsearch',
            '#submit' => [],
          ];

          $form['saved_search_selector_block']['btn_delete'] = [
            '#type' => 'submit',
            '#value' => $this->t('Delete'),
            '#name' => 'btn_deletesearch',
            '#submit' => [],
          ];

        } else {

          $form['saved_search_selector_block']['not_auth_message'] = [
            '#type' => 'item',
            '#title' => 'Login for full access',
            '#description' => 'You need to login or register to be able to load and save searches.'
          ];

        }

        //--------------------------------------------------------------------------------------------------------------

        $fleamarketObjects = SherlockDirectory::getAvailableFleamarkets(TRUE);

        //Print out supported flea-markets. TODO: maybe this output better to do with theme function and template file?
        $formattedList = [];

        foreach ($fleamarketObjects as $object) {
          $currentMarketId = $object::getMarketId();
          $currentMarketName = $object::getMarketName();
          $currentMarketUrl = $object::getBaseURL();
          $formattedList[$currentMarketId] = $currentMarketName . ' [<a target="_blank" href="' . $currentMarketUrl . '">' . t('Open this market\' website in new tab') . '</a>]';
        }
        unset($object, $currentMarketId, $currentMarketName, $currentMarketUrl, $fleamarketObjects);

        $form['resources_chooser'] = [
          '#type' => 'checkboxes',
          '#options' => $formattedList,
          '#title' => 'Choose flea-markets websites to search on',
        ];

        $form['query_constructor_block'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => ['query-constructor-block'],
          ],
        ];

        //Check if form storage contains some explicitly saved user input. If not - we consider this is a new form.
        $userAdded = $form_state->get('user_added');

        //When user requests form first time -> we generate new block (container with one text field) and store it in $form_state['user_added']:
        if (empty($userAdded)) {
          $this->newBlock($form_state);
        }

        //In case $form_state['user_added'] was just updated by newBlock(), get it again:
        $userAdded = $form_state->get('user_added');

        //If user begun to build the query, OR requested page first time (in both cases $form_state['user_added'] already exists at this point) ->
        //we show him actual content of $form_state['user_added']:
        if(is_array($userAdded) && !empty($userAdded)) {
          foreach ($userAdded as $key => $value) {
            $form['query_constructor_block'][$key] = $value;
          }
          unset($key, $value);
        }

        //---------------- Additional search parameters block: ---------------------------------------------------------
        $form['additional_params'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => 'Additional search parameters',
        ];

        //By default, search performs only in headers, but some resources (OLX and Skylots, but not Besplatka) also supports
        //search in body. Technically, it adds specific suffix to URL.
        $form['additional_params']['dscr_chk'] = [
          '#type' => 'checkbox',
          '#title' => 'Search in descriptions too (if resource supports).',
        ];

        $form['additional_params']['filter_by_price'] = [
          '#type' => 'checkbox',
          '#title' => 'Filter items by price:',
        ];

        $form['additional_params']['price_from'] = [
          '#type' => 'textfield',
          '#title' => 'Price from',
          '#default_value' => '',
          '#size' => 10,
          '#maxlength' => 10,
          '#description' => 'UAH', //'Enter the minimal price, at which (or higher) item will be selected.',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        $form['additional_params']['price_to'] = [
          '#type' => 'textfield',
          '#title' => 'Price to',
          '#default_value' => '',
          '#size' => 10,
          '#maxlength' => 10,
          '#description' => 'UAH', //'Enter the maximum acceptable price for items.',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        //--------------------------------------------------------------------------------------------------------------

        //Create a DIV wrapper for button(s) - according to Drupal 7 best practices recommendations:
        $form['first_step_buttons_wrapper'] = ['#type' => 'actions'];

        //...and three main controls buttons () in this wrapper:
        $form['first_step_buttons_wrapper']['btn_addterm'] = [
          '#type' => 'submit',
          '#value' => 'Add Term',
          '#name' => 'btn_addterm',
          '#submit' => [
            '::addTermHandler',
          ],
          '#ajax' => [
            'callback' => '::constructorBlockAjaxReturn',
          ],
        ];

        $form['first_step_buttons_wrapper']['btn_preview'] = [
          '#type' => 'submit',
          '#value' => 'Preview Results',
          '#name' => 'btn_preview',
          '#validate' => [
            '::previewValidateHandler'
          ],
          '#submit' => [
            '::previewSubmitHandler',
          ],
        ];

        $form['first_step_buttons_wrapper']['btn_reset'] = [
          '#type' => 'submit',
          '#value' => 'Reset All',
          '#name' => 'btn_reset',
          '#limit_validation_errors' => [], //We don't want validate any errors for this submit button, because this is RESET button!
          '#submit' => [
            '::resetAllHandler',
          ],
        ];

        break;

      // ----- STEP 2. Here we show results and give user ability to save his search for future use. -------------------
      case 2:
        $form['#title'] = $this->t('Preview and save results.');

        //Attach JS and CSS for first block - 'List of constructed search queries':
        $form['#attached']['library'][] = 'sherlock_d8/display_queries_lib';

        //Attach JS and CSS for second block - with tabs and tables for output gathered information:
        $form['#attached']['library'][] = 'sherlock_d8/display_results_lib';

        //Attach array with selected markets IDs to drupalSettings object, to be accessible from JS:
        $form['#attached']['drupalSettings']['sherlock_d8']['selectedMarkets'] = $form_state->getValue(['sherlock_tmp_storage', 'selected_markets']);

        $fleamarketObjects = SherlockDirectory::getAvailableFleamarkets(TRUE);

        $outputContainers = [];
        foreach ($form_state->getValue('resources_chooser') as $marketId) { //'olx', 'bsp', 'skl', or 0 (zero).
          //Let's create div-container for every checked resource, because we need place where to output parse result
          if ($marketId === 0) {continue;}
          $outputContainers[$marketId]['market_id'] = $marketId;
          $outputContainers[$marketId]['container_title'] = $fleamarketObjects[$marketId]::getMarketName();
          $outputContainers[$marketId]['container_id'] = $marketId.'-output-block';
        }
        unset ($marketId);

        //Prepare associative array with constructed search queries to show to user. Keys of array are normal (not short!) flea-market names.
        $constructedUrlsCollection = [];
        foreach ($form_state->getValue(['sherlock_tmp_storage', 'constructed_urls_collection']) as $key => $value) {
          $userFriendlyKey = $fleamarketObjects[$key]::getMarketName();
          $constructedUrlsCollection[$userFriendlyKey] = $value;
        }
        unset($key, $value);

        //---------------- Save search for future reuse ----------------------------------------------------------------
        $form['save_search_block'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => 'Save search',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        if($userAuthenticated) {

          $form['save_search_block']['search_name_textfield'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Enter name for your search'),
          ];

          $form['save_search_block']['btn_save'] = [
            '#type' => 'submit',
            '#value' => $this->t('Save'),
            '#name' => 'btn_savesearch',
            '#submit' => [],
          ];

        } else {
          $form['save_search_block']['not_auth_message'] = [
            '#type' => 'item',
            '#title' => 'Login for full access',
            '#description' => 'You need to login or register to be able to load and save searches.'
          ];
        }
        //--------------------------------------------------------------------------------------------------------------
        //---------------- List of constructed search queries block ----------------------------------------------------
        $form['constructed_search_queries'] = [
          '#theme' => 'display_queries',
          '#_title' => $this->t('List of constructed search queries (click to show).'),
          '#constructed_urls_collection' => $constructedUrlsCollection,
          '#prefix' => '<div id="constructed-queries-block">',
          '#suffix' => '</div>',
        ];
        //--------------------------------------------------------------------------------------------------------------
        //---------------- Results output block ------------------------------------------------------------------------
        $form['display_results_area'] = [
          '#theme' => 'display_results',
          '#output_containers' => $outputContainers,
          '#prefix' => '<div id="display-results-parent-block">',
          '#suffix' => '</div>',
        ];
        //--------------------------------------------------------------------------------------------------------------
        break;

      default:
        $form['#title'] = $this->t('Error: Wrong step number!');
    }

    return $form;
  }

  protected function newBlock(FormStateInterface $form_state): void {
    $userAdded = $form_state->get('user_added');
    $newBlockNo = (is_array($userAdded)) ? count($userAdded) : 0;

    //Create a new empty container just with title:
    $newBlock = [
      '#type' => 'fieldset',
      '#title' => 'KEYWORD-'.$newBlockNo,
      '#attributes' => ['id' => 'KEYWORD-BLOCK-'.$newBlockNo],
    ];

    //Place a "Add Variation" button to this container:
    $newBlock['add_variation_button_wrapper'] = [ //Create new DIV wrapper for Add Variation button (according to Drupal 7 best practices recommendations).
      '#type' => 'actions',
      'btn_addvariation-'.$newBlockNo => [ //...and new button inside this wrapper
        '#type' => 'submit',
        '#value' => 'Add Variation',
        '#name' => 'btn_addvariation-'.$newBlockNo,
        '#submit' => ['::btnAddvariationHandler',],
        '#ajax' => ['callback' => '::constructorBlockAjaxReturn',],
      ],
    ];

    //Place a very first empty textfield to this new block. Other textfields will be added by user by pressing "Add Variation" button:
    $newBlock['VALUES'] = [
      '#type' => 'fieldset',
      '#title' => 'Keyword Variations',
      0 => $this->newVariationField($newBlockNo, 0),
    ];

    $userAdded['KEYWORD-'.$newBlockNo] = $newBlock;
    $form_state->set('user_added', $userAdded);
  }

  //This function returns a render array with new text field and attached "remove" button:
  protected function newVariationField(int $blockNumber, int $variationNumber): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline',],],
      //Textfield itself:
      'textfield' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => 'Variation '.$blockNumber.'/'.$variationNumber,
      ],
      //Button for remove this textfield:
      'rm_this_variation_btn' => [
        '#type' => 'submit',
        '#value' => 'Remove',
        '#name' => 'rm_variation_BLOCK:FIELD-'.$blockNumber.':'.$variationNumber,
        '#submit' => ['::btnRemoveThisVariation',],
        '#ajax' => ['callback' => '::constructorBlockAjaxReturn',],
        '#limit_validation_errors' => [],
      ]
    ];
  }

  protected function getMaxNumericArrayKey(array $array): int {
    $maxKey = null;

    foreach ($array as $key => $value) {
      if (!is_numeric($key)) {continue;}

      if ($maxKey === null) {
        $maxKey = $key;
      }

      if ($key > $maxKey) {
        $maxKey = $key;
      }
    }
    unset($key, $value);

    return $maxKey;
  }

  public function btnAddvariationHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if (!((bool) strstr($pressedBtnName, 'btn_addvariation'))) {return;}

    $btnId = (int) explode('-', $pressedBtnName)[1];
    $currentBlockKeywordVariations = $form_state->getValue(['query_constructor_block', 'KEYWORD-'.$btnId, 'VALUES']);

    $variationNo = (is_array($currentBlockKeywordVariations) && (!empty($currentBlockKeywordVariations))) ? ($this->getMaxNumericArrayKey($currentBlockKeywordVariations) + 1) : 0;

    $newfield = $this->newVariationField($btnId, $variationNo);

    $userAdded = $form_state->get('user_added');
    array_push($userAdded['KEYWORD-'.$btnId]['VALUES'], $newfield);
    $form_state->set('user_added', $userAdded);

    $form_state->setRebuild();
  }

  public function addTermHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_addterm') {return;}

    $this->newBlock($form_state);

    $form_state->setRebuild();
  }

  public function resetAllHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_reset') {return;}

    $form_state->set('user_added', null);

    //And we don't need $form_state->setRebuild(); here, because we want absolutely clean new form!
  }

  public function btnRemoveThisVariation(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if (!((bool) strstr($pressedBtnName, 'rm_variation_BLOCK:FIELD'))) {return;}

    //Get button address in format BLOCK_NUMBER:FIELD_NUMBER
    $buttonAddressString = explode('-', $pressedBtnName)[1];

    //Explode button address to array, where [0] => BLOCK_NUMBER, [1] => FIELD_NUMBER
    $buttonAddressArray = explode(':', $buttonAddressString);

    $buttonBlockNo = (int) $buttonAddressArray[0];
    $buttonVariationNo = (int) $buttonAddressArray[1]; //Pressed button detected! We are ready to remove corresponding field!

    $user_added = $form_state->get('user_added');
    unset($user_added['KEYWORD-'.$buttonBlockNo]['VALUES'][$buttonVariationNo]); //Field #$buttonVariationNo from block #$buttonBlockNo now DELETED.

    $form_state->set('user_added', $user_added);

    $form_state->setRebuild();
  }

  public function previewValidateHandler(array &$form, FormStateInterface $form_state) {
    //Check if at least one of the resources to search is checked (olx, besplatka, skylots...):
    $resources = $form_state->getValue('resources_chooser');
    $atLeastOneSelected = FALSE;
    foreach ($resources as $value) {
      if ($value !== 0) {
        $atLeastOneSelected = TRUE;
        break;
      }
    }
    if (!$atLeastOneSelected) {
      $form_state->setErrorByName('resources_chooser', 'Please, select at least one resource where to search.');
    }

    //Check if array of keywords is not empty, and at least one keyword present:
    $wholeConstructorBlock = $form_state->getValue('query_constructor_block');
    $atLeastOneKeywordPresent = FALSE;
    foreach ($wholeConstructorBlock as $keyword_N_Block) {
      if (array_key_exists('VALUES', $keyword_N_Block)) {
        $atLeastOneKeywordPresent = TRUE;
        break;
      }
    }
    if (!$atLeastOneKeywordPresent) {
      $form_state->setErrorByName('query_constructor_block', 'No keywords specified to search. Add at least one keyword or phrase.');
    }
  }

  public function previewSubmitHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_preview') {return;}

    $sherlockTempStorage = []; //Initialize temporary storage. At the end of this function, we'll put it to $form_state.

    //Get flag, which indicates - search title only or body too:
    $checkDescriptionToo = $form_state->getValue(['additional_params', 'dscr_chk']) === 0 ? FALSE : TRUE;

    //Get price limits, if they are available:
    $enablePriceFilter = $form_state->getValue(['additional_params', 'filter_by_price']) === 0 ? FALSE : TRUE;
    $priceFrom = ($enablePriceFilter && !empty($form_state->getValue(['additional_params', 'price_from']))) ? intval($form_state->getValue(['additional_params', 'price_from'])) : null;
    $priceTo = ($enablePriceFilter && !empty($form_state->getValue(['additional_params', 'price_to']))) ? intval($form_state->getValue(['additional_params', 'price_to'])) : null;

    //Save all block values\variations to separate array.
    $blockValues = [];
    $filteredValues = []; //Text field's values are "glued" together with their "Remove" buttons. We need detach "Remove" buttons, because we need only clean values.
    $wholeConstructorBlock = $form_state->getValue('query_constructor_block');

    foreach ($wholeConstructorBlock as $keyword_N_Block) {
      foreach ($keyword_N_Block['VALUES'] as $value) {
        $filteredValues[] = $value['textfield'];
      }
      unset ($value);

      $blockValues[] = $filteredValues; //Each value of $blockValues is ARRAY with combinations of one given keyword (like sony, soni).

      $filteredValues = []; //Clean temporary storage after processing each keyword block.
    }
    unset($keyword_N_Block);

    //Now let's request list of all resources and their settings.
    $resourcesList = [];
    $resourcesList = SherlockDirectory::getAvailableFleamarkets(TRUE);

    //Array with all search URLs for all user-specified resources. This collection of URL we will use to cURL each of them at the next step.
    //All values are splitted by nested arrays. Keys to nested arrays are names of the resources.
    $constructedUrlsCollection = [];

    //Temporary storage for selected markets IDs.
    $sherlockTempStorage['selected_markets'] = [];

    $keywordsCombinations = BlackMagic::generateAllPossibleCombinations($blockValues);
    $keywordsCombinationsCount = count($keywordsCombinations);
    foreach ($form_state->getValue('resources_chooser') as $key => $value) { //$key here is flea market ID, like olx, bsp, skl
      if ($value !== 0) { //we take into consideration only checked resources, if checkbox unchecked its value == 0
        for ($i = 0; $i < $keywordsCombinationsCount; $i++) {
          $constructedUrlsCollection[$key][] = $resourcesList[$key]::makeRequestURL($keywordsCombinations[$i], $priceFrom, $priceTo, $checkDescriptionToo);
        }

        //Check constructed URLs, and left only unique of them:
        $constructedUrlsCollection[$key] = array_unique($constructedUrlsCollection[$key]);

        //Save selected (ONLY SELECTED!) fleamarkets to $form_state['sherlock_tmp_storage']['selected_markets'],
        //we'll pass them to frontend by attaching to drupalSettings object at step 2 of the main form:
        $sherlockTempStorage['selected_markets'][] = $key;
      }
    }
    unset($key, $value);

    $sherlockTempStorage['constructed_urls_collection'] = $constructedUrlsCollection;
    $sherlockTempStorage['price_from'] = $priceFrom;
    $sherlockTempStorage['price_to'] = $priceTo;

    $form_state->setValue('sherlock_tmp_storage', $sherlockTempStorage);

    //TODO: Maybe refactor this:
    $_SESSION['sherlock_tmp_storage'] = $sherlockTempStorage; //Also, save our tmp_storage to session.

    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*- LET'S THE PARTY BEGIN! *-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //At this point, we have enough info at $form_state['sherlock_tmp_storage'] to start fetch and parsing process:
    //$form_state['sherlock_tmp_storage']['selected_markets'] contains just user-selected markets to fetch,
    //$form_state['sherlock_tmp_storage']['constructed_urls_collection'] contains sub-arrays with collection of search queries for each market.
    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-

    $this->setNextStep($form_state, 2);
    $form_state->setRebuild();
  }

  public function constructorBlockAjaxReturn(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#query-constructor-block', $form['query_constructor_block']));
    return $response;
  }

  protected function getCurrentStep(FormStateInterface $form_state) {
    //If page requested 1st time (so currentStep is undefined) -> get user to first step. If user already explored the form -> get current step.
    $step = $form_state->get('currentStep');

    if ($step === null) {
      $step = 1;
      $form_state->set('currentStep', 1);
    }

    return $step;
  }

  protected function setNextStep(FormStateInterface $form_state, int $step) {
    $form_state->set('currentStep', $step);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }
}
