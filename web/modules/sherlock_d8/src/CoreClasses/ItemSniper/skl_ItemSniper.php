<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-02-17
 * Time: 13:42
 */

namespace Drupal\sherlock_d8\CoreClasses\ItemSniper;

use phpquery\phpQueryObject as phpQueryObject;

/*
 * Naming convention for child classes:
 * They must have prefix with marketId and underscore symbol, like: olx_, bsp_, skl_, with word 'ItemSniper' after prefix: ex.: olx_ItemSniper.
 * This makes possible to create new instances automatically, knowing only marketId.
 * */
class skl_ItemSniper extends ItemSniper {
  public function __construct($URL, $pageLimit, string $advertBlockSP = 'div.search_lot', string $titleSP = 'div.search_lot_title > a', string $titleLinkSP = 'div.search_lot_title > a', string $priceSP = 'div.search_lot_price', string $imageAddressSP = 'div.searchimg', string $nextPageLinkSP = 'div.rpagination a:last') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode): string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode): string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('data-src');
    if (empty($thumbAddress)) {return '';}

    $thumbAddress = explode('?', $thumbAddress)[0];

    return $thumbAddress;
  }

  protected function getItemLink(phpQueryObject $phpQueryNode): string {
    return ('https://skylots.org'.$phpQueryNode->find($this->titleLinkSP)->attr('href'));
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode): array {
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());
    $itemPriceWithoutCommaAndSpace = (string) preg_replace('/\s|\,/', '', $itemPriceRaw);
    $priceComponents = explode('.', $itemPriceWithoutCommaAndSpace);
    $itemPrice['price_value'] = (string) $priceComponents[0];
    $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9]+)$/', '$2', $priceComponents[1]); //Filter out digits, leave only currency ID.
    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode): string {
    $internalURL = $phpQueryNode->find($this->nextPageLinkSP)->attr('href');
    //Construct real full path only if found "next page" link is NOT empty, else -> just return '' (empty string):
    $path = ($internalURL === '') ? '' : ('https://skylots.org/search.php'.$internalURL);
    return ($path);
  }
}
