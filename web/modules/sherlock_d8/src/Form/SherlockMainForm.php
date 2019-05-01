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
use Drupal\sherlock_d8\CoreClasses\TextUtilities\TextUtilities;

class SherlockMainForm extends FormBase {
  public function getFormId() {
    return 'sherlock_main_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $step = $this->getCurrentStep($form_state);

    $form['#tree'] = TRUE;
    $form['#cache'] = ['max-age' => 0]; // Disable caching for the form
    $form['#attributes'] = ['id' => 'sherlock-main-form'];

    //Depending on which step we are on, show corresponding part of the form:
    switch ($step) {
      // ----- STEP 1. Here we show constructor and give user ability to make his own search queries. ------------------
      case 1:
        $form['#title'] = $this->t('What are you looking for? Create your perfect search query!');

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

        //include 'sherlock_ui_form_step_2.php';

        break;

      default:
        $form['#title'] = $this->t('Error: Wrong step number!');
    }

    return $form;
  }

  protected function newBlock(FormStateInterface $form_state) {
    $userAdded = $form_state->get('user_added');
    $newBlockNo = (is_array($userAdded)) ? count($userAdded) : 0;

    //Create a new empty container just with title:
    $newBlock = [
      '#type' => 'fieldset',
      '#title' => 'KEYWORD-'.$newBlockNo,
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
      0 => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => 'Variation '.$newBlockNo.'/'.'0',
      ],
    ];

    $userAdded['KEYWORD-'.$newBlockNo] = $newBlock;
    $form_state->set('user_added', $userAdded);
  }

  public function btnAddvariationHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if (!((bool) strstr($pressedBtnName, 'btn_addvariation'))) {return;}

    $btnId = (int) explode('-', $pressedBtnName)[1];
    $currentBlockKeywordVariations = $form_state->getValue(['query_constructor_block', 'KEYWORD-'.$btnId, 'VALUES']);
    $variationNo = is_array($currentBlockKeywordVariations) ? count($currentBlockKeywordVariations) : 0;

    $newfield = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => 'Variation '.$btnId.'/'.$variationNo,
    ];

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

  public function previewValidateHandler(array &$form, FormStateInterface $form_state) {
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
  }

  public function previewSubmitHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_preview') {return;}

    $_SESSION['sherlock_d8'] = []; //Clean session from previous use.

    //Get flag, which indicates - search title only or body too:
    $checkDescriptionToo = $form_state->getValue(['additional_params', 'dscr_chk']) === 0 ? FALSE : TRUE;

    //Get price limits, if they are available:
    $enablePriceFilter = $form_state->getValue(['additional_params', 'filter_by_price']) === 0 ? FALSE : TRUE;
    $priceFrom = ($enablePriceFilter && !empty($form_state->getValue(['additional_params', 'price_from']))) ? intval($form_state->getValue(['additional_params', 'price_from'])) : null;
    $priceTo = ($enablePriceFilter && !empty($form_state->getValue(['additional_params', 'price_to']))) ? intval($form_state->getValue(['additional_params', 'price_to'])) : null;

    //Save all block values\variations to separate array. Strictly speaking, we don't need to check if key is starts with 'KEYWORD-', because all 1st level keys are 'KEYWORD-'.
    //But left this check just for reliability.
    $blockValues = [];
    foreach ($form_state->getValue('query_constructor_block') as $key => $value) {
      if (TextUtilities::startsWith($key, 'KEYWORD-')) {
        $blockValues[] = $value['VALUES']; //Each value of $blockValues is ARRAY with combinations of one given keyword (like sony, soni).
      }
    }

    //Now let's request list of all resources and their settings.
    $resourcesList = [];
    $resourcesList = SherlockDirectory::getAvailableFleamarkets(TRUE);

    //Array with all search URLs for all user-specified resources. This collection of URL we will use to cURL each of them at the next step.
    //All values are splitted by nested arrays. Keys to nested arrays are names of the resources.
    $constructedUrlsCollection = [];

    $keywordsCombinations = BlackMagic::generateAllPossibleCombinations($blockValues);
    $keywordsCombinationsCount = count($keywordsCombinations);
    foreach ($form_state->getValue('resources_chooser') as $key => $value) { //$key here is flea market ID, like olx, bsp, skl
      if ($value !== 0) { //we take into consideration only checked resources, if checkbox unchecked its value == 0
        for ($i = 0; $i < $keywordsCombinationsCount; $i++) {
          $constructedUrlsCollection[$key][] = $resourcesList[$key]::makeRequestURL($keywordsCombinations[$i], $priceFrom, $priceTo, $checkDescriptionToo);
        }

        //Check constructed URLs, and left only unique of them:
        $constructedUrlsCollection[$key] = array_unique($constructedUrlsCollection[$key]);

        //Save selected (ONLY SELECTED!) fleamarkets to $_SESSION,
        //(we'll take them by ajax from there in a moment from frontend, see $items['sherlock/selected-markets'] endpoint in sherlock.module):
        $_SESSION['sherlock_d8']['selected_markets'][] = $key;
      }
    }
    unset($key, $value);

    $_SESSION['sherlock_d8']['constructed_urls_collection'] = $constructedUrlsCollection;
    $_SESSION['sherlock_d8']['price_from'] = $priceFrom;
    $_SESSION['sherlock_d8']['price_to'] = $priceTo;

    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*- LET'S THE PARTY BEGIN! *-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //At this point, we have enough info at $_SESSION['sherlock_d8'] to start fetch and parsing process:
    //$_SESSION['sherlock_d8']['selected_markets'] contains just user-selected markets to fetch,
    //$_SESSION['sherlock_d8']['constructed_urls_collection'] contains sub-arrays with collection of search queries for each market.
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