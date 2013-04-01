<?php
function bplog($contents)
{
	$file = 'bplog.txt';
	file_put_contents($file, date('m-d H:i:s').": ", FILE_APPEND);
	if (is_array($contents))
		file_put_contents($file, var_export($contents, true)."\n", FILE_APPEND);		
	else if (is_object($contents))
		file_put_contents($file, json_encode($contents)."\n", FILE_APPEND);
	else
		file_put_contents($file, $contents."\n", FILE_APPEND);
}

	class bitpay extends PaymentModule
	{
		private $_html = '';
		private $_postErrors = array();

		function __construct()
		{
			$this->name = 'bitpay';
			$this->tab = 'payments_gateways';
			$this->version = '0.1';

			$this->currencies = true;
			$this->currencies_mode = 'checkbox';

			parent::__construct();

			$this->page = basename(__FILE__, '.php');
			$this->displayName = $this->l('bitpay');
			$this->description = $this->l('Accepts payments by Bitcoin via bitpay.');
			$this->confirmUninstall = $this->l('Are you sure you want to delete your details?');
		}

		public function install()
		{
			if (!parent::install() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn'))
			{
				return false;
			}

			$db = Db::getInstance();
			$query = "CREATE TABLE `"._DB_PREFIX_."order_bitcoin` (
			`id_payment` int(11) NOT NULL AUTO_INCREMENT,
			`id_order` int(11) NOT NULL,
			`cart_id` int(11) NOT NULL,
			`invoice_id` varchar(255) NOT NULL,
			`status` varchar(255) NOT NULL,
			PRIMARY KEY (`id_payment`),
			UNIQUE KEY `invoice_id` (`invoice_id`)
			) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8';

			$db->Execute($query);

			return true;
		}

		public function uninstall()
		{
			Configuration::deleteByName('bitpay_APIKEY');
			
			return parent::uninstall();
		}

		public function getContent()
		{
			$this->_html .= '<h2>'.$this->l('bitpay').'</h2>';	
	
			$this->_postProcess();
			$this->_setbitpaySubscription();
			$this->_setConfigurationForm();
			
			return $this->_html;
		}

		function hookPayment($params)
		{
			global $smarty;

			$smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));

			return $this->display(__FILE__, 'payment.tpl');
		}

		private function _setbitpaySubscription()
		{
			$this->_html .= '
			<div style="float: right; width: 440px; height: 150px; border: dashed 1px #666; padding: 8px; margin-left: 12px;">
				<h2>'.$this->l('Opening your bitpay account').'</h2>
				<div style="clear: both;"></div>
				<p>'.$this->l('When opening your bitpay account by clicking on the following image, you are helping us significantly to improve the bitpay Solution:').'</p>
				<p style="text-align: center;"><a href="https://bitpay.com/"><img src="../modules/bitpay/prestashop_bitpay.png" alt="PrestaShop & bitpay" style="margin-top: 12px;" /></a></p>
				<div style="clear: right;"></div>
			</div>
			<img src="../modules/bitpay/bitcoin.png" style="float:left; margin-right:15px;" />
			<b>'.$this->l('This module allows you to accept payments by bitpay.').'</b><br /><br />
			'.$this->l('If the client chooses this payment mode, your bitpay account will be automatically credited.').'<br />
			'.$this->l('You need to configure your bitpay account before using this module.').'
			<div style="clear:both;">&nbsp;</div>';
		}

		private function _setConfigurationForm()
		{
			$this->_html .= '
			<form method="post" action="'.htmlentities($_SERVER['REQUEST_URI']).'">	
				<script type="text/javascript">
					var pos_select = '.(($tab = (int)Tools::getValue('tabs')) ? $tab : '0').';
				</script>
				<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'tabpane.js"></script>
				<link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_CSS_DIR_.'tabpane.css" />
				<input type="hidden" name="tabs" id="tabs" value="0" />
				<div class="tab-pane" id="tab-pane-1" style="width:100%;">
					<div class="tab-page" id="step1">
						<h4 class="tab">'.$this->l('Settings').'</h2>
						'.$this->_getSettingsTabHtml().'
					</div>
				</div>
				<div class="clear"></div>
				<script type="text/javascript">
					function loadTab(id){}
					setupAllTabs();
				</script>
			</form>';
		}

		private function _getSettingsTabHtml()
		{
			global $cookie;

			$html = '
			<h2>'.$this->l('Settings').'</h2>
			<h3 style="clear:both;">'.$this->l('API Key').'</h3>
			<div class="margin-form">
				<input type="text" name="apikey_bitpay" value="'.htmlentities(Tools::getValue('apikey', Configuration::get('bitpay_APIKEY')), ENT_COMPAT, 'UTF-8').'" />
			</div>
			<p class="center"><input class="button" type="submit" name="submitbitpay" value="'.$this->l('Save settings').'" /></p>';
			return $html;
		}

		private function _postProcess()
		{
			global $currentIndex, $cookie;

			if (Tools::isSubmit('submitbitpay'))
			{
				$template_available = array('A', 'B', 'C');

				$this->_errors = array();

				if (Tools::getValue('apikey_bitpay') == NULL)
				{
					$this->_errors[] = $this->l('Missing API Key');
				}
				
				if (count($this->_errors) > 0)
				{
					$error_msg = '';
					foreach ($this->_errors AS $error)
						$error_msg .= $error.'<br />';
					$this->_html = $this->displayError($error_msg);
				}
				else
				{
					Configuration::updateValue('bitpay_APIKEY', trim(Tools::getValue('apikey_bitpay')));

					$this->_html = $this->displayConfirmation($this->l('Settings updated'));
				}
			}
		}

		public function execPayment($cart)
		{
			$currency = Currency::getCurrencyInstance((int)$cart->id_currency);

			// create invoice
			$options = $_POST;
			$options['transactionSpeed'] = 'high';
			$options['currency'] = $currency->iso_code;

			$total = $cart->getOrderTotal(true);

			$options['notificationURL'] = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/ipn.php';
			$options['redirectURL'] = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'order-confirmation.php?id_cart='.$cart->id.'&id_module='.$this->id.'&id_order='.$this->currentOrder;
			$options['posData'] = '{"cart_id": "' . $cart->id . '"';
			$options['posData'].= ', "hash": "' . crypt($cart->id, Configuration::get('bitpay_APIKEY')) . '"';
			$options['posData'].= '}';
			$options['orderID'] = $cart->id;
			$options['price'] = $total;
			
			$postOptions = array('orderID', 'itemDesc', 'itemCode', 'notificationEmail', 'notificationURL', 'redirectURL', 'posData', 'price', 'currency', 'physical', 'fullNotifications', 'transactionSpeed', 'buyerName', 'buyerAddress1', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 'buyerEmail', 'buyerPhone');

			foreach($postOptions as $o)
			{
				if (array_key_exists($o, $options))
				{
					$post[$o] = $options[$o];
				}
			}

			$post = json_encode($post);
			
			// Call BitPay
			$curl = curl_init('https://bitpay.com/api/invoice/');
			$length = 0;
			if ($post)
			{	
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
				$length = strlen($post);
			}

			$uname = base64_encode(Configuration::get('bitpay_APIKEY'));
			$header = array(
				'Content-Type: application/json',
				"Content-Length: $length",
				"Authorization: Basic $uname",
				);

			curl_setopt($curl, CURLOPT_PORT, 443);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_TIMEOUT, 10);
			curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1); // verify certificate
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // check existence of CN and verify that it matches hostname
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
			curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
				
			$responseString = curl_exec($curl);
			
			if($responseString == false) {
				$response = curl_error($curl);
			} else {
				$response = json_decode($responseString, true);
			}
			curl_close($curl);

			header('Location: ' . $response['url']);
		}

		function writeDetails($id_order, $cart_id, $invoice_id, $status)
		{
			$invoice_id = stripslashes(str_replace("'", '', $invoice_id));
			$status = stripslashes(str_replace("'", '', $status));

			$db = Db::getInstance();
			$result = $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_bitcoin` (`id_order`, `cart_id`, `invoice_id`, `status`) VALUES(' . intval($id_order) . ', ' . intval($cart_id) . ', "' . $invoice_id . '", "' . $status . '")');
		}

		function readBitcoinpaymentdetails($id_order)
		{
			$db = Db::getInstance();
			$result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_bitcoin` WHERE `id_order` = ' . intval($id_order) . ';');
			return $result[0];
		}

		function hookInvoice($params)
		{
			global $smarty;

			$id_order = $params['id_order'];

			$bitcoinpaymentdetails = $this->readBitcoinpaymentdetails($id_order);

			$smarty->assign(array(
				'invoice_id' => $bitcoinpaymentdetails['invoice_id'],
				'status' => $bitcoinpaymentdetails['status'],
				'id_order' => $id_order,
				'this_page' => $_SERVER['REQUEST_URI'],
				'this_path' => $this->_path,
				'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"
			));
		
			return $this->display(__FILE__, 'invoice_block.tpl');
		}

		function hookpaymentReturn($params)
		{
			global $smarty;

			$smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));

			return $this->display(__FILE__, 'complete.tpl');
		}
	}
?>