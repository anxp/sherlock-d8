<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-02-17
 * Time: 13:41
 */

namespace Drupal\sherlock_d8\CoreClasses\ItemSniper;

use phpquery\PhpQuery as phpQuery;
use phpquery\phpQueryObject as phpQueryObject;

/*
 * Naming convention for child classes:
 * They must have prefix with marketId and underscore symbol, like: olx_, bsp_, skl_, with word 'ItemSniper' after prefix: ex.: olx_ItemSniper.
 * This makes possible to create new instances automatically, knowing only marketId.
 * */
class olx_ItemSniper extends ItemSniper {
  public function __construct($URL, $pageLimit, $advertBlockSP = 'div.offer-wrapper', $titleSP = 'h3', $titleLinkSP = 'h3 > a', $priceSP = 'p.price', $imageAddressSP = 'img.fleft', $nextPageLinkSP = 'div.pager span.next a') {
    parent::__construct($URL, $pageLimit, $advertBlockSP, $titleSP, $titleLinkSP, $priceSP, $imageAddressSP, $nextPageLinkSP);
  }

  protected function getItemTitle(phpQueryObject $phpQueryNode): string {
    return (trim($phpQueryNode->find($this->titleSP)->text()));
  }

  protected function getThumbnailAddress(phpQueryObject $phpQueryNode): string {
    $thumbAddress = $phpQueryNode->find($this->imageAddressSP)->attr('src');
    return (empty($thumbAddress) ? '' : $thumbAddress);
  }

  protected function getItemLink(phpQueryObject $phpQueryNode): string {
    //We parse link to item and then gather only required parts.
    //For example, usually links from OLX ends with smth like "#1b78919f15;promoted" and we don't need such trash.
    $itemLink = $phpQueryNode->find($this->titleLinkSP)->attr('href');
    $linkParts = parse_url($itemLink);
    $itemLinkClean = $linkParts['scheme'].'://'.$linkParts['host'].$linkParts['path']; //Link now clean.
    return $itemLinkClean;
  }

  protected function getItemPrice(phpQueryObject $phpQueryNode): array {
    //Get price. At the moment this is 'raw' price, with number and currency ID, like 5 000 грн. We'll parse it later:
    $itemPriceRaw = trim($phpQueryNode->find($this->priceSP)->text());
    $itemPrice = [];
    $itemPrice['price_value'] = (string) preg_replace('/\D/', '', $itemPriceRaw); //Filter out ANYTHING THAT NOT a digit.
    $itemPriceRawNoSpaces = (string) preg_replace('/\s/', '', $itemPriceRaw);
    $itemPrice['price_currency'] = (string) preg_replace('/(\d+)([^0-9]+)(\.)?$/', '$2', $itemPriceRawNoSpaces); //Filter out digits, leave only currency ID.
    return $itemPrice;
  }

  protected function getNextPageLink(phpQueryObject $phpQueryNode): string {
    return ($phpQueryNode->find($this->nextPageLinkSP)->attr('href'));
  }

  private function isAdsRelevant(phpQueryObject $phpQueryNode): bool {
    //Check if 'no items found' message present. If yes - IGNORE ALL return from OLX because it is NOT relevant.
    $noAdsFoundMessage = $phpQueryNode->find('div.emptynew > p')->text(); //Empty string OR 'Не найдено ни одного объявления, соответствующего параметрам поиска. Проверьте правильность написания или введите другие параметры поиска'
    $noAdsFoundMessage = trim($noAdsFoundMessage);
    return empty($noAdsFoundMessage) ? TRUE : FALSE;
  }

  public function grabItems(): array {
    $fullHTML = $this->getWebpageHtml($this->URL);
    $doc = phpQuery::newDocument($fullHTML);
    $isAdsRelevant = $this->isAdsRelevant($doc);
    phpQuery::unloadDocuments($doc);
    return $isAdsRelevant ? parent::grabItems() : [];
  }
}
