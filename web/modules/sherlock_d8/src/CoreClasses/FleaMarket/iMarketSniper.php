<?php

namespace Drupal\sherlock_d8\CoreClasses\FleaMarket;

interface iMarketSniper {
  public function setUserAgent(string $userAgent) :void;
  public function getUserAgent() :string;
  public function grabItems() :array;
}
