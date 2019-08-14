<?php

class Coinmoney_Block_Form extends Mage_Checkout_Block_Onepage_Payment
{
  protected function _construct()
  {
    parent::_construct();
    $this->setTemplate('coinmoney/form.phtml');
  }
}