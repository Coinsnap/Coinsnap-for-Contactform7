<?php
if (!defined( 'ABSPATH' )){
    exit;
}

use Coinsnap\Util\Notice;
use Coinsnap\Client\Webhook;

class CoinsnapCf7 {
    private static $_instance = null;
    protected $_full_path = __FILE__;
    private $_postid;
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing','Invalid'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing','InvoiceInvalid'];

    public function __construct() {
        add_filter( 'wpcf7_editor_panels', array( $this, 'coinsnapcf7_editor_panels' ) );
        add_action( 'wpcf7_admin_after_additional_settings', array($this,'coinsnapcf7_admin_after_additional_settings') );
        add_action( 'wpcf7_save_contact_form', array( $this, 'coinsnapcf7_save_settings' ) );
        add_filter( 'wpcf7_validate', array( $this, 'coinsnapcf7_payment_validation'),10, 2);
        add_action( 'wpcf7_before_send_mail', array( $this, 'coinsnap_payment_before' ), 20 );
        add_action( 'wpcf7_mail_sent', array( $this, 'coinsnap_payment_after' ),30 );
        add_action( 'init', array( $this, 'process_webhook' ) );
        add_action( 'admin_menu', array( $this, 'coinsnapcf7_admin_menu' ), 20 );
                
        if (is_admin()) {
            add_action( 'wpcf7_admin_notices', array( $this, 'coinsnapcf7_webhook'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_cf7_coinsnap_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_cf7_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // Adding template redirect handling for coinsnap-for-cf7-btcpay-settings-callback.
        add_action( 'template_redirect', function(){
    
            global $wp_query;
            $notice = new \Coinsnap\Util\Notice();
            
            $post_id = $this->_postid = filter_input(INPUT_GET,'cf7_post',FILTER_SANITIZE_STRING);

            // Only continue on a coinsnap-for-cf7-btcpay-settings-callback request.    
            if (!isset( $wp_query->query_vars['coinsnap-for-cf7-btcpay-settings-callback'])) {
                return;
            }
            
            if(!isset($wp_query->query_vars['coinsnap-for-cf7-btcpay-nonce']) || !wp_verify_nonce($wp_query->query_vars['coinsnap-for-cf7-btcpay-nonce'],'coinsnapcf7-btcpay-nonce')){
                return;
            }

            $CoinsnapBTCPaySettingsUrl = admin_url('admin.php?page=wpcf7&post='.$post_id.'&active-tab=coinsnap&provider=btcpay');

            $btcpay_server_url = get_post_meta( $post_id, "_cf7_btcpay_server_url", true );
            $btcpay_api_key  = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $client = new \Coinsnap\Client\Store($btcpay_server_url,$btcpay_api_key);
            if (count($client->getStores()) < 1) {
                $messageAbort = __('Error on verifying redirected API Key with stored BTCPay Server url. Aborting API wizard. Please try again or continue with manual setup.', 'coinsnap-for-contact-form-7');
                $notice->addNotice('error', $messageAbort);
                wp_redirect($CoinsnapBTCPaySettingsUrl);
            }

            // Data does get submitted with url-encoded payload, so parse $_POST here.
            if (!empty($_POST)) {
                $data['apiKey'] = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
                if(isset($_POST['permissions'])){
                    $permissions = array_map('sanitize_text_field', wp_unslash($_POST['permissions']));
                    if(is_array($permissions)){
                        foreach ($permissions as $key => $value) {
                            $data['permissions'][$key] = sanitize_text_field($permissions[$key] ?? null);
                        }
                    }
                }
            }
    
            if (isset($data['apiKey']) && isset($data['permissions'])) {

                $apiData = new \Coinsnap\Client\BTCPayApiAuthorization($data);
                if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {

                    update_post_meta( $post_id, "_cf7_btcpay_api_key", $apiData->getApiKey());
                    update_post_meta( $post_id, "_cf7_btcpay_store_id", $apiData->getStoreID());
                    update_post_meta( $post_id, "_cf7_coinsnap_provider", 'btcpay');

                    $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-contact-form-7'));

                    // Register a webhook.
                    if ($this->registerWebhook( $btcpay_server_url, $apiData->getApiKey(), $apiData->getStoreID())) {
                        $messageWebhookSuccess = __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-contact-form-7' );
                        $notice->addNotice('success', $messageWebhookSuccess);
                    }
                    else {
                        $messageWebhookError = __( 'Could not register a new webhook on the store.', 'coinsnap-for-contact-form-7' );
                        $notice->addNotice('error', $messageWebhookError );
                    }

                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
                else {
                    $notice->addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'coinsnap-for-contact-form-7'));
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
            }

            $notice->addNotice('error', __('Error processing the data from Coinsnap. Please try again.', 'coinsnap-for-contact-form-7'));
            wp_redirect($CoinsnapBTCPaySettingsUrl);
            exit();
        });
    }
    
    public function coinsnapConnectionHandler(){
        $post_id = filter_input(INPUT_POST,'cf7_post',FILTER_SANITIZE_STRING);
        $this->_postid = $post_id;
        
        $_nonce = filter_input(INPUT_POST,'_wpnonce',FILTER_SANITIZE_STRING);
        
        if(empty($this->getApiUrl()) || empty($this->getApiKey())){
            $response = [
                    'result' => false,
                    'message' => __('Contact Form 7: empty gateway URL or API Key', 'coinsnap-for-contact-form-7')
            ];
            $this->sendJsonResponse($response);
        }
        
        
        $_provider = get_post_meta( $post_id, "_cf7_coinsnap_provider", true );
        
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(),$this->getApiKey());
        $currency = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
        $store = new \Coinsnap\Client\Store($this->getApiUrl(),$this->getApiKey());
        
        if($_provider === 'btcpay'){
                        
            try {
                
                $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'bitcoin','calculation');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'lightning','calculation');
                    }
                }
            }
            catch (\Throwable $e) {
                $response = [
                        'result' => false,
                        'message' => __('Contact Form 7: API connection is not established (0)', 'coinsnap-for-contact-form-7')
                ];
                $this->sendJsonResponse($response);
            }
        }
        else {
            $checkInvoice = $client->checkPaymentData(0,$currency,'coinsnap','calculation');
        }
        
        if(isset($checkInvoice) && $checkInvoice['result']){
            $connectionData = __('Min order amount is', 'coinsnap-for-contact-form-7') .' '. $checkInvoice['min_value'].' '.$currency;
        }
        else {
            $connectionData = __('No payment method is configured', 'coinsnap-for-contact-form-7');
        }
        
        $_message_disconnected = ($_provider !== 'btcpay')? 
            __('Contact Form 7: Coinsnap server is disconnected', 'coinsnap-for-contact-form-7') :
            __('Contact Form 7: BTCPay server is disconnected', 'coinsnap-for-contact-form-7');
        $_message_connected = ($_provider !== 'btcpay')?
            __('Contact Form 7: Coinsnap server is connected', 'coinsnap-for-contact-form-7') : 
            __('Contact Form 7: BTCPay server is connected', 'coinsnap-for-contact-form-7');
        
        if( wp_verify_nonce($_nonce,'coinsnap-ajax-nonce') ){
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
                $this_store = $store->getStore($this->getStoreId());
                
                if ($this_store['code'] !== 200) {
                    $this->sendJsonResponse($response);
                }
                
                $webhookExists = $this->webhookExists( $this->getApiUrl(), $this->getApiKey(), $this->getStoreId());

                
                if($webhookExists) {
                    $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                    $this->sendJsonResponse($response);
                }
                
                

                $webhook = $this->registerWebhook( $this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
                $response['result'] = (bool)$webhook;
                $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
            }
            catch (\Throwable $e) {
                //$response['message'] = $e->getMessage();
                $response['message'] =  __('Contact Form 7: API connection is not established (0+)', 'coinsnap-for-contact-form-7');
            }

            $this->sendJsonResponse($response);
        }      
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    public function enqueueAdminScripts( $hook ) {
	// Register the CSS file
	wp_register_style( 'coinsnap-admin-styles', plugins_url('assets/css/coinsnapcf7-backend-style.css', __FILE__ ), array(), COINSNAPCF7_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnap-admin-styles' );
        //  Enqueue admin fileds handler script
        
        if('wpcf7' === filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS) && filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT) > 0){
            wp_enqueue_script('coinsnap-admin-fields',plugins_url('assets/js/adminFields.js', __FILE__ ),[ 'jquery' ],COINSNAPCF7_VERSION,true);
        }
        
        wp_enqueue_script('coinsnap-connection-check',plugin_dir_url( __FILE__ ) . 'assets/js/connectionCheck.js',[ 'jquery' ],COINSNAPCF7_VERSION,true);
        wp_localize_script('coinsnap-connection-check', 'coinsnap_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnap-ajax-nonce' ),
            'cf7_post' => sanitize_text_field( filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT) )
        ));
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $post_id = filter_input(INPUT_POST,'cf7_post',FILTER_SANITIZE_STRING);
        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_STRING);
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        if ( current_user_can( 'manage_options' ) ) {
            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_STRING), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            $permissions = array_merge([
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices'
            ],
            [
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
            ]);

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'ContactForm7',
                    true,
                    true,
                    home_url('?coinsnap-for-cf7-btcpay-settings-callback&cf7_post='.$post_id),
                    null
		);

		// Store the host to options before we leave the site.
		update_post_meta( $post_id, "_cf7_btcpay_server_url", $host);

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                Logger::debug('Error fetching redirect url from BTCPay Server.');
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }
        
    /**
        * Method checks if Coinsnap payment is enabled and 
        * payment should be before e-mail submission. 
        * If true, redirects to payment, otherwise - skips payment.
        *
        * @param WPCF7_ContactForm $contact_form The Contact Form 7 form object.
    */
    public function coinsnap_payment_before($cf7){
            $post_id = $cf7->id();
            $enable = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
            $paymentFirst = get_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", true );
            
            if($paymentFirst && $enable){
                $this->coinsnap_redirect_payment($cf7);
            }
    }
        
    /**
        * Method checks if Coinsnap payment is enabled and 
        * payment should be after e-mail submission. 
        * If true, redirects to payment, otherwise - returns false.
        *
        * @param WPCF7_ContactForm $contact_form The Contact Form 7 form object.
    */
    public function coinsnap_payment_after($cf7){
            $post_id = $cf7->id();
            $enable = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
            $paymentFirst = get_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", true );
            
            if(!$paymentFirst && $enable){
                $this->coinsnap_redirect_payment($cf7);
            }
            else {
                return false;
            }
    }
        
    function coinsnapcf7_amount_validation( $amount, $currency ) {
            $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
                    
            $_provider = $this->get_payment_provider();
            if($_provider === 'btcpay'){

                $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
                
                try {
                    $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                    if ($storePaymentMethods['code'] === 200) {
                        if(!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                            $errorMessage = __( 'No payment method is configured on BTCPay server', 'coinsnap-for-contact-form-7' );
                            $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                        }
                    }
                    else {
                        $errorMessage = __( 'Error store loading. Wrong or empty Store ID', 'coinsnap-for-contact-form-7' );
                        $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                    }

                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'bitcoin');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'lightning');
                    }
                }
                catch (\Throwable $e){
                    $errorMessage = __( 'API connection is not established.', 'coinsnap-for-contact-form-7' );
                    $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                }
            }
            else {
                $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ));
            }
            return $checkInvoice;
                    
    }
  
    function coinsnapcf7_payment_validation( $result, $tags ) {
            
            $post_id = sanitize_text_field( filter_input(INPUT_POST,'_wpcf7',FILTER_VALIDATE_INT) );
            $this->_postid = $post_id;
            $is_cs_amount = false;
            
            foreach($tags as $tag){
                if('cs_amount' === $tag->name){
                    $is_cs_amount = true;
                    $amount = (float)filter_input(INPUT_POST,'cs_amount',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ;
                    $currency = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
                    
                    $checkInvoice = $this->coinsnapcf7_amount_validation( $amount, $currency );
                    
                    if($checkInvoice['result'] !== true){
                        if($checkInvoice['error'] === 'currencyError'){
                            $errorMessage = sprintf( 
                            /* translators: 1: Currency */
                            __( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-contact-form-7' ), strtoupper( $currency ));
                        }      
                        elseif($checkInvoice['error'] === 'amountError'){
                            $errorMessage = sprintf( 
                            /* translators: 1: Amount, 2: Currency */
                            __( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-contact-form-7' ), $checkInvoice['min_value'], strtoupper( $currency ));
                        }
                        else {
                            $errorMessage = $checkInvoice['error'];
                        }
                        $result->invalidate( $tag, $errorMessage);
                    }
                }
                
                if('submit' === $tag->type){
                    $tag_submit = $tag;
                }
            }
            if(!$is_cs_amount){
                $errorMessage = esc_html__("Form doesn't contain cs_amount field",'coinsnap-for-contact-form-7');
                $result->invalidate( $tag_submit, $errorMessage);
            }
            return $result;
    }
        
    public function coinsnapcf7_webhook($cf7){
            
            $notices = new Notice(); 
            $notices->showNotices();
    }

    public static function get_instance() {
        if ( self::$_instance == null ) {
            self::$_instance = new CoinsnapCf7();
        }
        return self::$_instance;
    }

    function coinsnapcf7_admin_menu() {
        add_submenu_page( 'wpcf7', esc_html__( 'Coinsnap Payments', 'coinsnap-for-contact-form-7' ), esc_html__( 'Coinsnap Payments', 'coinsnap-for-contact-form-7' ), 'wpcf7_edit_contact_forms', 'coinsnapcf7_admin_list_trans', array($this,'coinsnapcf7_admin_list_trans') );
    }

    function coinsnapcf7_admin_list_trans() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( esc_html__( "You do not have sufficient permissions to access this page.", "coinsnap-for-contact-form-7" ) );
        }

        global $wpdb;
        $pagenum      = ( filter_input(INPUT_GET,'pagenum',FILTER_VALIDATE_INT) !== null ) ? absint( filter_input(INPUT_GET,'pagenum',FILTER_VALIDATE_INT) ) : 1;
        $limit        = 20;
        $offset       = ( $pagenum - 1 ) * $limit;
        $table_name   = $this->get_tablename();
        $transactions = $wpdb->get_results( $wpdb->prepare("SELECT * FROM %i ORDER BY id DESC LIMIT %d, %d", $table_name, $offset, $limit ), ARRAY_A);
                
        $total        = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(id) FROM %i  ",$table_name) );
        $num_of_pages = ceil( $total / $limit );
        $cntx         = 0;
	
        echo '<div class="wrap">
		<h2>Coinsnap Payments</h2>
		<table class="widefat post fixed" cellspacing="0">
			<thead>
				<tr>
                <th scope="col"  width="15%" class="manage-column" style="">Contact Form</th>
                <th scope="col"  width="" class="manage-column" style="">Order ID</th>
                <th scope="col"  width="" class="manage-column" style="">Date</th>
                <th scope="col"  width="15%" class="manage-column" style="">Name</th>
                <th scope="col"  width="15%" class="manage-column" style="">E-Mail</th>                    
                <th scope="col"  width="15%" class="manage-column" style="">Amount</th>
                <th scope="col"  width="13%" class="manage-column" style="">Status</th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th scope="col"  width="15%" class="manage-column" style="">Contact Form</th>
					<th scope="col"  width="" class="manage-column" style="">Order ID</th>
					<th scope="col"  width="" class="manage-column" style="">Date</th>
					<th scope="col"  width="15%" class="manage-column" style="">Name</th>
                    <th scope="col"  width="15%" class="manage-column" style="">E-Mail</th>                    
					<th scope="col"  width="15%" class="manage-column" style="">Amount</th>
					<th scope="col"  width="13%" class="manage-column" style="">Status</th>
				</tr>
			</tfoot>
			<tbody>';
		if ( count( $transactions ) == 0 ) {
			echo '<tr class="alternate author-self status-publish iedit" valign="top">
					<td class="" colspan="7">No Data</td>
				</tr>';
		} else {
			foreach ( $transactions as $transaction ) {
				$datetime = new DateTime("@{$transaction['submit_time']}");
				$formattedDate = $datetime->format('F j, Y g:i:s A');
				echo '<tr class="alternate author-self status-publish iedit" valign="top">
					<td class="">' . esc_html(get_the_title( $transaction['form_id']) ) . '</td>';
				echo '<td class="">' . esc_html($transaction['id']) . '</td>';
				echo '<td class="">' . esc_html($formattedDate) . '</td>';
				echo '<td class="">' . esc_html($transaction['name']) . '</td>';
				echo '<td class="">' . esc_html($transaction['email']) . '</td>';
				echo '<td class="">' . esc_html($transaction['amount']) . '</td>';
				echo '<td class="">' . esc_html($transaction['status']) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table><br>';
		$page_links = paginate_links( array(
			'base'      => add_query_arg( 'pagenum', '%#%' ),
			'format'    => '',
			'prev_text' => __( '&laquo;', 'coinsnap-for-contact-form-7' ),
			'next_text' => __( '&raquo;', 'coinsnap-for-contact-form-7' ),
			'total'     => $num_of_pages,
			'current'   => $pagenum
		) );
		if ( $page_links ) {
			echo wp_kses('<center><div class="tablenav"><div class="tablenav-pages" style="float:none; margin: 1em 0">' . $page_links . '</div></div></center>',array('center' => array(), 'div' => array('class' => true, 'style' => true, 'id' => true), 'a' => array('class' => true, 'style' => true, 'href' => true), 'span' => array('class' => true, 'style' => true, 'id' => true) ));
		}
		echo '<br><hr></div>';
    }

    function coinsnapcf7_save_settings( $cf7 ) {

        $notice = new \Coinsnap\Util\Notice();
        $post_id = sanitize_text_field( filter_input(INPUT_POST,'post',FILTER_VALIDATE_INT) );
        $this->_postid = $post_id;
            
        if ( !empty( filter_input(INPUT_POST,'coinsnap_enable',FILTER_VALIDATE_INT) ) ) {
            $coinsnap_enable = sanitize_text_field( filter_input(INPUT_POST,'coinsnap_enable',FILTER_VALIDATE_INT) );
            update_post_meta( $post_id, "_cf7_coinsnap_enable", $coinsnap_enable );
        }
        else {
            update_post_meta( $post_id, "_cf7_coinsnap_enable", 0 );
        }
            
        if ( !empty( filter_input(INPUT_POST,'coinsnap_autoredirect',FILTER_VALIDATE_INT) ) ) {
            $coinsnap_autoredirect = sanitize_text_field( filter_input(INPUT_POST,'coinsnap_autoredirect',FILTER_VALIDATE_INT) );
            update_post_meta( $post_id, "_cf7_coinsnap_autoredirect", $coinsnap_autoredirect );
        }
        else {
            update_post_meta( $post_id, "_cf7_coinsnap_autoredirect", 0 );
        }
            
        if ( !empty( filter_input(INPUT_POST,'coinsnap_paymentfirst',FILTER_VALIDATE_INT) ) ) {
            $coinsnap_paymentfirst = sanitize_text_field( filter_input(INPUT_POST,'coinsnap_paymentfirst',FILTER_VALIDATE_INT) );
            update_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", 0 ); //$coinsnap_paymentfirst
        }
        else {
            update_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", 0 );
        }

            update_post_meta( $post_id, "_cf7_coinsnap_currency", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_currency',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            
            update_post_meta( $post_id, "_cf7_coinsnap_provider", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_provider',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            
            update_post_meta( $post_id, "_cf7_coinsnap_store_id", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_store_id',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_coinsnap_api_key", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_api_key',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            
            update_post_meta( $post_id, "_cf7_btcpay_server_url", sanitize_text_field( filter_input(INPUT_POST,'btcpay_server_url',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_btcpay_store_id", sanitize_text_field( filter_input(INPUT_POST,'btcpay_store_id',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_btcpay_api_key", sanitize_text_field( filter_input(INPUT_POST,'btcpay_api_key',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            
            update_post_meta( $post_id, "_cf7_coinsnap_s_url", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_s_url',FILTER_SANITIZE_URL) ) );        
            
        $client = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
        try {
            $store = $client->getStore($this->getStoreId());
            
            if ($store['code'] === 200) {                    
                if ( !$this->webhookExists( $this->getApiUrl(), $this->getApiKey(), $this->getStoreId() )) {
                    if ( !$this->registerWebhook( $this->getApiUrl(), $this->getApiKey(), $this->getStoreId() )) {
                        $notice->addNotice('failed', __('Contact Form 7: Unable to create webhook on Coinsnap Server', 'coinsnap-for-contact-form-7'));
                    }
                    else {
                        $notice->addNotice('success',__('Contact Form 7: Successfully registered webhook on Coinsnap Server', 'coinsnap-for-contact-form-7'));
                    }
                }
                else {
                    $notice->addNotice('info',__('Contact Form 7: Webhook already exists, skipping webhook creation', 'coinsnap-for-contact-form-7'));
                }
            }
            else {
                $notice->addNotice('failed', __('Contact Form 7: API connection error (1)', 'coinsnap-for-contact-form-7'));
            }
        }
        catch (\Throwable $e) {
            $notice->addNotice('failed', __('Contact Form 7: API connection error (2)', 'coinsnap-for-contact-form-7'));
        }
    }

    function coinsnapcf7_editor_panels( $panels ) {
        $new_page = array(
            'coinsnap' => array(
		'title'    => __( 'Coinsnap', 'coinsnap-for-contact-form-7' ),
		'callback' => array( $this, 'coinsnapcf7_admin_after_additional_settings' )
            )
	);
	
        $panels = array_merge( $panels, $new_page );
        return $panels;
    }

    function coinsnapcf7_admin_after_additional_settings( $cf7 ) {

        $post_id = sanitize_text_field( filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT) );

        $coinsnap_enable    = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
        $coinsnap_autoredirect = get_post_meta( $post_id, "_cf7_coinsnap_autoredirect", true );
        $coinsnap_paymentfirst = get_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", true );
        $coinsnap_currency = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
        if ( empty( $coinsnap_currency ) ) { $coinsnap_currency = 'EUR'; }
                
                $coinsnap_provider = get_post_meta( $post_id, "_cf7_coinsnap_provider", true );
                $coinsnap_provider_array = array(
                    'coinsnap' => 'Coinsnap',
                    'btcpay' => 'BTCPay server'
                );
                
                $coinsnap_store_id = get_post_meta( $post_id, "_cf7_coinsnap_store_id", true );
		$coinsnap_api_key  = get_post_meta( $post_id, "_cf7_coinsnap_api_key", true );
                
		$btcpay_server_url = get_post_meta( $post_id, "_cf7_btcpay_server_url", true );
                $btcpay_store_id = get_post_meta( $post_id, "_cf7_btcpay_store_id", true );
		$btcpay_api_key  = get_post_meta( $post_id, "_cf7_btcpay_api_key", true );
                
		$coinsnap_s_url    = get_post_meta( $post_id, "_cf7_coinsnap_s_url", true );		
                
                $client = new \Coinsnap\Client\Invoice($coinsnap_s_url, $coinsnap_api_key);
                $coinsnapCurrencies = $client->getCurrencies();

		$coinsnap_enable_checked = ( $coinsnap_enable == "1" ) ? "CHECKED" : '';
                $coinsnap_autoredirect_checked = ( $coinsnap_autoredirect == "0" ) ? '' : 'CHECKED';
                $coinsnap_paymentfirst_checked = ($coinsnap_paymentfirst == "0") ? '' : 'CHECKED'; 

		echo '<div class="coinsnapcf7">';
                echo '<div class="coinsnapcf7-row"><div id="coinsnapConnectionStatus"></div></div>';
		echo '<div class="coinsnapcf7-row">
                        <div class="coinsnapcf7-field inline-form">
                            <input type="checkbox" value="1" id="coinsnap_enable" name="coinsnap_enable" ' . esc_html($coinsnap_enable_checked) . '><label for="coinsnap_enable">'. esc_html__('Enable Coinsnap on this form','coinsnap-for-contact-form-7').'</label>
                        </div>
                    </div>';

		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row">
                          <label for="coinsnap_currency">'. esc_html__('Currency Code (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field">
                            <select name="coinsnap_currency" id="coinsnap_currency">';
                
                for($j=0;$j<count($coinsnapCurrencies);$j++){
                    $selectedCurrencyAdd = ($coinsnapCurrencies[$j] === $coinsnap_currency)? ' selected' : '';
                    echo '<option value="'.esc_html($coinsnapCurrencies[$j]).'" '.esc_html($selectedCurrencyAdd).'>'.esc_html($coinsnapCurrencies[$j]).'</option>';
                }
                                
                echo '</select>
                          </div>
                        </div>';
                
                echo '<div class="coinsnapcf7-row">
                          <label for="coinsnap_provider">'. esc_html__('Payment provider','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field">
                            <select name="coinsnap_provider" id="coinsnap_provider" class="long-input">';
                
                foreach($coinsnap_provider_array as $provider_id => $provider){
                    $selectedProviderAdd = ($provider_id === $coinsnap_provider)? ' selected' : '';
                    echo '<option value="'.esc_html($provider_id).'" '.esc_html($selectedProviderAdd).'>'.esc_html($provider).'</option>';
                }
                                
                echo '</select>
                          </div>
                        </div>';
                
                
                //  Coinsnap settings fields
                
                echo '<div class="coinsnapcf7-row coinsnapcf7-coinsnap">
                          <label for="coinsnap_store_id">'. esc_html__('Store ID (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="coinsnap_store_id" class="long-input" type="text" value="' . esc_html($coinsnap_store_id) . '" name="coinsnap_store_id">
                            <div class="description">'. esc_html__('Please input your personal Store ID, which you will find in your Coinsnap account.','coinsnap-for-contact-form-7').'</div>
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row coinsnapcf7-coinsnap">
                          <label for="coinsnap_api_key">'. esc_html__('API Key (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="coinsnap_api_key" class="long-input" type="text" value="' . esc_html($coinsnap_api_key) . '" name="coinsnap_api_key">
                          <div class="description">'. esc_html__('Please input the API Key that you will find in your Coinsnap account.','coinsnap-for-contact-form-7').'</div>
                          </div>
                        </div>';
                
                //  BTCPay settings fields
                
                echo '<div class="coinsnapcf7-row coinsnapcf7-btcpay">
                          <label for="btcpay_server_url">'. esc_html__('BTCPay server URL (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="btcpay_server_url" class="long-input" type="text" value="' . esc_html($btcpay_server_url) . '" name="btcpay_server_url">
                            <div class="description"><a href="#" class="btcpay-apikey-link">'. esc_html__('Check connection','coinsnap-for-contact-form-7').'</a></div>
                          </div>
                        </div>';
                
                echo '<div class="coinsnapcf7-row coinsnapcf7-btcpay">
                          <label for="btcpay_wizard_button">'. esc_html__('Setup wizard','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field">
                           <button class="button btcpay-apikey-link" type="button" id="btcpay_wizard_button" target="_blank">'. esc_html__('Generate API key','coinsnap-for-contact-form-7').'</button>
                          </div>
                        </div>';
                
                
		echo '<div class="coinsnapcf7-row coinsnapcf7-btcpay">
                          <label for="btcpay_store_id">'. esc_html__('Store ID (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="btcpay_store_id" class="long-input" type="text" value="' . esc_html($btcpay_store_id) . '" name="btcpay_store_id">
                            <div class="description">'. esc_html__('Please input your personal Store ID, which you will find in your Coinsnap account.','coinsnap-for-contact-form-7').'</div>
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row coinsnapcf7-btcpay">
                          <label for="btcpay_api_key">'.esc_html__('API Key (required)','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input for="btcpay_api_key" class="long-input" type="text" value="'.esc_html($btcpay_api_key).'" name="btcpay_api_key">
                          <div class="description">'.esc_html__('Please input the API Key that you will find in your Coinsnap account.','coinsnap-for-contact-form-7').'</div>
                          </div>
                        </div>';
                
                
		echo '<div class="coinsnapcf7-row">
                          <label for="coinsnap_s_url">'. esc_html__('Success URL','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="coinsnap_s_url" class="long-input" type="text" value="' . esc_html($coinsnap_s_url) . '" name="coinsnap_s_url">
                          <div class="description">'. esc_html__('Please enter here the URL of the page on your website that the buyer will be redirected to after he finalizes the transaction. (Note: You must create this page, i.e. a “thank you”-page, yourself on your website!)','coinsnap-for-contact-form-7').'</div> 
                          </div>
                        </div>';/*
		echo '<div class="coinsnapcf7-row">
                        <div class="coinsnapcf7-field inline-form">
                            <input type="checkbox" value="1" name="coinsnap_paymentfirst" id="coinsnap_paymentfirst" '.esc_html($coinsnap_paymentfirst_checked).'><label for="coinsnap_paymentfirst">'. esc_html__('Payment before e-Mail submission','coinsnap-for-contact-form-7').'</label>
                        </div>
                    </div>';*/

		echo '<div class="coinsnapcf7-row">
                        <div class="coinsnapcf7-field inline-form">
                            <input type="checkbox" value="1" name="coinsnap_autoredirect" id="coinsnap_autoredirect" '.esc_html($coinsnap_autoredirect_checked).'><label for="coinsnap_autoredirect">'. esc_html__('Auto-redirect after payment','coinsnap-for-contact-form-7').'</label>
                        </div>
                    </div>';

		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row"><h3>'. wp_kses(__('How do I integrate Bitcoin-Lighting Payment in my form?','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).'</h3></div><ol>';
		echo '<li class="coinsnapcf7-row">'. wp_kses(__('To specify the cost of your offering, use the number element and name it','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).' <strong>cs_amount</strong>: [number* cs_amount min:0.01]</li>';
		echo '<li class="coinsnapcf7-row">'. wp_kses(__('You define the cost of your offering by manipulating the value of the 0.01 in the tag, i.e. writing','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).' [number* cs_amount min:2.50]</li>';
		echo '<li class="coinsnapcf7-row">'. wp_kses(__('If you want to see the name and/or the email of the buyer in the transaction overview in your Coinsnap account, name the respective fields accordingly <strong>cs_name</strong> and <strong>cs_email</strong>:','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).' [text cs_name] [email cs_email]</li>';
		echo '</ol><div class="coinsnapcf7-row"><p>'. wp_kses(__('NOTE: <strong>cs_amount</strong> is a mandatory field you must use in your form to make the plugin work. <strong>cs_name</strong> and <strong>cs_email</strong> you only need to use if you want to see this information in your Coinsnap transaction overview.','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).'</p></div>';
		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row"><h3>'. wp_kses(__('Or copy this code into your form, and then add all other fields needed to create your transactional form:','coinsnap-for-contact-form-7'),['b' => array(),'strong' => array()]).'</h3></div>';

		echo '<div class="coinsnapcf7-row">
                    <div class="coinsnapcf7-field">
                        <textarea readonly rows="6">
<label>'. esc_html__('Enter amount','coinsnap-for-contact-form-7').'</label>
[number* cs_amount min:0.01]
<label>'. esc_html__('Your name','coinsnap-for-contact-form-7').'</label>
[text cs_name]
<label>'. esc_html__('Your email','coinsnap-for-contact-form-7').'</label>
[email cs_email]
                        </textarea>
                        <div class="description">'. esc_html__('Please copy the contents of the textarea above and paste it in your form. Note that the `<strong>cs_amount</strong>` field is mandatory, `<strong>cs_name</strong>` and `<strong>cs_email</strong>` are optional.','coinsnap-for-contact-form-7').'</div>
                    </div>
                </div>';
		echo '<input type="hidden" name="post" value="' . esc_html($post_id) . '"></div>';
    }

    public function coinsnap_redirect_payment( $cf7 ) {
        global $wpdb;

        $post_id       = $cf7->id();
        $this->_postid = $post_id;

		$enable = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
                $redirectAutomatically = get_post_meta( $post_id, "_cf7_coinsnap_autoredirect", true );
                $paymentFirst = get_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", true );
                
		if ( ! $enable ) {
                    return false;
		}
                
                $wpcf7           = WPCF7_ContactForm::get_current();
		$submission      = WPCF7_Submission::get_instance();
		$form_properties = $wpcf7->get_properties();
		$form_content = $form_properties['form'];
		$field_to_check = 'cs_amount';
		if ( !str_contains( $form_content, $field_to_check ) ) {
                    return;
		}

		$amount_field    = 'cs_amount';
		$name_field      = 'cs_name';
		$email_field     = 'cs_email';
		
		$submission_data = $submission->get_posted_data();
		$payment_amount  = $submission_data[ $amount_field ] ?? '0';
		$currency        = strtoupper(get_post_meta( $post_id, "_cf7_coinsnap_currency", true ));
		$buyerEmail      = $submission_data[ $email_field ] ?? '';
		$buyerName       = $submission_data[ $name_field ] ?? '';
		
		//  Remove already populated data.
		//  $remove = [ $amount_field, $name_field, $email_field ];
		//  $remainingMetadata = array_diff_key( $submission_data, array_flip( $remove ) );
                
		$table_name = $this->get_tablename();

		$wpdb->insert( $table_name, [
			'form_id'      => $post_id,
			'field_values' => wp_json_encode( $submission_data, true ),
			'submit_time'  => time(),
			'name'         => $buyerName,
			'email'        => $buyerEmail,
			'amount'       => $payment_amount,
		], ['%d', '%s', '%s', '%s', '%s', '%f']   );
                
		if ( $wpdb->last_error != '' ) {
			echo esc_html($wpdb->last_error);
			exit;
		}
                
                $amount = round( $payment_amount, 2 );
                $client  = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );
                
                $checkInvoice = $this->coinsnapcf7_amount_validation( $amount, $currency );
                
                if($checkInvoice['result'] === true){

                    $return_url = get_post_meta( $post_id, "_cf7_coinsnap_s_url", true );
                    $invoice_no               = $wpdb->insert_id;
		
                    $metadata                 = [];
                    $metadata['orderNumber']  = $invoice_no;
                    $metadata['customerName'] = $buyerName;
                    $metadata['customerEmail'] = $buyerEmail;
                    
                    if($this->get_payment_provider() === 'btcpay') {
                        $metadata['orderId'] = $invoice_no;
                    }

                    if($this->get_payment_provider() === 'btcpay' && $currency !== 'BTC'){
                        $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
                        $btcpayCurrencies = $store -> getStoreCurrenciesRates($this->getStoreId(),array($currency));
                        $isCurrency = true;
                        if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                                if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                                    $isCurrency = false;
                                }
                        }
                        else {
                            $isCurrency = false;
                        }

                        // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                        if( !$isCurrency ){
                                $currency = 'BTC';
                                $rate = 1/$checkInvoice['rate'];
                                $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                                $amount = (float)$amountBTC;
                        }
                    }

                    $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
                    
                    $walletMessage = '';
                    
                    try {
                
                        $csinvoice = $client->createInvoice(
                            $this->getStoreId(),
                            strtoupper( $currency ),
                            $camount,
                            $invoice_no,
                            $buyerEmail,
                            $buyerName,
                            $return_url,
                            COINSNAPCF7_REFERRAL_CODE,
                            $metadata,
                            $redirectAutomatically,
                            $walletMessage
                        );

                        $payurl = $csinvoice->getData()['checkoutLink'];
                        wp_redirect( $payurl );
                    }
                    catch (\Throwable $e){
                        $errorMessage = __( 'API connection is not established', 'coinsnap-for-contact-form-7' );
                        throw new PaymentGatewayException(esc_html($errorMessage));
                    }
                }
                
                else {
                    return false;
                }
		
		exit();

	}

    public function get_tablename()
    {
        global $wpdb;
        return $wpdb->prefix . "coinsnapcf7_extension";
    }


    public function process_webhook() {
        global $wpdb;

        //  cf7-listener get parameter check
        if ( filter_input(INPUT_GET,'cf7-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) === null  || filter_input(INPUT_GET,'cf7-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap' ) { return; }
        
        
        
        //  form_id get parameter check
        $form_id = filter_input(INPUT_GET,'form_id',FILTER_VALIDATE_INT);
        if ( $form_id < 1 ) {
            return;
        }

        $this->_postid = $form_id;
        
        try {
            // First check if we have any input
            $rawPostData = file_get_contents("php://input");
            if (!$rawPostData) {
                wp_die('No raw post data received', '', ['response' => 400]);
            }

            // Get headers and check for signature
            $headers = getallheaders();
            $signature = null; $payloadKey = null;
            $_provider = $this->get_payment_provider();
                
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-coinsnap-sig' || strtolower($key) === 'btcpay-sig') {
                    $signature = $value;
                    $payloadKey = strtolower($key);
                }
            }

            // Handle missing or invalid signature
            if (!isset($signature)) {
                wp_die('Authentication required', '', ['response' => 401]);
            }

            // Validate the signature
            $webhook = unserialize(get_post_meta($form_id , "_cf7_coinsnap_webhook", true ));
            if (!Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhook['secret'])) {
                wp_die('Invalid authentication signature', '', ['response' => 401]);
            }
            
            try {

                // Parse the JSON payload
                $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

                if (!isset($postData->invoiceId)) {
                    wp_die('No Coinsnap invoiceId provided', '', ['response' => 400]);
                }

                if(strpos($postData->invoiceId,'test_') !== false){
                    wp_die('Successful webhook test', '', ['response' => 200]);
                }

                $invoice_id = $postData->invoiceId;

                $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
                $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
                $status    = $csinvoice->getData()['status'];
                $order_id = ($this->get_payment_provider() === 'btcpay')? $csinvoice->getData()['metadata']['orderId'] : $csinvoice->getData()['orderId'];

                $payment_res = $csinvoice->getData();
                unset( $payment_res['qrCodes'] );
                unset( $payment_res['lightningInvoice'] );
                $table_name = $this->get_tablename();
                $wpdb->update( $table_name, array(
			'payment_details' => wp_json_encode( $payment_res, true ),
			'status'          => $status
		),
			array( 'id' => $order_id ), array( '%s', '%s' ), array( '%d' )
		);

                echo "OK";
                exit;
            }
            catch (JsonException $e) {
                wp_die('Invalid JSON payload', '', ['response' => 400]);
            }
        }
        
        catch (\Throwable $e) {
            
            $errorMessage = __('Webhook payload error', 'coinsnap-for-contact-form-7' );
            
            wp_die('Internal server error', '', ['response' => 500]);
        }
    }
    
    public function get_payment_provider() {
        return (get_post_meta( $this->_postid, "_cf7_coinsnap_provider", true) === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    public function get_webhook_url() {
        return get_site_url() . '/?cf7-listener=coinsnap&form_id=' . $this->_postid;
    }

    public function getStoreId() {
        return ($this->get_payment_provider() === 'btcpay')? get_post_meta( $this->_postid, "_cf7_btcpay_store_id", true ) : get_post_meta( $this->_postid, "_cf7_coinsnap_store_id", true );
    }

    public function getApiKey() {
        return ($this->get_payment_provider() === 'btcpay')? get_post_meta( $this->_postid, "_cf7_btcpay_api_key", true ) : get_post_meta( $this->_postid, "_cf7_coinsnap_api_key", true );
    }

    public function getApiUrl() {
        return ($this->get_payment_provider() === 'btcpay')? get_post_meta( $this->_postid, "_cf7_btcpay_server_url", true ) : COINSNAP_SERVER_URL;
    }

    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
        
        $form_id = (isset($this->_postid) && $this->_postid > 0)? $this->_postid : 0;
        if($form_id > 0){
        
            $whClient = new Webhook( $apiUrl, $apiKey );
            $storedWebhook = unserialize(get_post_meta($form_id , "_cf7_coinsnap_webhook", true ));
            
            if (is_array($storedWebhook) && isset($storedWebhook['id'])) {

                try {
                    $existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
                    
                    if($existingWebhook->getData()['id'] === $storedWebhook['id'] && strpos( $existingWebhook->getData()['url'], $storedWebhook['url'] ) !== false){
                        return true;
                    }
                }
                catch (\Throwable $e) {
                    $errorMessage = __( 'Error fetching existing Webhook', 'coinsnap-for-contact-form-7' );
                }
            }
            try {
                $storeWebhooks = $whClient->getWebhooks( $storeId );
                foreach($storeWebhooks as $webhook){
                    if(strpos( $webhook->getData()['url'], $this->get_webhook_url() ) !== false){
                        $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                    }
                }
            }
            catch (\Throwable $e) {
                $errorMessage = sprintf( 
                    /* translators: 1: StoreId */
                    __( 'Error fetching webhooks for store ID %1$s', 'coinsnap-for-contact-form-7' ), $storeId);
            }
        }
	return false;
    }
    
    public function registerWebhook(string $apiUrl, $apiKey, $storeId){
        
        $form_id = (isset($this->_postid) && $this->_postid > 0)? $this->_postid : 0;
        if($form_id > 0){
        
            try {
                $whClient = new Webhook( $apiUrl, $apiKey );
                $webhook_events = ($this->get_payment_provider() === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
                $webhook = $whClient->createWebhook(
                    $storeId,   //$storeId
                    $this->get_webhook_url(), //$url
                    $webhook_events,   //$specificEvents
                    null    //$secret
                );

                update_post_meta( $form_id, "_cf7_coinsnap_webhook", serialize([
                        'id' => $webhook->getData()['id'],
                        'secret' => $webhook->getData()['secret'],
                        'url' => $webhook->getData()['url']
                    ]
                ));

                return $webhook;

            }
            catch (\Throwable $e) {
                $errorMessage = __('Error creating a new webhook on Coinsnap instance: ', 'coinsnap-for-contact-form-7' ) . $e->getMessage();
            }

            return null;
        }
    }
}
