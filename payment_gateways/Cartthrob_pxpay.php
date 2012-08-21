<?php 

class Cartthrob_pxpay extends Cartthrob_payment_gateway
{
	public $title = 'pxpay_title';
 	public $overview = 'pxpay_overview';
 	public $settings = array(
 		array(
			'name' 			=> 'user_id',
			'short_name' 	=> 'user_id', 
			'type' 			=> 'text', 
 		),
		array(
			'name' 			=> 'encryption_key', 
			'short_name' 	=> 'encryption_key', 
			'type' 			=> 'text',
 		),
		array(
			'name' 			=>  'pxpay_transaction_type',
			'short_name' 	=> 'transaction_type',
			'type' 			=> 'radio',
			'default' 		=> 'purchase',
			'options' 		=> array(
				'purchase' 	=> 'sale',
				'auth'		 => 'auth_only'
			)
		),
	);
	
	public $required_fields = array(
 		'email_address',
	);
	
	public $fields = array(
		'first_name'           ,
		'last_name'            ,
		'address'              ,
		'address2'             ,
		'city'                 ,
		'state'                ,
		'zip'                  ,
		'country_code'         ,
		'shipping_first_name'  ,
		'shipping_last_name'   ,
		'shipping_address'     ,
		'shipping_address2'    ,
		'shipping_city'        ,
		'shipping_state'       ,
		'shipping_zip'         ,
		'shipping_country_code',
		'phone'                ,
		'email_address'        ,
		);
	
	public $host = "https://www.paymentexpress.com/pxpay/pxaccess.aspx"; 

	public function initialize()
	{
		
 	}
	/**
	 * process_payment
	 *
 	 * @param string $credit_card_number 
	 * @return mixed | array | bool An array of error / success messages  is returned, or FALSE if all fails.
	 * @author Chris Newton
	 * @access public
	 * @since 1.0.0
	 */
	public function process_payment($credit_card_number)
	{
 		$total = $this->round($this->order('total')); 
		
		$auth['authorized']		= FALSE; 
		$auth['declined']		= FALSE;
		$auth['failed']			= TRUE; 
		$auth['error_message']	= "";
		$auth['transaction_id'] = NULL; 
		
		$req = new SimpleXmlElement('<GenerateRequest></GenerateRequest>');
		$req->addChild('PxPayUserId', 			$this->plugin_settings('user_id'));
		$req->addChild('PxPayKey', 				$this->plugin_settings('encryption_key'));
		$req->addChild('AmountInput', 			$this->order('total'));
		$req->addChild('CurrencyInput', 		$this->order('currency_code') ? $this->order('currency_code') : "NZD");
		$req->addChild('EmailAddress', 			$this->order('email_address'));
		$req->addChild('MerchantReference', 	"Transaction: " . $this->order('entry_id'));
		$req->addChild('TxnType', 				ucfirst($this->plugin_settings('transaction_type')));
		$req->addChild('TxnId', 				$this->order('entry_id'));
		$req->addChild('UrlSuccess', 			$this->response_script(ucfirst(get_class($this)), array("success")));
		$req->addChild('UrlFail', 				$this->response_script(ucfirst(get_class($this)), array("failure")));
		$req->addChild('TxnData1', 				$this->order('entry_id'));
		$req->addChild('TxnData2', 				NULL);
		$req->addChild('TxnData3', 				NULL);
		$req->addChild('Opt',	 				NULL);
 
		$xml = (string) $req->asXML();

		$connect = $this->curl_transaction($this->host, $xml);
		
		if (!$connect)
		{
			$auth['failed'] 		= TRUE;
			$auth['error_message']	= $this->lang('curl_gateway_failure');	
			return $auth; 
		}
		
		$response = @simplexml_load_string($connect);

		if ( empty($response['valid']))
		{
			$auth = array(
				"authorized"	=> FALSE,
				"declined"		=> FALSE,
				"failed"		=> TRUE,
				"error_message"	=> $this->lang("pxpay_unknown_error"),
				"transaction_id"=> NULL,
			);
			return $auth;
		}
		$this->gateway_exit_offsite(NULL,  (string) $response->URI);
		exit;
	}
	public function extload($post)
	{
		$auth  = array(
			'authorized' 	=> FALSE,
			'error_message'	=> NULL,
			'failed'		=> TRUE,
			'processing' 	=> FALSE,
			'declined'		=> FALSE,
			'transaction_id'=> NULL 
			);

		// parsing out the URL here so that we can get the base64encoded URL easier, without losing anything. 
		// it's easy for those extra == characters to get pulled off... causing the process response to fail later. 
		// request URI isn't available on windows machines though
		$url = @parse_url($_SERVER['REQUEST_URI']);
		parse_str($url['query'], $post);
		
		if (empty($post['result']))
		{
	 		die($this->lang('pxpay_no_result')); 
		}

		$req = new SimpleXmlElement('<ProcessResponse></ProcessResponse>');
		$req->addChild('PxPayUserId', 			$this->plugin_settings('user_id'));
		$req->addChild('PxPayKey', 				$this->plugin_settings('encryption_key'));
		$req->addChild('Response', 				$post['result']);
 
		$connect = $this->curl_transaction($this->host,  (string) $req->asXML());
 
		if (!$connect)
		{
			die($this->lang('curl_gateway_failure')); 
	 		
		}
		
		$response = @simplexml_load_string($connect);
		
		if (empty($response->TxnData1))
		{
	 		die($this->lang('pxpay_order_id_missing')); 
		}

		$order_id = (string) $response->TxnData1; 

		
		if ( empty($response['valid']))
		{
			$auth = array(
				"authorized"	=> FALSE,
				"declined"		=> FALSE,
				"failed"		=> TRUE,
				"error_message"	=> $this->lang("pxpay_decryption_error"),
				"transaction_id"=> NULL,
			);
			$this->gateway_order_update($auth, $order_id, $this->order('return'));
		}

 
		// relaunching full cart so that session is active and template content can be displayed. 
		$this->relaunch_cart($cart_id = NULL, $order_id);

		if (!empty($response->Success) && $response->Success == "1")
		{
			$auth  = array(
				'authorized' 	=> TRUE,
				'error_message'	=> NULL,
				'failed'		=> FALSE,
				'processing' 	=> FALSE,
				'declined'		=> FALSE,
				'transaction_id'=> (string) $response->DpsTxnRef, 
				);
 		}
		else
		{
			$auth  = array(
				'authorized' 	=> FALSE,
				'error_message'	=> (string) @$response->ResponseText,
				'failed'		=> TRUE,
				'processing' 	=> FALSE,
				'declined'		=> FALSE,
				'transaction_id'=> (string) @$response->DpsTxnRef, 
				); 
			
			if ($response->ResponseText == "DECLINED")	
			{
				$auth['declined']		= TRUE; 
				$auth['failed']			= FALSE;
			}
			else
			{
				$auth['declined']		= FALSE; 
				$auth['failed']			= TRUE;
			}
 
		}
 		
		$this->gateway_order_update($auth, $order_id, $this->order('return'));
		exit;
	}
 	// END
} // END CLASS