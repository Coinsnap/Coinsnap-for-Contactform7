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
        add_action( 'wpcf7_before_send_mail', array( $this, 'coinsnap_payment_before' ), 20, 3 );
        add_action( 'wpcf7_mail_sent', array( $this, 'coinsnap_payment_after' ),30 );
        add_action( 'init', array( $this, 'process_webhook' ) );
        add_action( 'admin_menu', array( $this, 'coinsnapcf7_admin_menu' ), 20 );
                
        if (is_admin()) {
            add_action( 'wpcf7_admin_notices', array( $this, 'coinsnapcf7_webhook'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_cf7_coinsnap_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_cf7_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // BTCPay posts the new API key back to this public URL. The proof is the one-time state created
        // when the admin started the wizard; the key's store and permissions are asked from BTCPay.
        add_action( 'template_redirect', array( $this, 'btcpayCallback' ) );
    }

    public function btcpayCallback(){
        global $wp_query;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time state is the proof
        if ( !isset( $_GET['coinsnap-for-cf7-btcpay-settings-callback'] ) && !isset( $wp_query->query_vars['coinsnap-for-cf7-btcpay-settings-callback'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $pending = CoinsnapCf7_Security::btcpay_consume_state( $state );
        if ( $pending === null ) {
            wp_die( esc_html__( 'Unauthorized', 'coinsnap-for-contact-form-7' ), '', ['response' => 403] );
        }

        $notice = new \Coinsnap\Util\Notice();
        $post_id = $this->_postid = $pending['form_id'];
        $btcpay_server_url = $pending['url'];
        $CoinsnapBTCPaySettingsUrl = admin_url( 'admin.php?page=wpcf7&post=' . $post_id . '&active-tab=coinsnap&provider=btcpay' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $btcpay_api_key = isset( $_POST['apiKey'] ) ? sanitize_text_field( wp_unslash( $_POST['apiKey'] ) ) : '';
        $store_id = CoinsnapCf7_Security::btcpay_verify_key( $btcpay_server_url, $btcpay_api_key );
        if ( $store_id === null ) {
            $notice->addNotice( 'error', __( 'The BTCPay API key was refused: it must be limited to one store and have exactly the permissions this plugin asks for. Please try again or continue with manual setup.', 'coinsnap-for-contact-form-7' ) );
            wp_safe_redirect( $CoinsnapBTCPaySettingsUrl );
            exit();
        }

        update_post_meta( $post_id, "_cf7_btcpay_server_url", $btcpay_server_url );
        update_post_meta( $post_id, "_cf7_btcpay_api_key", $btcpay_api_key );
        update_post_meta( $post_id, "_cf7_btcpay_store_id", $store_id );
        update_post_meta( $post_id, "_cf7_coinsnap_provider", 'btcpay' );

        $notice->addNotice( 'success', __( 'Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-contact-form-7' ) );

        if ( $this->registerWebhook( $btcpay_server_url, $btcpay_api_key, $store_id ) ) {
            $notice->addNotice( 'success', __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-contact-form-7' ) );
        }
        else {
            $notice->addNotice( 'error', __( 'Could not register a new webhook on the store.', 'coinsnap-for-contact-form-7' ) );
        }
        wp_safe_redirect( $CoinsnapBTCPaySettingsUrl );
        exit();
    }

    /** Capability to change this form's payment settings; checked with the nonce before any side effect (6.5). */
    private function canEditForm( $post_id ): bool {
        $post_id = (int) $post_id;
        return $post_id > 0 && get_post_type( $post_id ) === 'wpcf7_contact_form' && current_user_can( 'wpcf7_edit_contact_form', $post_id );
    }
    
    public function coinsnapConnectionHandler(){
        $post_id = (int) filter_input(INPUT_POST,'cf7_post',FILTER_VALIDATE_INT);
        $_nonce = sanitize_text_field( (string) filter_input(INPUT_POST,'_wpnonce',FILTER_UNSAFE_RAW) );

        // Nonce and capability before any API call or webhook change (6.5).
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) || !$this->canEditForm( $post_id ) ) {
            wp_send_json( ['result' => false, 'message' => __( 'Unauthorized', 'coinsnap-for-contact-form-7' )], 403 );
        }
        $this->_postid = $post_id;
        
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
        
        {
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
                $this_store = $store->getStore($this->getStoreId());
                
                if ($this_store->getData()['code'] !== 200) {
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
        
        // The scripts and their nonce only on a form's editor, for users who may edit that form.
        $post_id = (int) filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT);
        if('wpcf7' !== filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS) || !$this->canEditForm( $post_id )){
            return;
        }
        wp_enqueue_script('coinsnap-admin-fields',plugins_url('assets/js/adminFields.js', __FILE__ ),[ 'jquery' ],COINSNAPCF7_VERSION,true);
        wp_enqueue_script('coinsnap-connection-check',plugin_dir_url( __FILE__ ) . 'assets/js/connectionCheck.js',[ 'jquery' ],COINSNAPCF7_VERSION,true);
        wp_localize_script('coinsnap-connection-check', 'coinsnap_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnap-ajax-nonce' ),
            'cf7_post' => $post_id
        ));
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $post_id = (int) filter_input(INPUT_POST,'cf7_post',FILTER_VALIDATE_INT);
        $_nonce = sanitize_text_field( (string) filter_input(INPUT_POST,'apiNonce',FILTER_UNSAFE_RAW) );
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) || !$this->canEditForm( $post_id ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }

        $host = CoinsnapCf7_Security::clean_server_url( wp_unslash( (string) filter_input(INPUT_POST,'host',FILTER_UNSAFE_RAW) ) );
        if ($host === '') {
            wp_send_json_error("Error validating BTCPayServer URL.");
        }

        try {
            // The URL is kept with the one-time state and saved only together with a key BTCPay confirms (6.14).
            $state = CoinsnapCf7_Security::btcpay_start_state( $post_id, $host );
            $url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                $host,
                CoinsnapCf7_Security::BTCPAY_PERMISSIONS,
                'ContactForm7',
                true,
                true,
                add_query_arg( ['coinsnap-for-cf7-btcpay-settings-callback' => '1', 'state' => $state], home_url( '/' ) ),
                'coinsnap-for-contact-form-7'
            );
            wp_send_json_success(['url' => $url]);
        }
        catch (\Throwable $e) {
            CoinsnapCf7_Security::log('info', 'btcpay authorize url failed');
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
            
            $submission = WPCF7_Submission::get_instance();

            if (!$submission) {
                return;
            }
            
            // redirect to payment
            $this->coinsnap_redirect_payment($cf7);
            exit();
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

        $contact_form = WPCF7_ContactForm::get_current();
        $post_id = $contact_form ? (int) $contact_form->id() : 0;
        if ( $post_id < 1 || !get_post_meta( $post_id, "_cf7_coinsnap_enable", true ) ) {
            return $result; // Coinsnap isn't enabled on this form
        }
        $this->_postid = $post_id;
        $submission = WPCF7_Submission::get_instance();
        $posted = $submission ? (array) $submission->get_posted_data() : [];
        $amount_tag = null;
        $tag_submit = null;

        foreach($tags as $tag){
            if('cs_amount' === $tag->name){
                $amount_tag = $tag;
            }
            if('submit' === $tag->type){
                $tag_submit = $tag;
            }
        }

        if($amount_tag === null){
            if($tag_submit !== null){
                $result->invalidate( $tag_submit, esc_html__("Form doesn't contain cs_amount field",'coinsnap-for-contact-form-7') );
            }
            return $result;
        }

        // The amount allowed by the form's own cs_amount tag, not just what the browser sent.
        $amount = CoinsnapCf7_Security::amount_from_form( $contact_form, $posted );
        if($amount === null){
            $result->invalidate( $amount_tag, esc_html__( 'Please enter a valid amount.', 'coinsnap-for-contact-form-7' ) );
            return $result;
        }

        $currency = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
        $checkInvoice = $this->coinsnapcf7_amount_validation( (float) $amount, $currency );

        if($checkInvoice['result'] !== true){
            if($checkInvoice['error'] === 'currencyError'){
                $errorMessage = sprintf(
                /* translators: 1: Currency */
                __( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-contact-form-7' ), strtoupper( $currency ));
            }
            elseif($checkInvoice['error'] === 'amountError'){
                $errorMessage = sprintf(
                /* translators: 1: Amount, 2: Currency */
                __( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-contact-form-7' ), $checkInvoice['min_value'] ?? '', strtoupper( $currency ));
            }
            else {
                $errorMessage = $checkInvoice['error'];
            }
            $result->invalidate( $amount_tag, $errorMessage);
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

        // CF7 checked its own nonce and the capability for this form before saving it. Copies and
        // programmatic saves carry none of our fields: leave the settings alone then.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( !isset( $_POST['coinsnap_provider'] ) || !is_object( $cf7 ) || (int) $cf7->id() < 1 ) {
            return;
        }
        $notice = new \Coinsnap\Util\Notice();
        $post_id = (int) $cf7->id();
        $this->_postid = $post_id;
        $field = static function ( $name ) {
            return trim( sanitize_text_field( (string) filter_input( INPUT_POST, $name, FILTER_UNSAFE_RAW ) ) );
        };

        update_post_meta( $post_id, "_cf7_coinsnap_enable", filter_input(INPUT_POST,'coinsnap_enable',FILTER_VALIDATE_INT) ? 1 : 0 );
        update_post_meta( $post_id, "_cf7_coinsnap_autoredirect", filter_input(INPUT_POST,'coinsnap_autoredirect',FILTER_VALIDATE_INT) ? 1 : 0 );
        update_post_meta( $post_id, "_cf7_coinsnap_paymentfirst", filter_input(INPUT_POST,'coinsnap_paymentfirst',FILTER_VALIDATE_INT) ? 1 : 0 );

        $currency = strtoupper( $field('coinsnap_currency') );
        if ( in_array( $currency, COINSNAP_CURRENCIES, true ) ) {
            update_post_meta( $post_id, "_cf7_coinsnap_currency", $currency );
        }
        update_post_meta( $post_id, "_cf7_coinsnap_provider", ( $field('coinsnap_provider') === 'btcpay' ) ? 'btcpay' : 'coinsnap' );
        update_post_meta( $post_id, "_cf7_coinsnap_s_url", esc_url_raw( $field('coinsnap_s_url') ) );

        // Coinsnap: the key field is always shown empty; an empty field keeps the stored key.
        update_post_meta( $post_id, "_cf7_coinsnap_store_id", $field('coinsnap_store_id') );
        if ( $field('coinsnap_api_key') !== '' ) {
            update_post_meta( $post_id, "_cf7_coinsnap_api_key", $field('coinsnap_api_key') );
        }

        // BTCPay: a key is saved only if BTCPay confirms it is limited to the configured store,
        // and a new server URL drops the stored key unless a working key comes with it (6.14).
        $old_url   = (string) get_post_meta( $post_id, "_cf7_btcpay_server_url", true );
        $old_store = (string) get_post_meta( $post_id, "_cf7_btcpay_store_id", true );
        $old_key   = (string) get_post_meta( $post_id, "_cf7_btcpay_api_key", true );
        $new_url   = CoinsnapCf7_Security::clean_server_url( $field('btcpay_server_url') );
        $new_store = $field('btcpay_store_id');
        $new_key   = $field('btcpay_api_key');

        if ( $new_key !== '' ) {
            $verified_store = CoinsnapCf7_Security::btcpay_verify_key( $new_url, $new_key, $new_store );
            if ( $verified_store === null ) {
                $notice->addNotice( 'error', __( 'Contact Form 7: the BTCPay API key was not saved. It must work on this BTCPay server and be limited to the configured store, with only the permissions this plugin needs.', 'coinsnap-for-contact-form-7' ) );
            }
            else {
                update_post_meta( $post_id, "_cf7_btcpay_server_url", $new_url );
                update_post_meta( $post_id, "_cf7_btcpay_store_id", $verified_store );
                update_post_meta( $post_id, "_cf7_btcpay_api_key", $new_key );
            }
        }
        elseif ( $new_url !== rtrim( $old_url, '/' ) ) {
            update_post_meta( $post_id, "_cf7_btcpay_server_url", $new_url );
            update_post_meta( $post_id, "_cf7_btcpay_store_id", $new_store );
            delete_post_meta( $post_id, "_cf7_btcpay_api_key" );
        }
        elseif ( $new_store !== $old_store ) {
            if ( $old_key !== '' && CoinsnapCf7_Security::btcpay_verify_key( $old_url, $old_key, $new_store ) === null ) {
                $notice->addNotice( 'error', __( 'Contact Form 7: the BTCPay store ID was not changed, because the saved API key is not limited to that store. Please enter a key for the new store.', 'coinsnap-for-contact-form-7' ) );
            }
            else {
                update_post_meta( $post_id, "_cf7_btcpay_store_id", $new_store );
            }
        }

        if ( empty( $this->getApiKey() ) || empty( $this->getStoreId() ) || empty( $this->getApiUrl() ) ) {
            return;
        }
        $client = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
        try {
            $store = $client->getStore($this->getStoreId());
            
            if ($store->getData()['code'] === 200) {                    
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

        $post_id = is_object( $cf7 ) ? (int) $cf7->id() : (int) filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT);

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
                          <div class="coinsnapcf7-field"><input id="coinsnap_api_key" class="long-input" type="password" value="" autocomplete="new-password" placeholder="' . esc_attr( $coinsnap_api_key !== '' ? CoinsnapCf7_Security::secret_hint( (string) $coinsnap_api_key ) : __('Paste your API key','coinsnap-for-contact-form-7') ) . '" name="coinsnap_api_key">
                          <div class="description">'. esc_html__('Please input the API Key that you will find in your Coinsnap account.','coinsnap-for-contact-form-7').' '. esc_html__('Leave empty to keep the saved key.','coinsnap-for-contact-form-7').'</div>
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
                          <div class="coinsnapcf7-field"><input id="btcpay_api_key" class="long-input" type="password" value="" autocomplete="new-password" placeholder="' . esc_attr( $btcpay_api_key !== '' ? CoinsnapCf7_Security::secret_hint( (string) $btcpay_api_key ) : __('Paste your API key','coinsnap-for-contact-form-7') ) . '" name="btcpay_api_key">
                          <div class="description">'.esc_html__('Please input the API Key that you will find in your Coinsnap account.','coinsnap-for-contact-form-7').' '. esc_html__('Leave empty to keep the saved key. Changing the BTCPay server URL removes the saved key.','coinsnap-for-contact-form-7').'</div>
                          </div>
                        </div>';
                
                
		echo '<div class="coinsnapcf7-row">
                          <label for="coinsnap_s_url">'. esc_html__('Success URL','coinsnap-for-contact-form-7').'</label>
                          <div class="coinsnapcf7-field"><input id="coinsnap_s_url" class="long-input" type="text" value="' . esc_html($coinsnap_s_url) . '" name="coinsnap_s_url">
                          <div class="description">'. esc_html__('Please enter here the URL of the page on your website that the buyer will be redirected to after he finalizes the transaction. (Note: You must create this page, i.e. a “thank you”-page, yourself on your website!)','coinsnap-for-contact-form-7').'</div> 
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row">
                        <div class="coinsnapcf7-field inline-form">
                            <input type="checkbox" value="1" name="coinsnap_paymentfirst" id="coinsnap_paymentfirst" '.esc_html($coinsnap_paymentfirst_checked).'><label for="coinsnap_paymentfirst">'. esc_html__('Payment before e-Mail submission','coinsnap-for-contact-form-7').'</label>
                        </div>
                    </div>';

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
                
                if($paymentFirst){
                    
                    $default_data_array = [
                        '_site_title' => sanitize_text_field(get_bloginfo('name')),
                        '_site_url'   => wp_unslash(home_url()),
                        '_site_admin_email' => get_option('admin_email'),
                        '_date'       => date_i18n(get_option('date_format')),
                        '_time'       => date_i18n(get_option('time_format')),
                        '_remote_ip' => (isset($_SERVER['REMOTE_ADDR']))? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                        '_user_agent' => (isset($_SERVER['HTTP_USER_AGENT']))? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                        '_url' => wp_unslash($submission->get_meta('url')),
                        '_referer' => (isset($_SERVER['HTTP_REFERER']))? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : ''
                    ];
                    
                    foreach($default_data_array as $post_key => $post_value){
                        if(!isset($submission_data[$post_key])){
                            $submission_data[sanitize_text_field($post_key)] = sanitize_text_field($post_value);
                        }
                    }
                }
                
                $uploaded_files = $submission->uploaded_files();
		// The amount allowed by the form's cs_amount tag (fixed value, configured options or min/max), never just the browser's.
		$payment_amount  = CoinsnapCf7_Security::amount_from_form( $wpcf7, (array) $submission->get_posted_data() );
		if ( $payment_amount === null ) {
                    return false;
		}
		$submission_data[ $amount_field ] = $payment_amount; // the mail shows what is charged
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
                        'files'        => wp_json_encode( $uploaded_files, true ),
			'submit_time'  => time(),
			'name'         => $buyerName,
			'email'        => $buyerEmail,
			'amount'       => $payment_amount,
                        'status'       => 'Pending'
		], ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s']   );
                
		if ( $wpdb->last_error != '' ) {
			CoinsnapCf7_Security::log( 'info', 'entry insert failed' );
			return false;
		}
                
                $invoice_no = $wpdb->insert_id;
                
                if(is_array($uploaded_files) && count($uploaded_files) > 0 && $paymentFirst){
                    $upload_dir = wp_upload_dir();
                    $dir = $upload_dir['basedir'] . '/cf7-payments/' . $invoice_no;
                    wp_mkdir_p($dir);
                    $stored_files = [];
                    foreach ($uploaded_files as $field => $path) {
                        
                        if (!isset($path[0]) || !file_exists($path[0])){ continue; }
                        $new_path = $dir . '/' . basename($path[0]);
                        copy($path[0], $new_path);
                        $stored_files[$field] = $new_path;
                    }
                    
                    if(count($stored_files) > 0){
                        
                        $wpdb->update( $table_name, array('files' => wp_json_encode( $stored_files, true )),array( 'id' => $invoice_no ), array( '%s', '%s' ), array( '%d' ));
                        
                    }


                }
                
                
                $amount = (float) $payment_amount;
                $client  = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );
                
                $checkInvoice = $this->coinsnapcf7_amount_validation( $amount, $currency );
                
                if($checkInvoice['result'] === true){

                    $return_url = get_post_meta( $post_id, "_cf7_coinsnap_s_url", true );
                    
		
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

                        $invoiceData = $csinvoice->getData();
                        // Keep what this invoice asks for: the webhook pays the entry only with this record (3, 6.7).
                        if ( empty( $invoiceData['id'] ) || !CoinsnapCf7_Security::record_invoice( (string) $invoiceData['id'], (int) $invoice_no, (int) $post_id, $this->get_payment_provider(), $camount->__toString(), strtoupper( $currency ) ) ) {
                            CoinsnapCf7_Security::log( 'info', 'invoice record failed' );
                            return false;
                        }
                        $payurl = $invoiceData['checkoutLink'];
                        wp_redirect( $payurl ); // phpcs:ignore WordPress.Security.SafeRedirect -- the provider's checkout page
                        exit();
                    }
                    catch (\Throwable $e){
                        CoinsnapCf7_Security::log( 'info', 'invoice create failed' );
                        return false;
                    }
                }
                
                else {
                    return false;
                }
		
		exit();

	}

    public function get_tablename() {
        global $wpdb;
        return $wpdb->prefix . "coinsnapcf7_extension";
    }


    public function process_webhook() {

        //  cf7-listener get parameter check
        if ( filter_input(INPUT_GET,'cf7-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap' ) { return; }

        $form_id = (int) filter_input(INPUT_GET,'form_id',FILTER_VALIDATE_INT);
        if ( $form_id < 1 || get_post_type( $form_id ) !== 'wpcf7_contact_form' ) {
            status_header( 404 );
            exit;
        }
        $this->_postid = $form_id;
        status_header( $this->handle_webhook( $form_id, (string) file_get_contents( 'php://input' ) ) );
        exit;
    }

    /** Returns the HTTP status to send. Follows the guide's handler: signature, invoiceId only, API, ownership and amount, act once. */
    public function handle_webhook( int $form_id, string $rawBody ): int {
        $this->_postid = $form_id;
        $provider = $this->get_payment_provider();
        $isBtcpay = ( $provider === 'btcpay' );

        // 1. Signature over the raw body, with the secret of this form's webhook for its current store.
        $header = $isBtcpay
            ? ( isset( $_SERVER['HTTP_BTCPAY_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_BTCPAY_SIG'] ) ) : '' )
            : ( isset( $_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ) ) : '' );
        $secret = CoinsnapCf7_Security::webhook_secret( $form_id, $provider, (string) $this->getStoreId() );
        $problem = CoinsnapCf7_Security::signature_problem( $provider, $header, $rawBody, $secret, time() );
        if ( $problem !== null ) {
            CoinsnapCf7_Security::log( 'security', $problem );
            return 401;
        }

        // 2. Only the invoice ID is taken from the body.
        $data = json_decode( $rawBody, true );
        $invoice_id = ( is_array( $data ) && is_string( $data['invoiceId'] ?? null ) ) ? $data['invoiceId'] : '';
        if ( $invoice_id === '' || !preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $invoice_id ) ) {
            CoinsnapCf7_Security::log( 'info', 'no invoice id' );
            return 200;
        }
        $claimsPaid = in_array( $data['type'] ?? '', ['Settled', 'InvoiceSettled'], true );

        // 3. Fetch the invoice from the API.
        try {
            $invoice = CoinsnapCf7_Security::fetch_invoice( (string) $this->getApiUrl(), (string) $this->getApiKey(), (string) $this->getStoreId(), $invoice_id );
        }
        catch ( \RuntimeException $e ) {
            CoinsnapCf7_Security::log( 'info', 'retry: API unavailable', $invoice_id );
            return 503;
        }
        if ( $invoice === null ) {
            CoinsnapCf7_Security::log( 'info', 'unknown invoice', $invoice_id );
            return 200;
        }

        // 4. The invoice must be one this plugin created for an entry of this form.
        $apiOrderId = $isBtcpay ? ( $invoice['metadata']['orderId'] ?? '' ) : ( $invoice['orderId'] ?? ( $invoice['metadata']['orderNumber'] ?? '' ) );
        $record = $this->find_invoice_record( $invoice_id, $form_id, $provider, (string) $apiOrderId, $invoice );
        if ( $record === null ) {
            CoinsnapCf7_Security::log( 'info', 'not ours', $invoice_id );
            return 200;
        }
        $entry_id = (int) $record['entry_id'];

        $decision = CoinsnapCf7_Security::decide( $invoice, $isBtcpay, (string) $record['amount'], (string) $record['currency'] );
        if ( $decision === CoinsnapCf7_Security::MISMATCH || ( $decision !== CoinsnapCf7_Security::PAID && $claimsPaid ) ) {
            $forged = $claimsPaid && ( $invoice['additionalStatus'] ?? '' ) !== 'Marked';
            CoinsnapCf7_Security::log( $forged ? 'security' : 'info', $forged ? 'forged status' : 'status ' . preg_replace( '/[^A-Za-z]/', '', (string) ( $invoice['status'] ?? '?' ) ), $invoice_id );
            return 200;
        }

        $status = (string) $invoice['status'];
        $payment_res = $invoice;
        unset( $payment_res['qrCodes'], $payment_res['lightningInvoice'] );

        // 5. Act once, and never move an entry backwards.
        switch ( $decision ) {
            case CoinsnapCf7_Security::PAID:
                if ( !CoinsnapCf7_Security::mark_paid_once( $invoice_id ) ) {
                    CoinsnapCf7_Security::log( 'info', 'already processed', $invoice_id );
                    return 200;
                }
                // A late real payment still completes an expired entry.
                $changed = $this->update_entry_status( $entry_id, 'Settled', ['Pending', 'New', 'Processing', 'Expired', 'Invalid'], $payment_res );
                if ( $changed && get_post_meta( $form_id, "_cf7_coinsnap_paymentfirst", true ) ) {
                    $this->send_mail_after_payment( $form_id, $entry_id );
                }
                CoinsnapCf7_Security::log( 'info', $changed ? 'paid' : 'already processed', $invoice_id );
                return 200;
            case CoinsnapCf7_Security::PROCESSING:
                $this->update_entry_status( $entry_id, 'Processing', ['Pending', 'New'], $payment_res );
                break;
            case CoinsnapCf7_Security::FAILED:
                // Expired and Invalid never undo a paid entry.
                $this->update_entry_status( $entry_id, $status, ['Pending', 'New', 'Processing'], $payment_res );
                break;
            default:
                if ( $status === 'New' ) {
                    $this->update_entry_status( $entry_id, 'New', ['Pending'], $payment_res );
                }
        }
        CoinsnapCf7_Security::log( 'info', 'status ' . preg_replace( '/[^A-Za-z]/', '', $status ), $invoice_id );
        return 200;
    }

    /**
     * The invoice record for this form, or null. Entries created before 1.5.4 have no record:
     * during one day after the upgrade they are matched by the API's orderId, form and amount (6.7).
     */
    private function find_invoice_record( string $invoice_id, int $form_id, string $provider, string $apiOrderId, array $invoice ): ?array {
        global $wpdb;
        $record = CoinsnapCf7_Security::get_record( $invoice_id );
        if ( $record !== null ) {
            if ( (int) $record['form_id'] !== $form_id || $record['provider'] !== $provider || ( $apiOrderId !== '' && (string) $apiOrderId !== (string) $record['entry_id'] ) ) {
                return null;
            }
            return $record;
        }

        $entry_id = (int) $apiOrderId;
        if ( !CoinsnapCf7_Security::in_legacy_window() || $entry_id < 1 || (string) $entry_id !== (string) $apiOrderId || CoinsnapCf7_Security::entry_has_record( $entry_id ) ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, amount, submit_time FROM %i WHERE id = %d", $this->get_tablename(), $entry_id ), ARRAY_A );
        if ( !is_array( $entry ) || (int) $entry['form_id'] !== $form_id || (int) $entry['submit_time'] > CoinsnapCf7_Security::upgraded_at() ) {
            return null;
        }
        $currency = strtoupper( (string) get_post_meta( $form_id, "_cf7_coinsnap_currency", true ) );
        if ( strtoupper( (string) ( $invoice['currency'] ?? '' ) ) !== $currency ) {
            return null; // converted invoices (BTCPay → BTC) can't be checked without a record
        }
        if ( !CoinsnapCf7_Security::record_invoice( $invoice_id, $entry_id, $form_id, $provider, (string) $entry['amount'], $currency ) ) {
            return null;
        }
        return CoinsnapCf7_Security::get_record( $invoice_id );
    }

    /** Sets the status only if the entry is in one of $from; true if it changed. */
    private function update_entry_status( int $entry_id, string $status, array $from, array $payment_details ): bool {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
        $r = $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET status = %s, payment_details = %s WHERE id = %d AND status IN ($placeholders)",
            array_merge( [ $this->get_tablename(), $status, (string) wp_json_encode( $payment_details ), $entry_id ], $from )
        ) );
        return $r === 1;
    }

    private function send_mail_after_payment( int $form_id, int $entry_id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $order_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%d", $this->get_tablename(), $entry_id ), ARRAY_A );
        if ( !is_array( $order_data ) ) {
            return;
        }
        $mail_send_result = $this->cf7_mail_send( $form_id, $order_data );
        $wpdb->update( $this->get_tablename(), [
            'message' => $mail_send_result['message'],
            'form_sent' => ( $mail_send_result['result'] ) ? 1 : 0
        ], array( 'id' => $entry_id ), array( '%s', '%d' ), array( '%d' ) );
    }

    /*
    public function cf7_emulate_send($form_id,$order_data){

        $contact_form = WPCF7_ContactForm::get_instance($form_id);

        // Sent values
        $body = json_decode($order_data['field_values'],true);
        
        $_POST = $body;
        $_POST['_wpcf7'] = $form_id;
        $_POST['_wpcf7_unit_tag'] = 'wpcf7-f'.$form_id.'-p0-o1';
        $_POST['_wpcf7_container_post'] = 0;
        
        $files_array = json_decode($order_data['files'],true);

        // attachments
        add_filter('wpcf7_mail_components', function($components) use ($files_array){

            $valid = [];
            //  Sent files

            foreach ($files_array as $file) {
                if (file_exists($file)) {
                    $valid[] = $file;
                }
            }

            $components['attachments'] = implode("\n", $valid);

            return $components;
        });

        // отключаем spam
        add_filter('wpcf7_spam', '__return_false');

        //  Submission message
        $message = 'Submission after payment '. gmdate('Y-m-d H:i:s',time()).
                ' --------------------------------------- '. wp_json_encode($contact_form,true).
                ' --------------------------------------- '. wp_json_encode($_POST,true);
        
        
        // отправка как CF7
        $result = $contact_form->submit(true);

        remove_all_filters('wpcf7_mail_components');
        remove_all_filters('wpcf7_spam');
        
        $result_array = array('result' => $result,'message' => $message);

        return $result_array;
    }*/
    
    public function cf7_mail_send($form_id,$order_data){
        
        $contact_form = WPCF7_ContactForm::get_instance($form_id);
        $mail = $contact_form->prop('mail');
        
        // Sent values
        $body = json_decode($order_data['field_values'],true);
        
        //  Template with sent values replace. The values were typed by the visitor: no line breaks in
        //  header fields (no injected Bcc), and escaped in HTML mails.
        foreach((array) $body as $bkey => $bvalue){
            $bvalue = is_array($bvalue) ? implode(', ', array_map('strval', $bvalue)) : (string) $bvalue;
            $header_value = trim(str_replace(["\r", "\n"], ' ', $bvalue));
            $mail['body'] = str_replace("[".$bkey."]", !empty($mail['use_html']) ? esc_html($bvalue) : $bvalue, $mail['body']);
            $mail['subject'] = str_replace("[".$bkey."]", $header_value, $mail['subject']);
            $mail['sender'] = str_replace("[".$bkey."]", $header_value, $mail['sender']);
            $mail['recipient'] = str_replace("[".$bkey."]", $header_value, $mail['recipient']);
            $mail['additional_headers'] = str_replace("[".$bkey."]", $header_value, $mail['additional_headers']);
        }

        //  Sent files
        $files_array = json_decode((string) $order_data['files'],true);

        if(is_array($files_array) && count($files_array) > 0){
            $attachments = array();
            foreach($files_array as $_file){ $attachments[] = $_file; }
            $mail['attachments'] = implode("\n", $attachments);
        }
        
        //  Submission message
        $message = 'Submission after payment '. gmdate('Y-m-d H:i:s',time()).' --------------------------------------- '. wp_json_encode($mail,true);
                    
        // Run submission
        $result = WPCF7_Mail::send($mail,'mail');
        $result_array = array('result' => $result,'message' => $message);
        
        return $result_array;
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

    /**
     * True if this form's webhook is registered on the store; false only when the API confirms it is
     * missing. API errors are thrown: an error is never "no webhook", so it never replaces the secret.
     */
    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {

        $form_id = (int) $this->_postid;
        if ($form_id < 1) {
            return false;
        }
        $whClient = new Webhook( $apiUrl, $apiKey );
        $ourUrl = $this->get_webhook_url();
        $storedWebhook = CoinsnapCf7_Security::get_webhook( $form_id );

        if (CoinsnapCf7_Security::webhook_secret( $form_id, $this->get_payment_provider(), $storeId ) !== '' && ($storedWebhook['id'] ?? '') !== '') {
            try {
                $existing = $whClient->getWebhook( $storeId, $storedWebhook['id'] )->getData();
                if ((string) ($existing['id'] ?? '') === $storedWebhook['id'] && rtrim((string) ($existing['url'] ?? ''), '/') === rtrim($ourUrl, '/')) {
                    return true;
                }
            }
            catch (\Coinsnap\Exception\RequestException $e) {
                if (!in_array((int) $e->getCode(), [400, 404], true)) {
                    throw $e;
                }
            }
        }

        // Missing: remove stale webhooks with exactly this form's URL (their secret is unknown), then report missing.
        foreach ($whClient->getWebhooks( $storeId ) as $webhook) {
            $data = $webhook->getData();
            if (rtrim((string) ($data['url'] ?? ''), '/') === rtrim($ourUrl, '/')) {
                $whClient->deleteWebhook( $storeId, (string) $data['id'] );
            }
        }
        return false;
    }

    public function registerWebhook(string $apiUrl, $apiKey, $storeId): bool {

        $form_id = (int) $this->_postid;
        if ($form_id < 1) {
            return false;
        }
        try {
            $whClient = new Webhook( $apiUrl, (string) $apiKey );
            $webhook_events = ($this->get_payment_provider() === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $data = $whClient->createWebhook( (string) $storeId, $this->get_webhook_url(), $webhook_events, null )->getData();
            if (empty($data['id']) || empty($data['secret'])) {
                CoinsnapCf7_Security::log( 'info', 'webhook register failed' );
                return false;
            }
            CoinsnapCf7_Security::save_webhook( $form_id, (string) $data['id'], (string) $data['secret'], (string) ($data['url'] ?? $this->get_webhook_url()), $this->get_payment_provider(), (string) $storeId );
            return true;
        }
        catch (\Throwable $e) {
            CoinsnapCf7_Security::log( 'info', 'webhook register failed' );
        }
        return false;
    }
}
