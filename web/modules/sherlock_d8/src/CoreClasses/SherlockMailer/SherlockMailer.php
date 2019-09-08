<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-09-03
 * Time: 22:42
 */
namespace Drupal\sherlock_d8\CoreClasses\SherlockMailer;

use Drupal\sherlock_d8\CoreClasses\Exceptions\InvalidInputData;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Render\Markup;

class SherlockMailer {
  /**
   * @var RendererInterface $rendererService
   */
  protected $rendererService = null;

  /**
   * @var MailManager $mailManager
   */
  protected $mailManager = null;

  protected $moduleName = '';
  protected $key = '';
  protected $params = [];
  protected $recipientEmail = '';
  protected $langcode = '';

  public function __construct(MailManager $mailManager, RendererInterface $rendererService) {
    $this->mailManager = $mailManager;
    $this->rendererService = $rendererService;
  }

  /**
   * This method intended for composing email message. Depending on passed parameters and variables it can compose simple text or html letter.
   * Also it automatically chooses preferred language for letter from user account preferences.
   * At the end of this method we'll have $params array with next elements, which can later be used in hook_mail():
   *
   * $params['message'] - the body of the message. All placeholders are already substituted to their values, so no need to do this in hook_mail().
   * Should be added to $message['body'][] in hook_mail()
   *
   * $params['subject'] - the subject of the mail. All placeholders are already substituted to their values, so no need to do this in hook_mail().
   * Should be assigned to $message['subject'] in hook_mail()
   *
   * $params['headers-content-type'] - string with content type and encoding. Should be assigned to $message['headers']['Content-Type'] in hook_mail().
   *
   * @param string $moduleName - the module, whose hook_mail() will be invoked
   * @param string $key - the key of the message (or message type identifier)
   * @param int $userID - ID of the user who will got the mail message. We get user email and preferred language from given userID
   * @param string $subject - the subject of mail message. Can contain placeholders starting with '@'
   * @param array $substitutionVariablesForSubject - array for substitution placeholders in subject string to real values
   * @param string $plainTextBody - if you compose primitive simple text mail, you can put text body into this variable. As a subject, can contain placeholders
   * @param array $substitutionVariablesForBody - array for substitution placeholders in body to real values
   * @param array $renderableSet - if you compose HTML email, fill this array with '#theme' => 'theme_hook_name' (the only required element) and all variables which will be put in template.
   * Variables should be declared as '#variable_1' => 'value_1', '#variable_2' => 'value_2' ...
   * @param string $mimeType - text/plain or text/html
   * @throws InvalidInputData
   */
  public function composeMail(string $moduleName, string $key, int $userID, string $subject, array $substitutionVariablesForSubject = [], string $plainTextBody = '', array $substitutionVariablesForBody = [], array $renderableSet = [], string $mimeType = 'text/plain') {
    if (!in_array($mimeType, ['text/plain', 'text/html'])) {
      throw new InvalidInputData('Invalid MIME Type. $mimeType should be only \'text/plain\' or \'text/html\'');
    }

    if ($mimeType === 'text/html' && (empty($renderableSet['#theme']) || !is_string($renderableSet['#theme']))) {
      //Theme hook not set
      throw new InvalidInputData('Theme hook not set (check #theme element of $renderableSet array). Theme hook is the only required element IF you compose HTML letter.');
    }

    $userAccount = \Drupal\user\Entity\User::load($userID);
    $this->recipientEmail = $userAccount->getEmail();
    $this->langcode = $userAccount->getPreferredLangcode();

    $options = [
      'langcode' => $this->langcode,
    ];

    if (isset($renderableSet['#theme']) && is_string($renderableSet['#theme'])) {
      //If user specified #theme for rendering HTML OR NO-HTML content, we go this way (IGNORING value of $plainTextBody):
      /**
       * @var \Drupal\Component\Render\MarkupInterface $body
       */
      $body = $this->rendererService->render($renderableSet);
    } else {
      //If user didn't specified #theme we just make a letter from $plainTextBody string passed:
      /**
       * @var string | \Drupal\Component\Render\MarkupInterface $body
       */
      $body = Markup::create(t($plainTextBody, $substitutionVariablesForBody, $options));
    }

    if ($mimeType === 'text/html') {
      $headersContentType = 'text/html; charset=UTF-8; format=flowed; delsp=yes';
    } else {
      $headersContentType = 'text/plain; charset=UTF-8; format=flowed; delsp=yes';
    }

    $params = [];
    $params['message'] = $body;
    $params['subject'] = t($subject, $substitutionVariablesForSubject, $options);
    $params['headers-content-type'] = $headersContentType;

    $this->params = $params;
    $this->moduleName = $moduleName;
    $this->key = $key;
  }

  public function sendMail($replyTo = NULL, $send = TRUE) {
    $attemptResult = $this->mailManager->mail($this->moduleName, $this->key, $this->recipientEmail, $this->langcode, $this->params, $replyTo, $send);

    return $attemptResult['result'];
  }
}
