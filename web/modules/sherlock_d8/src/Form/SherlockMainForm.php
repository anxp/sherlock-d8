<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-04-10
 * Time: 22:02
 */
namespace Drupal\sherlock_d8\Form;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Extension\ModuleHandlerInterface;

use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockSearchEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\iSherlockTaskEntity;
use Drupal\sherlock_d8\CoreClasses\SherlockEntity\SherlockEntity;
use Drupal\sherlock_d8\CoreClasses\BlackMagic\BlackMagic;
use Drupal\sherlock_d8\CoreClasses\FleaMarket\{FleaMarket, olx_FleaMarket, bsp_FleaMarket, skl_FleaMarket};
use Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager;
use Drupal\sherlock_d8\CoreClasses\TaskLauncher\TaskLauncher;
use Drupal\sherlock_d8\CoreClasses\MessageCollector\MessageCollector;

use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;

class SherlockMainForm extends FormBase {
  /**
   * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
   */
  protected $dbConnection = null;

  /**
   * The module handler service.
   * @var \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   */
  protected $moduleHandler = null;

  /**
   * Task launcher service.
   * @var TaskLauncher
   */
  protected $taskLauncher = null;

  /**
   * @var MessageCollector $messageCollector
   */
  protected $messageCollector = null;

  //ID of loaded saved search
  protected $recordID = null;

  /**
   * Constructs a new SherlockMainForm object.
   * @param \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
   * @param ModuleHandlerInterface $moduleHandler
   * @param TaskLauncher $taskLauncher
   * @param MessageCollector $messageCollector
   */
  public function __construct(DatabaseManager $dbConnection, ModuleHandlerInterface $moduleHandler, TaskLauncher $taskLauncher, MessageCollector $messageCollector) {
    $this->dbConnection = $dbConnection;
    $this->moduleHandler = $moduleHandler;
    $this->taskLauncher = $taskLauncher;
    $this->messageCollector = $messageCollector;
  }

  public static function create(ContainerInterface $container) {
    /**
     * @var \Drupal\sherlock_d8\CoreClasses\DatabaseManager\DatabaseManager $dbConnection
     */
    $dbConnection = $container->get('sherlock_d8.database_manager');

    /**
     * @var \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
     */
    $moduleHandler = $container->get('module_handler');

    /**
     * @var TaskLauncher $taskLauncher
     */
    $taskLauncher = $container->get('sherlock_d8.task_launcher');

    /**
     * @var MessageCollector $messageCollector
     */
    $messageCollector = $container->get('sherlock_d8.message_collector');

    return new static($dbConnection, $moduleHandler, $taskLauncher, $messageCollector);
  }

  public function getFormId() {
    return 'sherlock_main_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $recordID = null) {
    //================ IMPORTANT VARIABLES INITIALIZATION ==============================================================
    $userAuthenticated = false;
    if ($this->currentUser()->isAuthenticated()) {
      $userAuthenticated = true;
    }

    $userID = $this->currentUser()->id();

    $recordID = intval($recordID); //Validate recordID because user can put anything here!
    $this->recordID = $recordID;

    $step = $this->getCurrentStep($form_state);
    $isUserInteractsWithForm = (bool) $form_state->get('does_user_altering_form');
    //------------------------------------------------------------------------------------------------------------------

    $form['#tree'] = TRUE;
    $form['#cache'] = ['max-age' => 0]; // Disable caching for the form
    $form['#attributes'] = ['id' => 'sherlock-main-form'];

    //Depending on which step we are on, show corresponding part of the form:
    switch ($step) {
      //====== STEP 1. Here we show constructor and give user ability to make his own search queries. ==================
      //====== Or, load previously saved search. =======================================================================
      case 1:
        //We load form from DB only on first page\form request.
        //If user started to edit form (add or remove keywords or keyword variations) -> we skip loading form from DB because it's not actual anymore.
        if (!$isUserInteractsWithForm) {
          $requestedFormContent = $this->loadSavedForm($recordID);
        }

        $form['#title'] = $this->t('What are you looking for? Create your perfect search query!');

        //---------------- LOAD / DELETE saved search block ------------------------------------------------------------
        $form['saved_search_block'] = [
          '#type' => 'details',
          '#open' => FALSE, //Block is closed by default, but it will be rendered as open if any record (by record ID) is selected to load, see method "injectFormValues()"
          '#title' => $this->t('Load saved search'),
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        if($userAuthenticated) {

          //Load CURRENT USER's list of saved searches:
          $recordsSelectCriterea = [
            'uid' => $this->currentUser()->id(),
          ];
          $currentUserRecords = $this->dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->selectRecords($recordsSelectCriterea, 'id');

          //We also need to rebuild a bit our user records, make it more flat, because now it 2-dimensional:
          foreach ($currentUserRecords as &$record) {
            $record = $record['name'];
          }
          unset($record);

          $form['saved_search_block']['saved_search_id'] = [
            '#type' => 'select',
            '#options' => $currentUserRecords,
            '#title' => $this->t('Select and load saved search'),
            '#default_value' => 0,
            '#empty_option' => $this->t('Select one of...'),
            '#empty_value' => 0,
          ];

          $form['saved_search_block']['btn_load'] = [
            '#type' => 'submit',
            '#value' => $this->t('Load'),
            '#name' => 'btn_loadsearch',
            '#limit_validation_errors' => [['saved_search_block', 'saved_search_id',],],
            '#submit' => [
              '::loadSearchHandler'
            ],
          ];

          $form['saved_search_block']['btn_delete'] = [
            '#type' => 'submit',
            '#value' => $this->t('Delete'),
            '#name' => 'btn_deletesearch',
            '#limit_validation_errors' => [['saved_search_block', 'saved_search_id',],],
            '#submit' => [
              '::deleteSearchHandler'
            ],
          ];

        } else {

          $form['saved_search_block']['not_auth_message'] = [
            '#type' => 'item',
            '#title' => $this->t('Login for full access'),
            '#description' => $this->t('You need to login or register to be able to load and save searches.'),
          ];

        }
        //--------------------------------------------------------------------------------------------------------------

        $fleamarketsProperties = FleaMarket::getSupportedMarketsList(TRUE);

        //Print out supported flea-markets. TODO: maybe this output better to do with theme function and template file?
        $formattedList = [];
        $resourcesChooserDefault = [];

        foreach ($fleamarketsProperties as $marketRecord) {
          $currentMarketId = $marketRecord['marketID'];
          $currentMarketName = $marketRecord['marketName'];
          $currentMarketUrl = $marketRecord['marketURL'];
          $formattedList[$currentMarketId] = $currentMarketName . ' [<a target="_blank" href="' . $currentMarketUrl . '">' . t('Open this market\'s website in new tab') . '</a>]';
          $resourcesChooserDefault[$currentMarketId] = 0; //We just want all checkboxes to be unchecked by default.
        }
        unset($marketRecord, $currentMarketId, $currentMarketName, $currentMarketUrl, $fleamarketsProperties);

        $form['resources_chooser'] = [
          '#type' => 'checkboxes',
          '#options' => $formattedList,
          '#title' => $this->t('Choose flea-markets websites to search on'),
          '#default_value' => $resourcesChooserDefault,
        ];

        $form['query_constructor_block'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => ['query-constructor-block'],
          ],
        ];

        //Check from where get form structure:
        //  1. If !empty($requestedFormContent) -> this means that user wants to load previously saved form from DB, so let's get it;
        //  2. Else -> get 'user_added' from form_state->storage, maybe this variable is not empty, and has something:
        if (!empty($requestedFormContent)) {
          $userAdded = $requestedFormContent['form_structure'];
          $form_state->set('user_added', $userAdded);
        } else {
          $userAdded = $form_state->get('user_added');
        }

        //If $userAdded IS STILL EMPTY -> this means that user requests form for the very first time (just started to work with form),
        //so we generate default start set: a new block (container with one empty text field) and store it in $form_state['user_added']:
        if (empty($userAdded)) {
          $this->newBlock($form_state);

          //In case $form_state['user_added'] was just updated by newBlock(), get it again:
          $userAdded = $form_state->get('user_added');
        }

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
          '#title' => $this->t('Additional search parameters'),
        ];

        //By default, search performs only in headers, but some resources (OLX and Skylots, but not Besplatka) also supports
        //search in body. Technically, it adds specific suffix to URL.
        $form['additional_params']['dscr_chk'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Search in descriptions too (if resource supports).'),
          '#default_value' => 0,
        ];

        $form['additional_params']['filter_by_price'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Filter items by price:'),
          '#default_value' => 0,
        ];

        $form['additional_params']['price_from'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Price from'),
          '#default_value' => '',
          '#size' => 10,
          '#maxlength' => 10,
          '#description' => 'UAH', //'Enter the minimal price, at which (or higher) item will be selected.',
          '#prefix' => '<div class="container-inline">',
          '#suffix' => '</div>',
        ];

        $form['additional_params']['price_to'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Price to'),
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
          '#value' => $this->t('Add Keyword'),
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
          '#value' => $this->t('Start Search'),
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
          '#value' => $this->t('Reset All'),
          '#name' => 'btn_reset',
          '#limit_validation_errors' => [], //We don't want validate any errors for this submit button, because this is RESET button!
          '#submit' => [
            '::resetAllHandler',
          ],
        ];

        $dataToInject = [];

        if (isset($requestedFormContent['form_values'])) {
          //The case, when user requested to load already existing search from DB:
          $dataToInject = $requestedFormContent['form_values'];
        } else {
          //Other cases:
          //  1. When user switch from second to first step of the form ->
          //      we need to fill form elements with values from form_state->storage['form_state_values_snapshot'];
          //  2. When form loading first time, and we don't have any data in form_state->storage['form_state_values_snapshot'],
          //      but anyway we still need at least empty array to pass to injectFormValues();
          $dataToInject = $form_state->get('form_state_values_snapshot');
          $dataToInject = empty($dataToInject) ? [] : $dataToInject;
        }

        $this->injectFormValues($form, $dataToInject, $recordID);

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

        $fleamarketsList = FleaMarket::getSupportedMarketsList(TRUE);

        //This is the full path to animated GIF (sand clock like in windows-98), showing to user, while php script is busy in parsing fleamarkets:
        $schemeAndHttpHost = $this->getRequest()->getSchemeAndHttpHost();
        $baseUrl = $this->getRequest()->getBaseUrl();
        $modulePath = $this->moduleHandler->getModule('sherlock_d8')->getPath();
        $animationPath = $schemeAndHttpHost . $baseUrl . '/' . $modulePath . '/templates/img/sand-clock.gif';

        $outputContainers = [];
        foreach ($form_state->getValue('resources_chooser') as $marketId) { //'olx', 'bsp', 'skl', or 0 (zero).
          //Let's create div-container for every checked resource, because we need place where to output parse result
          if ($marketId === 0) {continue;}
          $outputContainers[$marketId]['market_id'] = $marketId;
          $outputContainers[$marketId]['container_title'] = $fleamarketsList[$marketId]['marketName'];
          $outputContainers[$marketId]['container_id'] = $marketId.'-output-block';
          $outputContainers[$marketId]['loading_animation_path'] = $animationPath;
        }
        unset ($marketId);

        //Prepare associative array with constructed search queries to show to user. Keys of array are normal (not short!) flea-market names.
        $constructedUrlsCollection = [];
        foreach ($form_state->get(['sherlock_tmp_storage', 'constructed_urls_collection']) as $key => $value) {
          $userFriendlyKey = $fleamarketsList[$key]['marketName'];
          $constructedUrlsCollection[$userFriendlyKey] = $value;
        }
        unset($key, $value);

        //Load saved search record from DB, to pre-populate it's name, checkbox state and expiring time label:
        /**
         * @var $savedSearchObject iSherlockSearchEntity
         */
        $savedSearchObject = SherlockEntity::getInstance('SEARCH', $userID, $this->dbConnection)->load($recordID);
        $savedSearchName = '';
        $linkedTaskID = 0;
        $subscribeForUpdatesCheckbox = FALSE;

        if ($savedSearchObject) {
          $savedSearchName = $savedSearchObject->getName();
          $linkedTaskID = $savedSearchObject->getTaskId();
          $subscribeForUpdatesCheckbox = boolval($linkedTaskID);
        }

        //Load task entity record:
        /**
         * @var $savedTaskObject iSherlockTaskEntity
         */
        $savedTaskObject = SherlockEntity::getInstance('TASK', $userID, $this->dbConnection)->load($linkedTaskID);
        $activeTo = $this->t('End time not set');

        if ($savedTaskObject) {
          $activeTo = date('F j, Y', $savedTaskObject->getActiveTo());
        }

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
        //---------------- Save search for future reuse block ----------------------------------------------------------
        $form['save_search_block'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $this->t('Save search'),
        ];

        if($userAuthenticated) {
          $form['save_search_block']['search_name_textfield'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Enter name for your search'),
            '#required' => TRUE,
            '#maxlength' => 255, //This field defined as VARCHAR(255) in DB.
            '#default_value' => $savedSearchName,
          ];

          $form['save_search_block']['overwrite_existing_search'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Overwrite if exists'),
            '#default_value' => 0,
          ];

          $form['save_search_block']['subscribe_to_updates'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Check for updates daily and send a report on new offers.'),
            '#default_value' => $subscribeForUpdatesCheckbox,
          ];

          $form['save_search_block']['now_or_tomorrow_radiobutton'] = [
            '#type' => 'radios',
            '#title' => $this->t('Would you like to get all current results now, or just updates starting from tomorrow?'),
            '#default_value' => 'from_now',
            '#options' => [
              'from_now' => $this->t('Mail me ALL current results NOW, and new results starting from tomorrow'),
              'from_tomorrow' => $this->t('Mail me new results starting from tomorrow'),
            ],
          ];

          $form['save_search_block']['serving_period_selector'] = [
            '#type' => 'select',
            '#options' => [3 => '3 days', 7 => '7 days', 30 => '30 days', 90 => '90 days',],
            '#title' => $this->t('How long to serve your task?'),
            '#default_value' => 7,
            '#prefix' => '<div class="container-inline">',
            '#suffix' => '</div>',
          ];

          $form['save_search_block']['active_to_remark'] = [
            '#type' => 'item',
            '#title' => $this->t('This task is active until'),
            '#description' => $activeTo,
            '#prefix' => '<div class="container-inline">',
            '#suffix' => '</div>',
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
              '::saveSubmitHandler',
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

  /**
   * This method returns saved form structure and form values from DB (from "SHERLOCK_MAIN_TABLE" table).
   * As data stores in DB in serialized form, this method also unserialize them and returns as array.
   *
   * @param int $recordID
   * @return array
   */
  protected function loadSavedForm(int $recordID) {
    $userID = $this->currentUser()->id();
    if ($recordID <= 0 || $userID <= 0) {return [];}

    /**
     * @var $savedSearchObject iSherlockSearchEntity
     */
    $savedSearchObject = SherlockEntity::getInstance('SEARCH', $userID, $this->dbConnection)->load($recordID);

    $savedForm = [];

    if ($savedSearchObject) {
      $savedForm['form_structure'] = $savedSearchObject->getFormStructure();
      $savedForm['form_values'] = $savedSearchObject->getFormValues();
    }

    if (empty($savedForm['form_structure']) || empty($savedForm['form_values'])) {
      return [];
    }

    return $savedForm;
  }

  protected function injectFormValues(array &$form, array $formStateValuesSnapshot, int $recordIdToLoad = 0) {
    foreach ($formStateValuesSnapshot as $logicBlockKey => $logicBlockValue) {
      //Check if $logicBlockValue contains something - we just want to skip a buttons or empty wrappers:
      if (empty($logicBlockValue) || !is_array($logicBlockValue)) {
        continue;
      }

      //Check if current element is saved search selector dropdown menu
      if ($logicBlockKey === 'saved_search_block') {
        //If recordIdToLoad == 0 (not set), this property will be == FALSE (by default), so block will be rendered as closed,
        //if recordIdToLoad == some integer value -> block will be rendered as open, so user will easily see what record is loaded:
        $form[$logicBlockKey]['#open'] = (bool) $recordIdToLoad;

        //Dropdown menu will highlight active (loaded) record, if any:
        $form['saved_search_block']['saved_search_id']['#default_value'] = $recordIdToLoad;
        continue;
      }

      if ($logicBlockKey === 'resources_chooser') {
        $form[$logicBlockKey]['#default_value'] = $logicBlockValue;
        continue;
      }

      if ($logicBlockKey === 'additional_params') {
        $form[$logicBlockKey]['dscr_chk']['#default_value'] = $logicBlockValue['dscr_chk'];
        $form[$logicBlockKey]['filter_by_price']['#default_value'] = $logicBlockValue['filter_by_price'];
        $form[$logicBlockKey]['price_from']['#default_value'] = $logicBlockValue['price_from'];
        $form[$logicBlockKey]['price_to']['#default_value'] = $logicBlockValue['price_to'];
        continue;
      }

      if ($logicBlockKey === 'query_constructor_block') {
        foreach ($logicBlockValue as $keywordIndex => $keywordValue) { //keywordIndex is like KEYWORD-0, KEYWORD-1...
          foreach ($keywordValue as $key => $value) {
            if ($key !== 'VALUES') {
              continue;
            }
            foreach ($value as $digitIndex => $textField) {
              $form[$logicBlockKey][$keywordIndex]['VALUES'][$digitIndex]['textfield']['#default_value'] = $textField['textfield'];
            }
            unset($digitIndex, $textField);
          }
          unset($key, $value);
        }
        unset($keywordIndex, $keywordValue);
      }
    }
  }

  protected function newBlock(FormStateInterface $form_state): void {
    $userAdded = $form_state->get('user_added');

    $findGreatestKeywordBlockIndex = function() use ($userAdded) :int {
      $keys = [];

      foreach ($userAdded as $key => $value) {
        $keys[] = intval(explode('-', $key)[1]);
      }

      return empty($keys) ? -1 : max($keys);
    };

    $newBlockNo = (is_array($userAdded)) ? ($findGreatestKeywordBlockIndex() + 1) : 0;

    //Create a new empty container just with title:
    $newBlock = [
      '#type' => 'fieldset',
      '#title' => $this->t('KEYWORD-').$newBlockNo,
      '#attributes' => ['id' => 'KEYWORD-BLOCK-'.$newBlockNo],
    ];

    //Place a "Add Variation" button to this container:
    $newBlock['add_variation_button_wrapper'] = [ //Create new DIV wrapper for Add Variation button (according to Drupal 7 best practices recommendations).
      '#type' => 'actions',
      'btn_addvariation-'.$newBlockNo => [ //...and new button inside this wrapper
        '#type' => 'submit',
        '#value' => $this->t('Add Word Spelling Variant'),
        '#name' => 'btn_addvariation-'.$newBlockNo,
        '#submit' => ['::btnAddvariationHandler',],
        '#ajax' => ['callback' => '::constructorBlockAjaxReturn',],
      ],
    ];

    //Place a very first empty textfield to this new block. Other textfields will be added by user by pressing "Add Variation" button:
    $newBlock['VALUES'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Keyword Variations'),
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
        '#title' => $this->t('Variation').' '.$blockNumber.'/'.$variationNumber,
        '#default_value' => '',
      ],
      //Button for remove this textfield:
      'rm_this_variation_btn' => [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
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
   * A handler function for Load button.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function loadSearchHandler(array &$form, FormStateInterface $form_state) {
    //Get id of selected search\record:
    $recordIdToLoad = intval($form_state->getValue(['saved_search_block', 'saved_search_id']));
    $form_state->setRedirect('sherlock_d8.mainform', ['recordID' => $recordIdToLoad]);
    $form_state->setRebuild(FALSE);
  }

  public function deleteSearchHandler(array &$form, FormStateInterface $form_state) {
    //Get id of selected search\record:
    $recordIdToDelete = intval($form_state->getValue(['saved_search_block', 'saved_search_id']));

    //Get current user ID (for security reasons - we will check DB for record with specified recordId AND userId,
    //because recordId user can submit by any POST application and such way get access to records owned by other users.
    //Checking for recordId AND userId is more secure, because user can't submit another's userId):
    $currentUserId = $this->currentUser()->id();

    if ($recordIdToDelete > 0 && $currentUserId > 0) {
      $searchObject = SherlockEntity::getInstance('SEARCH', $currentUserId, $this->dbConnection);
      $searchObject->delete($recordIdToDelete);

      $this->addMessagesToCollector();

      $this->messageCollector->msgCollectorObject()->displayAllMessages();

      $form_state->setRebuild(FALSE);

    } else {

      //If user selected default option from dropdown (or, in other words, does not selected any saved search, and $recordIdToLoad === 0) -> just reset form to default\empty:
      $form_state->setRebuild(FALSE);
    }
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
    $form_state->set('does_user_altering_form', TRUE);

    $form_state->setRebuild();
  }

  public function addTermHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_addterm') {return;}

    $this->newBlock($form_state);
    $form_state->set('does_user_altering_form', TRUE);

    $form_state->setRebuild();
  }

  public function resetAllHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_reset') {return;}

    $form_state->set('user_added', null); //This option is not absolutely necessary, bet let it be here for reliability.

    //Redirect to form with default recordID parameter == 0, in other words - just load form as on first visit (effectively - reset all changes).
    $form_state->setRedirect('sherlock_d8.mainform');
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

    //Now we should recheck $user_added['KEYWORD-'.$buttonBlockNo]['VALUES'] array, and found if any text inputs are left in it.
    //Text inputs are array elements indexed by numeric indexes, like 0, 1, 2, 3
    //If no text inputs left - delete block completely:
    $numericIndexDetected = function($arrayToTest) {
      foreach ($arrayToTest as $key => $value) {
        if (is_numeric($key)) {
          return TRUE;
        }
      }

      return FALSE;
    };

    //No numeric indexes - block is empty - delete it completely:
    if ($numericIndexDetected($user_added['KEYWORD-'.$buttonBlockNo]['VALUES']) === FALSE) {
      unset($user_added['KEYWORD-'.$buttonBlockNo]);
    }

    $form_state->set('user_added', $user_added);
    $form_state->set('does_user_altering_form', TRUE);

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
    $resourcesList = FleaMarket::getSupportedMarketsList(TRUE);

    //Array with all search URLs for all user-specified resources. This collection of URL we will use to cURL each of them at the next step.
    //All values are splitted by nested arrays. Keys to nested arrays are names of the resources.
    $constructedUrlsCollection = [];

    //Temporary storage for selected markets IDs.
    $sherlockTempStorage['selected_markets'] = [];

    $keywordsCombinations = BlackMagic::generateAllPossibleCombinations($blockValues);
    $keywordsCombinationsCount = count($keywordsCombinations);
    foreach ($form_state->getValue('resources_chooser') as $key => $value) { //$key here is flea market ID, like olx, bsp, skl
      if ($value === 0) { //we take into consideration only checked resources, if checkbox unchecked its value == 0 and we skip it.
        continue;
      }

      for ($i = 0; $i < $keywordsCombinationsCount; $i++) {
        $className = $resourcesList[$key]['marketClassName'];
        $fleaMarketObjectReflection = new \ReflectionClass('\Drupal\sherlock_d8\CoreClasses\FleaMarket\\' . $className);
        $requestConstructor = $fleaMarketObjectReflection->getMethod('makeRequestURL');
        $constructedUrlsCollection[$key][] = $requestConstructor->invokeArgs(null, [$keywordsCombinations[$i], $priceFrom, $priceTo, $checkDescriptionToo]);
      }

      //Check constructed URLs, and left only unique of them:
      $constructedUrlsCollection[$key] = array_unique($constructedUrlsCollection[$key]);

      //Save selected (ONLY SELECTED!) fleamarkets to $form_state['sherlock_tmp_storage']['selected_markets'],
      //we'll pass them to frontend by attaching to drupalSettings object at step 2 of the main form:
      $sherlockTempStorage['selected_markets'][] = $key;
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
      //Redirect to form with default recordID parameter == 0, in other words - just load form as on first visit (effectively - reset all changes).
      $form_state->setRedirect('sherlock_d8.mainform');
    }
  }

  public function saveUpdateValidate(array &$form, FormStateInterface $form_state) {
    $overwriteExisting = intval($form_state->getValue(['save_search_block', 'overwrite_existing_search']));

    $searchName = $form_state->getValue(['save_search_block', 'search_name_textfield']);
    $searchNameSanitized = filter_var($searchName, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);

    $storingPeriod = $form_state->getValue(['save_search_block', 'serving_period_selector']);
    $storingPeriodSanitized = intval($storingPeriod);

    //Calculate md5 hash of name of the search:
    $searchNameMD5Hash = hash(SHERLOCK_SEARCHNAME_HASH_ALGO, $searchName);

    $dataToCheck = [
      'uid' => $this->currentUser()->id(),
      'name_hash' => $searchNameMD5Hash,
    ];

    if ($searchName !== $searchNameSanitized) {
      $form_state->setErrorByName('save_search_block][search_name_textfield', $this->t('Please, provide correct name for your search.'));
    }

    if ($storingPeriodSanitized > 90) {
      $form_state->setErrorByName('save_search_block][serving_period_selector', $this->t('Select how many days your search will be served.'));
    }

    //If checkbox "Overwrite existing search" is UNCHECKED && If data with given user ID and name already exists:
    if (!$overwriteExisting && $this->dbConnection->selectTable(SHERLOCK_MAIN_TABLE)->checkIfRecordExists($dataToCheck)) {
      $form_state->setErrorByName('save_search_block][search_name_textfield', $this->t('This name is already taken, we can\'t save new search with the same name. But you can overwrite existing search with new data.'));
    }
  }

  public function saveSubmitHandler(array &$form, FormStateInterface $form_state) {
    $triggeringElement = $form_state->getTriggeringElement();
    $pressedBtnName = $triggeringElement['#name'] ?? '';
    if ($pressedBtnName != 'btn_savesearch') {return;}

    $searchEntity = SherlockEntity::getInstance('SEARCH', $this->currentUser()->id(), $this->dbConnection);
    $searchEntity->fillObjectWithFormData($form_state);
    $searchEntity->save();

    $this->addMessagesToCollector();

    // Check fleamarkets again (actually, load results from cache), and, optionally, send first email notification:

    $isSubscribeForUpdatesChecked = intval($form_state->getValue(['save_search_block', 'subscribe_to_updates']));

    if ($isSubscribeForUpdatesChecked) {
      /**
       * @var iSherlockSearchEntity $searchEntity
       */
      $taskID = $searchEntity->getTaskId();

      $nowOrTomorrowRadio = $form_state->getValue(['save_search_block', 'now_or_tomorrow_radiobutton']);

      $sendMail = ($nowOrTomorrowRadio === 'from_now');
      $rowsInsertedNum = -1;

      try {

        $rowsInsertedNum = $this->taskLauncher->runTask($this->currentUser()->id(), $taskID, $sendMail);

      } catch (InvalidInputData $invalidInputData) {

        $this->messageCollector->msgCollectorObject()
          ->addMessage($this->t('Cannot proceed because of incompatible data format. Please, contact support for this incident.'), 'error', 'H');

        $this->logThisIncident($invalidInputData);
      }

      if ($rowsInsertedNum >= 0) {
        $this->messageCollector->msgCollectorObject()
          ->addMessage(t('Current search results successfully saved/synced to database.'), 'status', 'L');
      }

      if ($this->taskLauncher->getMailNotificationStatus()) {
        $this->messageCollector->msgCollectorObject()
          ->addMessage(t('Search results just have been sent to your email (@email). If you don\'t see the letter in the inbox, please, check spam folder.',
            ['@email' => $this->currentUser()->getEmail(),]), 'status', 'H'
        );
      }
    }

    $this->messageCollector->msgCollectorObject()->displayAllMessages();
  }

  protected function addMessagesToCollector() {
    if (SherlockEntity::isSearchCreated()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_SEARCH_SAVED_NOTIFICATION), 'status', 'H');
    }

    if (SherlockEntity::isSearchUpdated()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_SEARCH_UPDATED_NOTIFICATION), 'status');
    }

    if (SherlockEntity::isSearchDeleted()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_SEARCH_DELETED_NOTIFICATION), 'status');
    }

    if (SherlockEntity::isTaskCreated()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_TASK_SAVED_NOTIFICATION), 'status');
    }

    if (SherlockEntity::isTaskUpdated()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_TASK_UPDATED_NOTIFICATION), 'status');
    }

    if (SherlockEntity::isTaskDeleted()) {
      $this->messageCollector->msgCollectorObject()
        ->addMessage(t(SherlockEntity::SHERLOCK_TASK_DELETED_NOTIFICATION), 'status');
    }

    SherlockEntity::resetFlags();
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

  protected function logThisIncident(\Exception $exceptionObject) {
    //How to use logger: https://api.drupal.org/api/drupal/core!lib!Drupal.php/function/Drupal%3A%3Alogger/8.2.x

    $problemFilePath = $exceptionObject->getFile();
    $lineNumber = $exceptionObject->getLine();
    $description = $exceptionObject->getMessage();

    $this->logger('sherlock_d8')->error('Problem file: ' . $problemFilePath . '<br>' . 'Line number: ' . $lineNumber . '<br>' . 'Error description: ' . $description);

    unset($problemFilePath, $lineNumber, $description);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }
}
