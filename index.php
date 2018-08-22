<?php
/*
Plugin Name: WooCommerce PayUmoney BOLT with Shiprocket
Plugin URI: https://www.payumoney.com/
Description: Extends WooCommerce with PayUmoney BOLT and Shiprocket.
Version: 2.6
Author: PayUmoney
Author URI: https://www.payumoney.com/
Copyright: © 2016 PayUmoney. All rights reserved.
*/

$bd=ABSPATH.'wp-content/plugins/'.dirname( plugin_basename( __FILE__ ) );

add_action('plugins_loaded', 'woocommerce_payumbolt_init', 0);

function woocommerce_payumbolt_init() {

  if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
  /**
   * Localisation
   */
  load_plugin_textdomain('wc-payumbolt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

  if($_GET['msg']!=''){
    add_action('the_content', 'showpayumboltMessage');
  }

  function showpayumboltMessage($content){
    return '<div class="box '.htmlentities($_GET['type']).'-box">'.htmlentities(urldecode($_GET['msg'])).'</div>'.$content;
  }
  /**
   * Gateway class
   */
  class WC_Payumbolt extends WC_Payment_Gateway {
    protected $msg = array();

	protected $logger;

    public function __construct(){
		global $wpdb;
      // Go wild in here
      $this -> id = 'payumbolt';
      $this -> method_title = __('PayUmoney', 'payumbolt');
      $this -> icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/payumoney.png';
      $this -> has_fields = false;
      $this -> init_form_fields();
      $this -> init_settings();
      $this -> title = 'PayUmoney'; //$this -> settings['title'];
      $this -> description = $this -> settings['description'];
      $this -> gateway_module = $this -> settings['gateway_module'];
      $this -> redirect_page_id = $this -> settings['redirect_page_id'];
      $this -> liveurl = 'http://www.payumoney.com';
	  $this -> pum_key = $this -> settings['pum_key'];
	  $this -> pum_salt = $this -> settings['pum_salt'];
	  $this -> pum_url	= $this -> settings['pum_url'];
	  $this -> msg['message'] = "";
      $this -> msg['class'] = "";


      add_action('init', array(&$this, 'check_payumbolt_response'));
      //update for woocommerce >2.0
      add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_payumbolt_response' ) );

      add_action('valid-payumbolt-request', array(&$this, 'SUCCESS'));

      if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
      } else {
        add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
      }

      add_action('woocommerce_receipt_payumbolt', array(&$this, 'receipt_page'));
      add_action('woocommerce_thankyou_payumbolt',array(&$this, 'thankyou_page'));

	  $this->logger = wc_get_logger();


	  add_action('wp_head', array(&$this,'header_modifier'));

    }

	function header_modifier(){
      if($this->gateway_module == 'sandbox')
	  {
		  ?>
          <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" >
          <script id="bolt" src="https://sboxcheckout-static.citruspay.com/bolt/run/bolt.min.js" bolt-
color="e34524" bolt-logo=""></script>
          <?php
	  }
	  else { ?>
	      <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" >
          <script id="bolt" src="https://checkout-static.citruspay.com/bolt/run/bolt.min.js" bolt-color="e34524" bolt-logo=""></script>
      <?php
	  }
    }


    function init_form_fields(){

      $this -> form_fields = array(
        'enabled' => array(
            'title' => __('Enable/Disable', 'payumbolt'),
            'type' => 'checkbox',
						'label' => __('Enable PayUmoney', 'payumbolt'),
            'default' => 'no'),
		  'description' => array(
			'title' => __('Description:', 'payumbolt'),
			'type' => 'textarea',
			'description' => __('This controls the description which the user sees during checkout.', 'payumbolt'),
			'default' => __('Pay securely by Credit or Debit card or net banking through PayUmoney.', 'payumbolt')),
          'gateway_module' => array(
            'title' => __('Gateway Mode', 'payumbolt'),
            'type' => 'select',
            'options' => array("0"=>"Select","sandbox"=>"Sandbox","production"=>"Production"),
            'description' => __('Mode of gateway subscription.','payumbolt')
            ),
		  'pum_key' => array(
            'title' => __('PayUmoney Key', 'payumbolt'),
            'type' => 'text',
            'description' =>  __('PayUmoney merchant key.', 'payumbolt')
            ),
		  'pum_salt' => array(
            'title' => __('PayUmoney Salt', 'payumbolt'),
            'type' => 'text',
            'description' =>  __('PayUmoney merchant salt.', 'payumbolt')
            ),
          'redirect_page_id' => array(
            'title' => __('Return Page'),
            'type' => 'select',
            'options' => $this -> get_pages('Select Page'),
            'description' => "URL of success page"
            ),
		   'pum_url' => array(
            'title' => __('Shiprocket URL', 'payumbolt'),
            'type' => 'text',
            'description' =>  __('Shiprocket Webhook URL (optional)', 'payumbolt')
            )
          );
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     **/
    public function admin_options(){
      echo '<h3>'.__('PayUmoney payment', 'payumbolt').'</h3>';
      echo '<p>'.__('PayUmoney most popular payment gateways for online shopping in India').'</p>';
	  echo '<script src="https://code.jquery.com/jquery-3.2.1.min.js"></script>';
      echo '<table class="form-table">';
      $this -> generate_settings_html();
      echo '</table>';

    }

    /**
     *  There are no payment fields for Citrus, but we want to show the description if set.
     **/
    function payment_fields(){
      if($this -> description) echo wpautop(wptexturize($this -> description));
    }

    /**
     * Receipt Page
     **/
    function receipt_page($order){
      echo '<p>'.__('Thank you for your order, please click the button below to pay.', 'payumbolt').'</p>';
      echo $this -> generate_payumbolt_form($order);
    }

    /**
     * Process the payment and return the result
     **/
     function process_payment($order_id){
            $order = new WC_Order($order_id);

            if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id,
                        add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true)))
                );
            }
            else {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id,
                        add_query_arg('key', $order->get_order_key(), get_permalink(get_option('woocommerce_pay_page_id'))))
                );
            }
        }
    /**
     * Check for valid Citrus server callback
     **/
    function check_payumbolt_response(){

		global $woocommerce;



		if (!isset($_GET['pg'])) {
			//invalid response
			$this -> msg['class'] = 'error';
			$this -> msg['message'] = "Invalid payment gateway response...";

			wc_add_notice( $this->msg['message'], $this->msg['class'] );

			$redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );

			wp_redirect( $redirect_url );
			exit;
		}

		if($_GET['pg'] == 'PayUmoney') {

			$postdata = $_POST;

			if (isset($postdata ['key']) && ($postdata['key'] == $this -> pum_key)) {
				$txnid = $postdata['txnid'];
    	    	$order_id = explode('_', $txnid);
				$order_id = (int)$order_id[0];    //get rid of time part

				$amount      		= 	$postdata['amount'];
				$productInfo  		= 	$postdata['productinfo'];
				$firstname    		= 	$postdata['firstname'];
				$email        		=	$postdata['email'];
				$udf5				=   $postdata['udf5'];
				$keyString 	  		=  	$this -> pum_key.'|'.$txnid.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'|||||';
				$keyArray 	  		= 	explode("|",$keyString);
				$reverseKeyArray 	= 	array_reverse($keyArray);
				$reverseKeyString	=	implode("|",$reverseKeyArray);

				$order = new WC_Order($order_id);


				if (isset($postdata['status']) && $postdata['status'] == 'success') {
				 	$saltString     = $this -> pum_salt.'|'.$postdata['status'].'|'.$reverseKeyString;
					$sentHashString = strtolower(hash('sha512', $saltString));
				 	$responseHashString=$postdata['hash'];

					$this -> msg['class'] = 'error';
					$this -> msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

					if($sentHashString==$responseHashString){

						$this -> msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful with following order details:

							<br>
								Order Id: $order_id <br/>
								Amount: $amount
								<br />


						We will be shipping your order to you soon.";

						$this -> msg['class'] = 'success';

						if($order -> status == 'processing' || $order -> status == 'completed' )
						{
							//do nothing
						}
						else
						{
							//complete the order
							$order -> payment_complete();
							$order -> add_order_note('PayUmoney has processed the payment. Ref Number: '. $txnid);
							$order -> add_order_note($this->msg['message']);
							$order -> add_order_note("Paid by PayUmoney");
							$woocommerce -> cart -> empty_cart();
						}

						//send to shiprocket
						$this->SubmitToWebhook($order,$txnid,$this->pum_url);
					}
					else {
						//tampered
						$this->msg['class'] = 'error';
						$this->msg['message'] = "Thank you for shopping with us. However, the payment failed";
						$order -> update_status('failed');
						$order -> add_order_note('Failed');
						$order -> add_order_note($this->msg['message']);
					}
				} else {
		    		$this -> msg['class'] = 'error';
					$this -> msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
							//Here you need to put in the routines for a failed
							//transaction such as sending an email to customer
							//setting database status etc etc
				}
			}

		}
			//manage msessages
		if (function_exists('wc_add_notice')) {
			wc_add_notice( $this->msg['message'], $this->msg['class'] );
		}
		else {
			if($this->msg['class']=='success'){
				$woocommerce->add_message($this->msg['message']);
			}
			else{
				$woocommerce->add_error($this->msg['message']);
			}
			$woocommerce->set_messages();
		}

		$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
		//For wooCoomerce 2.0
		//$redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );
		wp_redirect( $redirect_url );
		exit;

    }



    /*
     //Removed For WooCommerce 2.0
    function showMessage($content){
         return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
     }*/

    /**
     * Generate Citrus button link
     **/
    public function generate_payumbolt_form($order_id){

		global $woocommerce;
		$order = new WC_Order($order_id);
		$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);

		//For wooCoomerce 2.0
		$redirect_url = add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
		$redirect_url = add_query_arg( 'pg',$this -> title, $redirect_url );  //pass gateway selection in response
		$order_id = $order_id.'_'.date("ymds");

		//do we have a phone number?
		//get currency
		$address = $order -> billing_address_1;
		if ($order -> billing_address_2 != "")
		$address = $address.' '.$order -> billing_address_2;

		$productInfo = "";
	  	$order_items = $order->get_items();
		foreach($order_items as $item_id => $item_data)
		{
			$product = wc_get_product( $item_data['product_id'] );
			$productInfo .= $product->get_sku();
		}
		if($productInfo == "" || $productInfo == 0)	$productInfo = "Product Info";

			$amount = $order -> order_total;
			$firstname = $order -> billing_first_name;
			$lastname = $order -> billing_last_name;
			$zipcode = $order -> billing_postcode;
			$email = $order -> billing_email;
			$phone = $order -> billing_phone;
        	$state = $order -> billing_state;
        	$city = $order -> billing_city;
        	$country = $order -> billing_country;
			$udf5 = "WooCommerce_v_3.x_BOLT";

			$hash=hash('sha512', $this -> pum_key.'|'.$order_id.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'||||||'.$this -> pum_salt);

			$html = "<form name=\"payumbolt-form\" id=\"payumbolt-form\" method=\"POST\">
			<button id='submit_payumbolt_payment_form' onclick='launchBOLT(); return false;'>Pay Now</button>
			<a id='cancel_payumbolt_payment' class=\"button cancel\" href=\"". $order->get_cancel_order_url()."\">".__('Cancel Payment &amp; back to cart')."</a>
			</form>

					<script>
						function launchBOLT()
						{
						bolt.launch({
						key: '".$this -> pum_key."',
						txnid: '".$order_id."',
						hash: '".$hash."',
						amount: '".$amount."',
						firstname: '".$firstname."',
						email: '".$email."',
						phone: '".$phone."',
						productinfo: '".$productInfo."',
						udf5: '".$udf5."',
						surl : '".$redirect_url."',
						furl: '".$redirect_url."'
						},{ responseHandler: function(BOLT){
								console.log( BOLT.response.txnStatus );
								if(BOLT.response.txnStatus != 'CANCEL')
								{
								var fr = '<form action=\"". $redirect_url."\" method=\"post\">' +
  								'<input type=\"hidden\" name=\"key\" value=\"'+BOLT.response.key+'\" />' +
								'<input type=\"hidden\" name=\"txnid\" value=\"'+BOLT.response.txnid+'\" />' +
								'<input type=\"hidden\" name=\"amount\" value=\"'+BOLT.response.amount+'\" />' +
								'<input type=\"hidden\" name=\"productinfo\" value=\"'+BOLT.response.productinfo+'\" />' +
								'<input type=\"hidden\" name=\"firstname\" value=\"'+BOLT.response.firstname+'\" />' +
								'<input type=\"hidden\" name=\"email\" value=\"'+BOLT.response.email+'\" />' +
								'<input type=\"hidden\" name=\"udf5\" value=\"'+BOLT.response.udf5+'\" />' +
								'<input type=\"hidden\" name=\"status\" value=\"'+BOLT.response.status+'\" />' +
								'<input type=\"hidden\" name=\"hash\" value=\"'+BOLT.response.hash+'\" />' +
  								'</form>';
								var form = jQuery(fr);
								jQuery('body').append(form);
								form.submit();
								}
							},
							catchException: function(BOLT){
								alert( BOLT.message );
							}
						});
						}

						launchBOLT();
					</script>
					";

			return $html;

    }


    function get_pages($title = false, $indent = true) {
      $wp_pages = get_pages('sort_column=menu_order');
      $page_list = array();
      if ($title) $page_list[] = $title;
      foreach ($wp_pages as $page) {
        $prefix = '';
        // show indented child pages?
        if ($indent) {
          $has_parent = $page->post_parent;
          while($has_parent) {
            $prefix .=  ' - ';
            $next_page = get_page($has_parent);
            $has_parent = $next_page->post_parent;
          }
        }
        // add to page list array array
        $page_list[$page->ID] = $prefix . $page->post_title;
      }
      return $page_list;
    }

	/**
	* Shiprocket functions
	**/
	public function _sendWebhook($url, $body)
    {

		$bodyJson = json_encode($body);
        try {
        	$headers = array('Content-Type: application/json','Content-Length: ' . strlen($bodyJson));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS,$bodyJson);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch,CURLOPT_TIMEOUT,10000);
			$response  = curl_exec($ch);
			$error = curl_error($ch);
			curl_close($ch);
		    if($error)
				$this->logger->debug( 'CURL Error: '.$error, array( 'source' => 'payumbolt' ) );
		}
		catch(Exception $e)
		{
			$this->logger->debug("Error :: ".$e->getMessage().". Failed to post data to " . $url, array( 'source' => 'payumbolt' ) );
		}
    }

	public function _getWebhookData($order,$txnid)
    {
		$order_data = $order->get_data();
		$order_meta = get_post_meta($order->id);
		$order_items = $order->get_items();
		$weight=0;

		$line_items=array();
		foreach($order_items as $item_id => $item_data)
		{
			$product = wc_get_product( $item_data['product_id'] );
			$weight += $product->get_weight();

			$line_items[]= array ("id" => $item_id,
							   "quantity" => $order->get_item_meta($item_id, '_qty', true),
							   "price" => $product->get_sale_price(),
							   "grams" => $product->get_weight(),
							   "sku" => $product->get_sku(),
							   "product_id" => $product->get_id(),
							   "name" => $product->get_name());
		}

		$ordata = array(
						"checkout_id" => $txnid,
						"email" => $order -> billing_email,
						"total_price" => $order -> order_total,
						"subtotal_price" => $order->get_subtotal(),
						"total_weight"  => $weight,
						"total_tax" => $order->get_total_tax(),
						"taxes_included" => 'true',
						"currency"  => $order_meta[_order_currency][0],
						"total_discounts" => $order_data['discount_total'],
						"line_items" => $line_items
						);

		//$this->logger->debug( 'Data: '.json_encode($ordata), array( 'source' => 'payumbolt' ));
		return $ordata;
    }

	public function SubmitToWebhook($order,$txnid,$url)
    {
		if($url == '') return;

		try {
			$data = $this->_getWebhookData($order,$txnid);

			//$url = 'https://www.payumoney.com/auth/op/receiveCartDetailsForCod/93CD737798EEB6398837ABC76E718C83';

			//$url = 'http://viatechs.in/request.php';

	        $this->_sendWebhook($url, $data);
		}
		catch (Exception $e)
		{
			$this->logger->debug( 'Error: '.$e->getMessage(), array( 'source' => 'payumbolt' ) );
		}
    }
	/**
	End of Shiprocket functions
	**/

  }



  /**
   * Add the Gateway to WooCommerce
   **/
  function woocommerce_add_payumbolt_gateway($methods) {
    $methods[] = 'WC_Payumbolt';
    return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'woocommerce_add_payumbolt_gateway' );
}

?>
