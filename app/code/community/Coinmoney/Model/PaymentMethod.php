<?php
 
/**
* Our test CC module adapter
*/
class Coinmoney_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
	/**
	* unique internal payment method identifier
	*
	* @var string [a-z0-9_]
	*/
	protected $_code = 'Coinmoney';
 
	/**
	 * Here are examples of flags that will determine functionality availability
	 * of this module to be used by frontend and backend.
	 *
	 * @see all flags and their defaults in Mage_Payment_Model_Method_Abstract
	 *
	 * It is possible to have a custom dynamic logic by overloading
	 * public function can* for each flag respectively
	 */
	 
	/**
	 * Is this payment method a gateway (online auth/charge) ?
	 */
	protected $_isGateway               = true;
 
	/**
	 * Can authorize online?
	 */
	protected $_canAuthorize            = true;
 
	/**
	 * Can capture funds online?
	 */
	protected $_canCapture              = false;
 
	/**
	 * Can capture partial amounts online?
	 */
	protected $_canCapturePartial       = false;
 
	/**
	 * Can refund online?
	 */
	protected $_canRefund               = false;
 
	/**
	 * Can void transactions online?
	 */
	protected $_canVoid                 = false;
 
	/**
	 * Can use this payment method in administration panel?
	 */
	protected $_canUseInternal          = false;
 
	/**
	 * Can show this payment method as an option on checkout payment page?
	 */
	protected $_canUseCheckout          = true;
 
	/**
	 * Is this payment method suitable for multi-shipping checkout?
	 */
	protected $_canUseForMultishipping  = true;
 
	/**
	 * Can save credit card information for future processing?
	 */
	protected $_canSaveCc = false;

	protected $_formBlockType = 'coinmoney/form';

	
	function canUseForCurrency($currencyCode)
	{		
		$currencies = Mage::getStoreConfig('payment/Coinmoney/currencies');
		$currencies = array_map('trim', explode(',', $currencies));
		return array_search($currencyCode, $currencies) !== false;
	}
 	
	public function canUseCheckout()
    {
		
		$secret = Mage::getStoreConfig('payment/Coinmoney/api_key');
		if (!$secret or !strlen($secret))
		{
			Mage::log('Coinmoney: API key not entered', null, 'coinmoney.log');
			return false;
		}
		
		$speed = Mage::getStoreConfig('payment/Coinmoney/speed');
		if (!$speed or !strlen($speed))
		{
			Mage::log('Coinmoney: Transaction Speed invalid', null, 'coinmoney.log');
			return false;
		}
		
		return $this->_canUseCheckout;
    }

	public function authorize(Varien_Object $payment, $amount) 
	{
		if (!Mage::getStoreConfig('payment/Coinmoney/fullscreen'))
			return $this->CheckForPayment($payment);
		else
			return $this->CreateInvoiceAndRedirect($payment, $amount);

	}
	
	function CheckForPayment($payment)
	{
		$quoteId = $payment->getOrder()->getQuoteId();
		$ipn = Mage::getModel('Coinmoney/ipn');
		if (!$ipn->GetQuotePaid($quoteId))
		{
			Mage::throwException("Order not paid for.  Please pay first and then Place your Order.");
		}
		else if (!$ipn->GetQuoteComplete($quoteId))
		{			
			// order status will be PAYMENT_REVIEW instead of PROCESSING
			$payment->setIsTransactionPending(true); 
		}
		else
		{
			$this->MarkOrderPaid($payment->getOrder());
		}
		
		return $this;
	}
	
	function MarkOrderPaid($order)
	{
		$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
		if (!count($order->getInvoiceCollection()))	
		{
			$invoice = $order->prepareInvoice()
				->setTransactionId(1)
				->addComment('Invoiced automatically by Coinmoney/controllers/IndexController.php')
				->register()
				->pay();

			$transactionSave = Mage::getModel('core/resource_transaction')
				->addObject($invoice)
				->addObject($invoice->getOrder());
			$transactionSave->save();
		}
	}
	
	// given Mage_Core_Model_Abstract, return api-friendly address
	function ExtractAddress($address)
	{
		$options = array();
		$options['buyerName'] = $address->getName();
		if ($address->getCompany())
			$options['buyerName'] = $options['buyerName'].' c/o '.$address->getCompany();
		$options['buyerAddress1'] = $address->getStreet1();
		$options['buyerAddress2'] = $address->getStreet2();
		$options['buyerAddress3'] = $address->getStreet3();
		$options['buyerAddress4'] = $address->getStreet4();
		$options['buyerCity'] = $address->getCity();
		$options['buyerState'] = $address->getRegionCode();
		$options['buyerZip'] = $address->getPostcode();
		$options['buyerCountry'] = $address->getCountry();
		$options['buyerEmail'] = $address->getEmail();
		$options['buyerPhone'] = $address->getTelephone();
		// trim to fit API specs
		foreach(array('buyerName', 'buyerAddress1', 'buyerAddress2', 'buyerAddress3', 'buyerAddress4', 'buyerCity', 'buyerState', 'buyerZip', 'buyerCountry', 'buyerEmail', 'buyerPhone') as $f)
			$options[$f] = substr($options[$f], 0, 100); 
		return $options;
	}
	
	function CreateInvoiceAndRedirect($payment, $amount)
	{
    $apiKey = Mage::getStoreConfig('payment/Coinmoney/api_key');
		$speed = Mage::getStoreConfig('payment/Coinmoney/speed');

		$order = $payment->getOrder();
		$orderId = $order->getIncrementId();  
		$options = array(
			'currency' => $order->getBaseCurrencyCode(),
			'buyerName' => $order->getCustomerFirstname().' '.$order->getCustomerLastname(),			
			'fullNotifications' => 'true',
			'notificationURL' => Mage::getUrl('coinmoney_callback'),
			'redirectURL' => Mage::getUrl('customer/account'),
			'transactionSpeed' => $speed,
			'apiKey' => $apiKey,
			);
		$options += $this->ExtractAddress($order->getShippingAddress());
			
		$invoice = bpCreateInvoice($orderId, $amount, array('orderId' => $orderId), $options);

		$payment->setIsTransactionPending(true); // status will be PAYMENT_REVIEW instead of PROCESSING

		if (array_key_exists('error', $invoice)) 
		{
			Mage::log('Error creating coinmoney invoice', null, 'coinmoney.log');
			Mage::log($invoice['error'], null, 'coinmoney.log');
			Mage::throwException("Error creating xCoinmoney.com invoice.  Please try again or use another payment option.");
		}
		else
		{
			$invoiceId = Mage::getModel('sales/order_invoice_api')->create($orderId, array());
			Mage::getSingleton('customer/session')->setRedirectUrl($invoice['url']);
		}

		return $this;
	}

	public function getOrderPlaceRedirectUrl()
	{
		if (Mage::getStoreConfig('payment/Coinmoney/fullscreen'))
			return Mage::getSingleton('customer/session')->getRedirectUrl();
		else
			return '';
	}
	
	# computes a unique hash determined by the contents of the cart
	public function getQuoteHash($quoteId)
	{
		$quote = Mage::getModel('sales/quote')->load($quoteId, 'entity_id');
		if (!$quote)
		{
			Mage::log('getQuoteTimestamp: quote not found', NULL, 'coinmoney.log');
			return false;
		}
		
		#encode items
		$items = $quote->getAllItems();
		$latest = NULL;
		$description = '';
		foreach($items as $i)
		{
			$description.= 'i'.$i->getItemId().'q'.$i->getQty();				

			# could encode $i->getOptions() here but item ids are incremented if options are changed
		}
		
		$hash = base64_encode(hash_hmac('sha256', $description, $quoteId));
		$hash = substr($hash, 0, 30); // fit it in posData maxlen
		
		Mage::log("quote $quoteId descr $description hash $hash", NULL, 'coinmoney.log');
		
		return $hash;
	}
	
}
?>