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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sherlock_d8\CoreClasses\BlackMagic\BlackMagic;
use Drupal\sherlock_d8\CoreClasses\SherlockDirectory\SherlockDirectory;
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;

class SherlockMainForm extends FormBase {
  /**
   * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
   */
  protected $dbConnection;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface $messenger
   */
  protected $messenger;

  /**
   * Constructs a new SherlockMainForm object.
   * @param \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
   */
  public function __construct(DatabaseManager $dbConnection, MessengerInterface $messenger) {
    $this->dbConnection = $dbConnection;
    $this->messenger = $messenger;
  }

  public static function create(ContainerInterface $container) {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = $container->get('sherlock_d8.database_manager');

    /**
     * @var \Drupal\Core\Messenger\MessengerInterface $messenger
     */
    $messenger = $container->get('messenger');

    return new static($dbConnection, $messenger);
  }

  public function getFormId() {
    return 'sherlock_main_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    //---------------- Authentication check ----------------------------------------------------------------------------
    $userAuthenticated = false;
    if ($this->currentUser()->isAuthenticated()) {
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
        $recordIdToLoad = intval($form_state->get('record_id_to_load'));

        $form['saved_search_selector_block'] = [
          '#type' => 'details',
          '#open' => (bool) $recordIdToLoad, //If recordIdToLoad == 0 (not set), this property will be == FALSE, so block will be rendered as closed.
          '#title' => 'Load saved search',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        if($userAuthenticated) {

          //Load CURRENT USER's list of saved searches:
          $recordsSelectCriterea = [
            'uid' => $this->currentUser()->id(),
          ];
          $currentUserRecords = $this->dbConnection->selectTable('sherlock_user_input')->setFieldsToGet(['id', 'name'])->selectRecords($recordsSelectCriterea, 'id');

          //We also need to rebuild a bit our user records, make it more flat, because now it 2-dimensional:
          foreach ($currentUserRecords as &$record) {
            $record = $record['name'];
          }
          unset($record);

          $form['saved_search_selector_block']['saved_search_selector'] = [
            '#type' => 'select',
            '#options' => $currentUserRecords,
            '#title' => $this->t('Select and load saved search'),
            '#default_value' => $recordIdToLoad === 0 ? null : $recordIdToLoad,
            '#empty_option' => $this->t('Select one of...'),
            '#empty_value' => 0,
          ];

          $form['saved_search_selector_block']['btn_load'] = [
            '#type' => 'submit',
            '#value' => $this->t('Load'),
            '#name' => 'btn_loadsearch',
            '#limit_validation_errors' => [['saved_search_selector_block', 'saved_search_selector',],],
            '#submit' => [
              '::loadSearchHandler'
            ],
          ];

          $form['saved_search_selector_block']['btn_delete'] = [
            '#type' => 'submit',
            '#value' => $this->t('Delete'),
            '#name' => 'btn_deletesearch',
            '#limit_validation_errors' => [],
            '#submit' => [
              '::deleteSearchHandler'
            ],
          ];

        } else {

          $form['saved_search_selector_block']['not_auth_message'] = [
            '#type' => 'item',
            '#title' => $this->t('Login for full access'),
            '#description' => $this->t('You need to login or register to be able to load and save searches.'),
          ];

        }
        //--------------------------------------------------------------------------------------------------------------

        $fleamarketObjects = SherlockDirectory::getAvailableFleamarkets(TRUE);

        //Print out supported flea-markets. TODO: maybe this output better to do with theme function and template file?
        $formattedList = [];

        /**
         * @var \Drupal\sherlock_d8\CoreClasses\MarketReference\iMarketReference $object
         */

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
          '#value' => 'Add Keyword',
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

        //TODO: Make a method from this. And check if $formStateValuesSnapshot is not empty before iterate it:
        //----------Prepopulate QUERY CONSTRUCTOR with saved values, if there are ones:---------------------------------
        $formStateValuesSnapshot = $form_state->get(['form_state_values_snapshot']);

        foreach ($formStateValuesSnapshot as $logicBlockKey => $logicBlockValues) {
          //Check if $logicBlockValue contains something - we just want to skip a buttons or empty wrappers:
          if (empty($logicBlockValues) || !is_array($logicBlockValues)) {continue;}

          //Check if current element is saved search selector dropdown menu,
          //if it is - just skip it, because correct default value is already set to it.
          if ($logicBlockKey === 'saved_search_selector_block') {continue;}

          foreach ($logicBlockValues as $itemKey => $itemValue) {
            if (is_array($itemValue)) {
              for ($i = 0; $i < count($itemValue['VALUES']); $i++) {
                $form[$logicBlockKey][$itemKey]['VALUES'][$i]['textfield']['#value'] = $itemValue['VALUES'][$i]['textfield'];
              }
            } else {
              $form[$logicBlockKey][$itemKey]['#value'] = $itemValue;
            }
          }
          unset($itemKey, $itemValue);
        }
        unset($logicBlockKey, $logicBlockValues);

        //--------------------------------------------------------------------------------------------------------------

        break;

      // ----- STEP 2. Here we show results and give user ability to save his search for future use. -------------------
      case 2:
        $form['#title'] = $this->t('Preview and save results.');

        //Attach CSS for first block - 'List of constructed search queries':
        $form['#attached']['library'][] = 'sherlock_d8/display_queries_lib';

        //Attach JS and CSS for second block - with tabs and tables for output gathered information:
        $form['#attached']['library'][] = 'sherlock_d8/display_results_lib';

        //Attach array with selected markets IDs to drupalSettings object, to be accessible from JS:
        $form['#attached']['drupalSettings']['sherlock_d8']['selectedMarkets'] = $form_state->get(['sherlock_tmp_storage', 'selected_markets']);

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
        foreach ($form_state->get(['sherlock_tmp_storage', 'constructed_urls_collection']) as $key => $value) {
          $userFriendlyKey = $fleamarketObjects[$key]::getMarketName();
          $constructedUrlsCollection[$userFriendlyKey] = $value;
        }
        unset($key, $value);

        //---------------- Back button ---------------------------------------------------------------------------------
        $form['navigation'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'container-inline',
            ],
          ],
        ];

        $form['navigation']['btn_get_first_step'] = [
          '#type' => 'submit',
          '#value' => $this->t('Back to constructor'),
          '#name' => 'btn_get_first_step',
          '#limit_validation_errors' => [],
          '#validate' => [],
          '#submit' => [
            '::getFirstStepSubmit'
          ],
        ];

        $form['navigation']['btn_reset_and_get_first_step'] = [
          '#type' => 'submit',
          '#value' => $this->t('Reset and back to constructor'),
          '#name' => 'btn_reset_and_get_first_step',
          '#limit_validation_errors' => [],
          '#validate' => [],
          '#submit' => [
            '::getFirstStepSubmit'
          ],
        ];
        //---------------- Save search for future reuse ----------------------------------------------------------------
        $form['save_search_block'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $this->t('Save search'),
        ];

        if($userAuthenticated) {

          $form['save_search_block']['first_inline_container'] = [
            '#type' => 'container',
            '#prefix' => '<div class="container-inline">',
            '#suffix' => '</div>',
          ];

          $form['save_search_block']['first_inline_container']['search_name_textfield'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Enter name for your search'),
            '#required' => TRUE,
            '#maxlength' => 255, //This field defined as VARCHAR(255) in DB.
          ];

          $form['save_search_block']['second_inline_container'] = [
            '#type' => 'container',
            '#prefix' => '<div class="container-inline">',
            '#suffix' => '</div>',
          ];

          $form['save_search_block']['second_inline_container']['storing_period_selector'] = [
            '#type' => 'select',
            '#options' => [1 => '1 day', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '365 days'],
            '#title' => $this->t('How long to store and maintain your saved search?'),
            '#default_value' => 7,
          ];

          $form['save_search_block']['buttons_block'] = [
            '#type' => 'actions',
          ];

          //Button for SAVE NEW RECORD operation:
          $form['save_search_block']['buttons_block']['btn_save'] = [
            '#type' => 'submit',
            '#value' => $this->t('Save'),
            '#name' => 'btn_savesearch',
            '#validate' => [
              '::saveUpdateValidate'
            ],
            '#submit' => [
              '::saveSubmit',
            ],
          ];

          //Button for OVERWRITE EXISTING RECORD operation:
          $form['save_search_block']['buttons_block']['btn_overwrite'] = [
            '#type' => 'submit',
            '#value' => $this->t('Save and overwrite if exists'),
            '#name' => 'btn_overwriteexisting',
            '#validate' => [
              '::saveUpdateValidate'
            ],
            '#submit' => [
              '::overwriteSubmit',
            ],
          ];

        } else {
          $form['save_search_block']['not_auth_message'] = [
            '#type' => 'item',
            '#title' => $this->t('Login for full access'),
            '#description' => $this->t('You need to login or register to be able to load and save searches.'),
          ];
        }
        //--------------------------------------------------------------------------------------------------------------
        //---------------- List of constructed search queries block ----------------------------------------------------
        $form['constructed_search_queries'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $this->t('List of constructed search queries'),
          '#theme' => 'display_queries',
          '#constructed_urls_collection' => $constructedUrlsCollection,
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
        '#value' => 'Add Word Spelling Variant',
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

  /**
   * A handler function for Load button. It takes serialized form structure and form values from DB, unserialize it,
   * and put to form_state->storage, where it will be checked on form build process. So, form will be pre-populated with values,
   * and has appropriate structure, if user requested one of saved form from DB.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function loadSearchHandler(array &$form, FormStateInterface $form_state) {
    //Get id of selected search\record:
    $recordIdToLoad = intval($form_state->getValue(['saved_search_selector_block', 'saved_search_selector']));

    //Get current user ID (for security reasons - we will check DB for record with specified recordId AND userId,
    //because recordId user can submit by any POST application and such way get access to records owned by other users.
    //Checking for recordId AND userId is more secure, because user can't submit another's userId):
    $currentUserId = $this->currentUser()->id();

    if ($recordIdToLoad > 0 && $currentUserId > 0) {
      $selectionCriterion = [
        'id' => $recordIdToLoad,
        'uid' => $currentUserId,
      ];

      //Load record from DB by it recordId AND userId:
      $savedSearchData = $this->dbConnection->selectTable('sherlock_user_input')->setFieldsToGet(['id', 'serialized_form_structure', 'serialized_form_values'])->selectRecords($selectionCriterion, 'id');

      $savedSearchData = array_shift($savedSearchData);

      $formStructure = unserialize($savedSearchData['serialized_form_structure']);
      $formValues = unserialize($savedSearchData['serialized_form_values']);

      $form_state->set('user_added', $formStructure);
      $form_state->set('form_state_values_snapshot', $formValues);
      $form_state->set('record_id_to_load', $recordIdToLoad);

      $form_state->setRebuild();
    } else {

      //If user selected default option from dropdown (or, in other words, does not selected any saved search, and $recordIdToLoad === 0) -> just reset form to default\empty:
      $form_state->setRebuild(FALSE);
    }
  }

  public function deleteSearchHandler(array &$form, FormStateInterface $form_state) {

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

    $form_state->set('sherlock_tmp_storage', $sherlockTempStorage);

    //TODO: Maybe refactor this:
    $_SESSION['sherlock_tmp_storage'] = $sherlockTempStorage; //Also, save our tmp_storage to session.

    //Save all submitted form state values, to pass them to 2nd step and get access there (for serialize and save to DB):
    $form_state->set('form_state_values_snapshot', $form_state->cleanValues()->getValues());

    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*- LET'S THE PARTY BEGIN! *-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-
    //At this point, we have enough info at $form_state['sherlock_tmp_storage'] to start fetch and parsing process:
    //$form_state['sherlock_tmp_storage']['selected_markets'] contains just user-selected markets to fetch,
    //$form_state['sherlock_tmp_storage']['constructed_urls_collection'] contains sub-arrays with collection of search queries for each market.
    //*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-

    $this->setNextStep($form_state, 2);
    $form_state->setRebuild();
  }

  public function getFirstStepSubmit(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';

    if ($pressedBtnName === 'btn_get_first_step') {
      $this->setNextStep($form_state, 1);
      $form_state->setRebuild();
    }

    if ($pressedBtnName === 'btn_reset_and_get_first_step') {
      $this->setNextStep($form_state, 1);
      //As we don't set setRebuilt() here, all form_state->storage will be lost,
      //so effectively form will be reset to default.
    }
  }

  public function saveUpdateValidate(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';

    $searchName = $form_state->getValue(['save_search_block', 'first_inline_container', 'search_name_textfield']);
    $searchNameSanitized = filter_var($searchName, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);

    $storingPeriod = $form_state->getValue(['save_search_block', 'second_inline_container', 'storing_period_selector']);
    $storingPeriodSanitized = intval($storingPeriod);

    //Calculate md5 hash of name of the search:
    $searchNameMD5Hash = hash('md5', $searchName);

    $dataToCheck = [
      'uid' => $this->currentUser()->id(),
      'name_hash' => $searchNameMD5Hash,
    ];

    if ($searchName !== $searchNameSanitized) {
      $form_state->setErrorByName('save_search_block][first_inline_container][search_name_textfield', 'Please, provide correct name for your search.');
    }

    if ($storingPeriodSanitized > 365) {
      $form_state->setErrorByName('save_search_block][second_inline_container][storing_period_selector', 'Select how many days your search will be kept.');
    }

    if ($pressedBtnName === 'btn_savesearch') {
      //Check if data with given user ID and name already exists:
      if ($this->dbConnection->selectTable('sherlock_user_input')->checkIfRecordExists($dataToCheck)) {
        $form_state->setErrorByName('save_search_block][first_inline_container][search_name_textfield', 'This name is already taken, we can\'t save new search with the same name. But you can overwrite existing search with new data.');
      }
    }

    if ($pressedBtnName === 'btn_overwriteexisting') {
      //Check if record we going to UPDATE is EXISTS:
      if (!$this->dbConnection->selectTable('sherlock_user_input')->checkIfRecordExists($dataToCheck)) {
        $form_state->setErrorByName('save_search_block][first_inline_container][search_name_textfield', 'A record with the specified name does not exist. Use "Save" button to save new record.');
      }
    }

  }

  public function saveSubmit(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_savesearch') {return;}

    //Get structure of user-configured block with keywords (this is only structure, values we'll get in next step...):
    $userAdded = $form_state->get('user_added');

    //Get values (not only of user-configured block with keywords but of whole form):
    $formStateValuesSnapshot = $form_state->get(['form_state_values_snapshot']);

    //Get user-given name for search:
    $searchName = $form_state->getValue(['save_search_block', 'first_inline_container', 'search_name_textfield']);

    //Calculate md5 hash of name of the search:
    $searchNameMD5Hash = hash('md5', $searchName);

    //Get storage time:
    $storingPeriod = $form_state->getValue(['save_search_block', 'second_inline_container', 'storing_period_selector']);
    $storingPeriodSanitized = intval($storingPeriod);

    $dataToInsert = [
      'uid' => $this->currentUser()->id(),
      'created' => time(),
      'changed' => time(),
      'name' => $searchName,
      'name_hash' => $searchNameMD5Hash,
      'serialized_form_structure' => serialize($userAdded),
      'serialized_form_values' => serialize($formStateValuesSnapshot),
      'keep_alive_days' => $storingPeriodSanitized,
      'delete' => 0,
    ];

    if ($this->dbConnection->setData($dataToInsert)->selectTable('sherlock_user_input')->insertRecord()) {
      $this->messenger->addStatus('Search settings and parameters were successfully saved.');
    } else {
      $this->messenger->addError('An unexpected error occurred on saving.');
    }
  }

  public function overwriteSubmit(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_overwriteexisting') {return;}

    //Get structure of user-configured block with keywords (this is only structure, values we'll get in next step...):
    $userAdded = $form_state->get('user_added');

    //Get values (not only of user-configured block with keywords but of whole form):
    $formStateValuesSnapshot = $form_state->get(['form_state_values_snapshot']);

    //Get user-given name for search:
    $searchName = $form_state->getValue(['save_search_block', 'first_inline_container', 'search_name_textfield']);

    //Calculate md5 hash of name of the search:
    $searchNameMD5Hash = hash('md5', $searchName);

    //Get storage time:
    $storingPeriod = $form_state->getValue(['save_search_block', 'second_inline_container', 'storing_period_selector']);
    $storingPeriodSanitized = intval($storingPeriod);

    $dataToUpdateExistingRecord = [
      //'uid' => $this->currentUser()->id(),
      //'created' => time(),
      'changed' => time(),
      //'name' => $searchName,
      //'name_hash' => $searchNameMD5Hash,
      'serialized_form_structure' => serialize($userAdded),
      'serialized_form_values' => serialize($formStateValuesSnapshot),
      'keep_alive_days' => $storingPeriodSanitized,
      'delete' => 0,
    ];

    $whereClause = [
      'uid' => $this->currentUser()->id(),
      'name_hash' => $searchNameMD5Hash,
    ];

    if ($this->dbConnection->setData($dataToUpdateExistingRecord)->selectTable('sherlock_user_input')->updateRecords($whereClause)) {
      $this->messenger->addStatus('Existing search successfully updated.');
    } else {
      $this->messenger->addError('No records were updated, nothing to change.');
    }
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
