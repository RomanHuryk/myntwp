<?php

/* 
 * Main Gateway of Mynt using a daemon online 
 * Authors: Serhack and cryptochangements
 */


class Mynt_Gateway extends WC_Payment_Gateway
{
    private $reloadTime = 30000;
    private $discount;
    private $confirmed = false;
    private $mynt_daemon;

    function __construct()
    {
        $this->id = "mynt_gateway";
        $this->method_title = __("Mynt GateWay", 'mynt_gateway');
        $this->method_description = __("Monero Payment Gateway Plug-in for WooCommerce. You can find more information about this payment gateway on our website. You'll need a daemon online for your address.", 'mynt_gateway');
        $this->title = __("Mynt Gateway", 'mynt_gateway');
        $this->version = "0.3";
        //
        $this->icon = apply_filters('woocommerce_offline_icon', '');
        $this->has_fields = false;

        $this->log = new WC_Logger();

        $this->init_form_fields();
        $this->host = $this->get_option('daemon_host');
        $this->port = $this->get_option('daemon_port');
        $this->address = $this->get_option('mynt_address');
        $this->discount = $this->get_option('discount');

        // After init_settings() is called, you can get the settings and load them into variables, e.g:
        // $this->title = $this->get_option('title' );
        $this->init_settings();

        // Turn these settings into variables we can use
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        add_action('admin_notices', array($this, 'do_ssl_check'));
        add_action('admin_notices', array($this, 'validate_fields'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'instruction'));
        if (is_admin()) {
            /* Save Settings */
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_filter('woocommerce_currencies', 'add_my_currency');
            add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 2);
        }
        $this->mynt_daemon = new Monero_Library($this->host, $this->port);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'mynt_gateway'),
                'label' => __('Enable this payment gateway', 'mynt_gateway'),
                'type' => 'checkbox',
                'default' => 'no'
            ),

            'title' => array(
                'title' => __('Title', 'mynt_gateway'),
                'type' => 'text',
                'desc_tip' => __('Payment title the customer will see during the checkout process.', 'mynt_gateway'),
                'default' => __('Mynt XSM Payment', 'mynt_gateway')
            ),
            'description' => array(
                'title' => __('Description', 'mynt_gateway'),
                'type' => 'textarea',
                'desc_tip' => __('Payment description the customer will see during the checkout process.', 'mynt_gateway'),
                'default' => __('Pay securely using XSM.', 'mynt_gateway')

            ),
            'mynt_address' => array(
                'title' => __('Mynt Address', 'mynt_gateway'),
                'label' => __('Useful for people that have not a daemon online'),
                'type' => 'text',
                'desc_tip' => __('Mynt Wallet Address', 'mynt_gateway')
            ),
            'daemon_host' => array(
                'title' => __('Mynt wallet rpc Host/ IP', 'mynt_gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Daemon Host/IP to authorize the payment with port', 'mynt_gateway'),
                'default' => 'localhost',
            ),
            'daemon_port' => array(
                'title' => __('Mynt wallet rpc port', 'mynt_gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Daemon Host/IP to authorize the payment with port', 'mynt_gateway'),
                'default' => '24090',
            ),
            'discount' => array(
                'title' => __('% discount for using XMR', 'mynt_gateway'),

                'desc_tip' => __('Provide a discount to your customers for making a private payment with XSM!', 'mynt_gateway'),
                'description' => __('Do you want to spread the word about Mynt? Offer a small discount! Leave this empty if you do not wish to provide a discount', 'mynt_gateway'),
                'type' => __('text'),
                'default' => '5%'

            ),
            'environment' => array(
                'title' => __(' Testnet', 'mynt_gateway'),
                'label' => __(' Check this if you are using testnet ', 'mynt_gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box if you are using testnet', 'mynt_gateway'),
                'default' => 'no'
            ),
            'onion_service' => array(
                'title' => __(' SSL warnings ', 'mynt_gateway'),
                'label' => __(' Check to Silence SSL warnings', 'mynt_gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box if you are running on an Onion Service (Suppress SSL errors)', 'mynt_gateway'),
                'default' => 'no'
            ),
        );
    }

    public function add_my_currency($currencies)
    {
        $currencies['XSM'] = __('Mynt', 'woocommerce');
        return $currencies;
    }

    function add_my_currency_symbol($currency_symbol, $currency)
    {
        switch ($currency) {
            case 'XSM':
                $currency_symbol = 'XSM';
                break;
        }
        return $currency_symbol;
    }

    public function admin_options()
    {
        $this->log->add('Mynt_gateway', '[SUCCESS] Mynt Settings OK');

        echo "<h1>Mynt Payment Gateway</h1>";

        echo "<p>Welcome to Mynt Extension for WooCommerce. Getting started: Make a connection with daemon <a href='https://reddit.com/u/myntcoin'>Contact Me</a>";
        echo "<div style='border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#223079;background-color:#9ddff3;'>";
        $this->getamountinfo();
        echo "</div>";
        echo "<table class='form-table'>";
        $this->generate_settings_html();
        echo "</table>";
        echo "<h4>Learn more about using mynt-wallet-rpc <a href=\"https://github.com/mynt-project/myntwp/blob/master/README.md\">here</a></h4>";
    }

    public function getamountinfo()
    {
        $wallet_amount = $this->mynt_daemon->getbalance();
        if (!isset($wallet_amount)) {
            $this->log->add('Mynt_gateway', '[ERROR] Can not connect to mynt-wallet-rpc');
            echo "</br>Your balance is: Not Avaliable </br>";
            echo "Unlocked balance: Not Avaliable";
        }
        else
        {
            $real_wallet_amount = $wallet_amount['balance'] / 1000000000000;
            $real_amount_rounded = round($real_wallet_amount, 6);

            $unlocked_wallet_amount = $wallet_amount['unlocked_balance'] / 1000000000000;
            $unlocked_amount_rounded = round($unlocked_wallet_amount, 6);
        
            echo "Your balance is: " . $real_amount_rounded . " XSM </br>";
            echo "Unlocked balance: " . $unlocked_amount_rounded . " XSM </br>";
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', __('Awaiting offline payment', 'mynt_gateway'));
        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        WC()->cart->empty_cart();

        // Return thank you redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );

    }

    // Submit payment and handle response

    public function validate_fields()
    {
        if ($this->check_mynt() != TRUE) {
            echo "<div class=\"error\"><p>Your Mynt Address doesn't seem valid. Have you checked it?</p></div>";
        }

    }


    // Validate fields

    public function check_mynt()
    {
        $mynt_address = $this->settings['mynt_address'];
        if (strlen($mynt_address) == 95 && substr($mynt_address, 1)) {
            return true;
        }
        return false;
    }
    
    public function is_virtual_in_cart($order_id)
    {
        $order = wc_get_order( $order_id );
        $items = $order->get_items();
        
        foreach ( $items as $item ) {
            $product = new WC_Product( $item['product_id'] );
            if ( $product->is_virtual() ) {
                return true;
            }
        }
        
        return false;
    }
    
    public function instruction($order_id)
    {
        $order = wc_get_order($order_id);
        $amount = floatval(preg_replace('#[^\d.]#', '', $order->get_total()));
        $payment_id = $this->set_paymentid_cookie();
        $currency = $order->get_currency();
        $amount_xmr2 = $this->changeto($amount, $currency, $payment_id);
        $address = $this->address;
        if (!isset($address)) {
            // If there isn't address (merchant missed that field!), $address will be the Monero address for donating :)
            $address = "XSwVkm6aNxF5561yAeAssYZijk5op57G342vdniS7zYBB5tMtJci9pCAfw6wsGNwopHHoDRLfZNA5BbAw8xjHYfW2jaA2VBPs";
        }
        $uri = "mynt:$address?amount=$amount?payment_id=$payment_id";
        $array_integrated_address = $this->mynt_daemon->make_integrated_address($payment_id);
        if (!isset($array_integrated_address)) {
            $this->log->add('Mynt_Gateway', '[ERROR] Unable get integrated address');
            // Seems that we can't connect with daemon, then set array_integrated_address, little hack
            $array_integrated_address["integrated_address"] = $address;
        }
        if($this->is_virtual_in_cart($order_id) == true){
            echo "test";
        }
        $message = $this->verify_payment($payment_id, $amount_xmr2, $order);
        if ($this->confirmed) {
            $color = "006400";
        } else {
            $color = "DC143C";
        }
        echo "<h4><font color=$color>" . $message . "</font></h4>";
        
        echo "
        <head>
        <!--Import Google Icon Font-->
        <link href='https://fonts.googleapis.com/icon?family=Material+Icons' rel='stylesheet'>
        <link href='https://fonts.googleapis.com/css?family=Montserrat:400,800' rel='stylesheet'>
        <link href='http://cdn.monerointegrations.com/style.css' rel='stylesheet'>
        <!--Let browser know website is optimized for mobile-->
            <meta name='viewport' content='width=device-width, initial-scale=1.0'/>
            </head>
            <body>
            <!-- page container  -->
            <div class='page-container'>
            <!-- monero container payment box -->
            <div class='container-xmr-payment'>
            <!-- header -->
            <div class='header-xmr-payment'>
            <span class='logo-xmr'><img src='http://cdn.monerointegrations.com/logomonero.png' /></span>
            <span class='xmr-payment-text-header'><h2>MYNT PAYMENT</h2></span>
            </div>
            <!-- end header -->
            <!-- xmr content box -->
            <div class='content-xmr-payment'>
            <div class='xmr-amount-send'>
            <span class='xmr-label'>Send:</span>
            <div class='xmr-amount-box'>".$amount_xmr2."</div><div class='xmr-box'>XSM</div>
            </div>
            <div class='xmr-address'>
            <span class='xmr-label'>To this address:</span>
            <div class='xmr-address-box'>".$array_integrated_address['integrated_address']."</div>
            </div>
            <div class='xmr-qr-code'>
            <span class='xmr-label'>Or scan QR:</span>
            <div class='xmr-qr-code-box'><img src='https://api.qrserver.com/v1/create-qr-code/? size=200x200&data=".$uri."' /></div>
            </div>
            <div class='clear'></div>
            </div>
            <!-- end content box -->
            <!-- footer xmr payment -->
            <div class='footer-xmr-payment'>
            <a href='https://myntnote.org' target='_blank'>Help</a> | <a href='https://myntnote.org' target='_blank'>About Mynt</a>
            </div>
            <!-- end footer xmr payment -->
            </div>
            <!-- end monero container payment box -->
            </div>
            <!-- end page container  -->
            </body>
        ";
	    
	    
	    
        echo "
      <script type='text/javascript'>setTimeout(function () { location.reload(true); }, $this->reloadTime);</script>";
    }

    private function set_paymentid_cookie()
    {
        if (!isset($_COOKIE['payment_id'])) {
            $payment_id = bin2hex(openssl_random_pseudo_bytes(8));
            setcookie('payment_id', $payment_id, time() + 2700);
        }
        else{
            $payment_id = $this->sanatize_id($_COOKIE['payment_id']);
        }
        return $payment_id;
    }
	
    public function sanatize_id($payment_id)
    {
        // Limit payment id to alphanumeric characters
        $sanatized_id = preg_replace("/[^a-zA-Z0-9]+/", "", $payment_id);
	return $sanatized_id;
    }

    public function changeto($amount, $currency, $payment_id)
    {
        global $wpdb;
        // This will create a table named whatever the payment id is inside the database "WordPress"
        $create_table = "CREATE TABLE IF NOT EXISTS $payment_id (
									rate INT
									)";
        $wpdb->query($create_table);
        $rows_num = $wpdb->get_results("SELECT count(*) as count FROM $payment_id");
        if ($rows_num[0]->count > 0) // Checks if the row has already been created or not
        {
            $stored_rate = $wpdb->get_results("SELECT rate FROM $payment_id");

            $stored_rate_transformed = $stored_rate[0]->rate / 100; //this will turn the stored rate back into a decimaled number

            if (isset($this->discount)) {
                $sanatized_discount = preg_replace('/[^0-9]/', '', $this->discount);
                $discount_decimal = $sanatized_discount / 100;
                $new_amount = $amount / $stored_rate_transformed;
                $discount = $new_amount * $discount_decimal;
                $final_amount = $new_amount - $discount;
                $rounded_amount = round($final_amount, 12);
            } else {
                $new_amount = $amount / $stored_rate_transformed;
                $rounded_amount = round($new_amount, 12); //the moneo wallet can't handle decimals smaller than 0.000000000001
            }
        } else // If the row has not been created then the live exchange rate will be grabbed and stored
        {
            $xmr_live_price = $this->retriveprice($currency);
            $live_for_storing = $xmr_live_price * 100; //This will remove the decimal so that it can easily be stored as an integer
            $new_amount = $amount / $xmr_live_price;
            $rounded_amount = round($new_amount, 12);

            $wpdb->query("INSERT INTO $payment_id (rate)
										 VALUES ($live_for_storing)");
        }

        return $rounded_amount;
    }


    // Check if we are forcing SSL on checkout pages
    // Custom function not required by the Gateway

    public function retriveprice($currency)
    {
        $xmr_price = file_get_contents('https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms=BTC,USD,EUR,CAD,INR,GBP,COP&extraParams=mynt_woocommerce');
        $price = json_decode($xmr_price, TRUE);
        if (!isset($price)) {
            $this->log->add('Mynt_Gateway', '[ERROR] Unable to get the price of Mynt');
        }
        switch ($currency) {
            case 'USD':
                return $price['USD'];
            case 'EUR':
                return $price['EUR'];
            case 'CAD':
                return $price['CAD'];
            case 'GBP':
                return $price['GBP'];
            case 'INR':
                return $price['INR'];
	    case 'COP':
		return $price['COP'];
            case 'XMR':
                $price = '1';
                return $price;
        }
    }
    
    private function on_verified($payment_id, $amount_atomic_units, $order_id)
    {
        $message = "Payment has been received and confirmed. Thanks!";
        $this->log->add('Mynt_gateway', '[SUCCESS] Payment has been recorded. Congratulations!');
        $this->confirmed = true;
        $order = wc_get_order($order_id);
        
        if($this->is_virtual_in_cart($order_id) == true){
            $order->update_status('completed', __('Payment has been received', 'mynt_gateway'));
        }
        else{
            $order->update_status('processing', __('Payment has been received', 'mynt_gateway'));
        }
        global $wpdb;
        $wpdb->query("DROP TABLE $payment_id"); // Drop the table from database after payment has been confirmed as it is no longer needed
                         
        $this->reloadTime = 3000000000000; // Greatly increase the reload time as it is no longer needed
        return $message;
    }
    
    public function verify_payment($payment_id, $amount, $order_id)
    {
        /*
         * function for verifying payments
         * Check if a payment has been made with this payment id then notify the merchant
         */
        $message = "We are waiting for your payment to be confirmed";
        $amount_atomic_units = $amount * 1000000000000;
        $get_payments_method = $this->mynt_daemon->get_payments($payment_id);
        if (isset($get_payments_method["payments"][0]["amount"])) {
            if ($get_payments_method["payments"][0]["amount"] >= $amount_atomic_units)
            {
                $message = $this->on_verified($payment_id, $amount_atomic_units, $order_id);
            }
            if ($get_payments_method["payments"][0]["amount"] < $amount_atomic_units)
            {
                $totalPayed = $get_payments_method["payments"][0]["amount"];
                $outputs_count = count($get_payments_method["payments"]); // number of outputs recieved with this payment id
                $output_counter = 1;

                while($output_counter < $outputs_count)
                {
                         $totalPayed += $get_payments_method["payments"][$output_counter]["amount"];
                         $output_counter++;
                }
                if($totalPayed >= $amount_atomic_units)
                {
                    $message = $this->on_verified($payment_id, $amount_atomic_units, $order_id);
                }
            }
        }
        return $message;
    }

    public function do_ssl_check()
    {
        if ($this->enabled == "yes" && !$this->get_option('onion_service')) {
            if (get_option('woocommerce_force_ssl_checkout') == "no") {
                echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
            }
        }
    }

    public function connect_daemon()
    {
        $host = $this->settings['daemon_host'];
        $port = $this->settings['daemon_port'];
        $monero_library = new Monero($host, $port);
        if ($monero_library->works() == true) {
            echo "<div class=\"notice notice-success is-dismissible\"><p>Everything works! Congratulations and welcome to Mynt. <button type=\"button\" class=\"notice-dismiss\">
						<span class=\"screen-reader-text\">Dismiss this notice.</span>
						</button></p></div>";

        } else {
            $this->log->add('Mynt_gateway', '[ERROR] Plugin can not reach wallet rpc.');
            echo "<div class=\" notice notice-error\"><p>Error with connection of daemon, see documentation!</p></div>";
        }
    }
}
