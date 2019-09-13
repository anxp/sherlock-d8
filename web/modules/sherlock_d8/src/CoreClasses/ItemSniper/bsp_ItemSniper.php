<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-02-17
 * Time: 13:42
 */

namespace Drupal\sherlock_d8\CoreClasses\ItemSniper;

use PhpQuery\PhpQueryObject as phpQueryObject;
use function PhpQuery\pq;

/*
 * Naming convention for child classes:
 * They must have prefix with marketId and underscore symbol, like: olx_, bsp_, skl_, with word 'ItemSniper' after prefix: ex.: olx_ItemSniper.
 * This makes possible to create new instances automatically, knowing only marketId.
 * */
class bsp_ItemSniper extends ItemSniper {
  public function __construct($URL, $pageLimit, string $advertBlockSP = 'div.messages-list > div.msg-one > div.msg-inner', string $titleSP = 'div.w-body a.m-title', string $titleLinkSP = 'div.w-body a.m-title', string $priceSP = 'div.w-body > p.m-price > span', string $imageAddressSP = 'a.w-image > img.img-responsive', string $nextPageLinkSP = 'head > link.pag_params') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode): string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode): string {
    //Get address of attached thumbnail. We use value from attribute 'data-src', because 'src' attribute does not exists when raw html received!
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('data-src');
    return (empty($thumbAddress) ? '' : $thumbAddress);
  }

  protected function getItemLink(phpQueryObject $phpQueryNode): string {
    //All links at Besplatka are 'internal', so we need add 'https://besplatka.ua' at the begining of them:
    return ('https://besplatka.ua'.$phpQueryNode->find($this->titleLinkSP)->attr('href'));
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode): array {
    //Usually, we have only 1 or, more rarely, 2 nested (inside p.m-price) span tags. First contains price and currency, and second - "Buy now" text, if this option available.
    //So, we normally want only first span tag. Unfortunately, phpQuery does not support CSS selectors like span:first-child, so we need select them all, iterate, and get only first:
    $allNestedSpans = $phpQueryNode->find($this->priceSP);

    $spanNode = null;

    foreach ($allNestedSpans as $justFirstSpan) {
      $spanNode = pq($justFirstSpan); //Converted span to phpQueryObject type.
      break;
    }

    //If price not found -> don't even try to parse anything:
    if ($spanNode === null) {
      return [];
    }

    //Get price. At the moment this is 'raw' price, with number and currency ID, like 5 000,50 грн. We'll parse it later:
    $itemPriceRaw = trim($spanNode->text()); //price now is like 2 286,5 грн.
    $itemPriceRawNoSpaces = (string) preg_replace('/\s/', '', $itemPriceRaw); //price now is like 2286,5грн. or more common: 2189грн.

    $itemPrice = []; //Here we will store price - price_value and price_currency.

    //Check for ',':
    $commaPosition = strpos($itemPriceRawNoSpaces, ',');

    if ($commaPosition === FALSE) {
      //if comma was not found in string:
      $itemPrice['price_value'] = (string) preg_replace('/\D/', '', $itemPriceRaw); //Filter out ANYTHING THAT NOT a digit.
      $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9]+)(\.)$/', '$2', $itemPriceRawNoSpaces); //Filter out digits, leave only currency ID.
    } else {
      //if comma WAS FOUND in string:
      $exploded = explode(',', $itemPriceRawNoSpaces);
      $itemPrice['price_value'] = (string) $exploded[0];
      $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9]+)(\.)$/', '$2', $exploded[1]); //Filter out digits, leave only currency ID.
    }

    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode): string {
    $internalURL = $phpQueryNode->find($this->nextPageLinkSP)->attr('href');
    //Construct real full path only if found "next page" link is NOT empty, else -> just return '' (empty string):
    $path = ($internalURL === '') ? '' : ('https://besplatka.ua'.$internalURL);
    return ($path);
  }
}
