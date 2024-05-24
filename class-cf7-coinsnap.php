<?php
class Cf7Coinsnap
{
    private static $_instance = null;
    protected $_full_path = __FILE__;
    private $_postid;
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];


    public function __construct()
    {

        add_filter('wpcf7_editor_panels',array($this,'cf7_coinsnap_editor_panels'));
        add_action('wpcf7_admin_after_additional_settings', array($this, 'cf7_coinsnap_admin_after_additional_settings'));
        add_action('wpcf7_save_contact_form', array($this, 'cf7_save_settings'));
        add_action('wpcf7_mail_sent', array($this, 'redirect_payment'));
        add_action('init', array($this, 'process_webhook'));
        add_action('admin_menu', array($this, 'cf7_coinsnap_admin_menu'), 20 );
        //add_filter('wpcf7_skip_mail', '__return_true');

    }

    public static function get_instance()
    {
        if (self::$_instance == null) {
            self::$_instance = new Cf7Coinsnap();
        }

        return self::$_instance;
    }


    function cf7_coinsnap_admin_menu() {
        add_submenu_page('wpcf7', __('Coinsnap Payments', 'contact-form-7'), __('Coinsnap Payments', 'contact-form-7'), 'wpcf7_edit_contact_forms', 'cf7_coinsnap_admin_list_trans', array($this,'cf7_coinsnap_admin_list_trans'));
    }

    function cf7_coinsnap_admin_list_trans()
	{
		if (!current_user_can("manage_options")) {
			wp_die(__("You do not have sufficient permissions to access this page."));
		}
		global $wpdb;
        global $wpdb;
		$pagenum = isset($_GET['pagenum']) ? absint($_GET['pagenum']) : 1;
		$limit = 10;
		$offset = ($pagenum - 1) * $limit;
		$table_name = $this->get_tablename();
		$transactions = $wpdb->get_results("SELECT * FROM $table_name  ORDER BY $table_name.id DESC LIMIT $offset, $limit", ARRAY_A);
		$total = $wpdb->get_var("SELECT COUNT($table_name.id) FROM $table_name  ");
		$num_of_pages = ceil($total / $limit);
		$cntx = 0;
		echo '<div class="wrap">
		<h2>Coinsnap Payments</h2>
		<table class="widefat post fixed" cellspacing="0">
			<thead>
				<tr>
                <th scope="col" id="name" width="15%" class="manage-column" style="">Contact Form</th>
                <th scope="col" id="name" width="" class="manage-column" style="">Order ID</th>
                <th scope="col" id="name" width="" class="manage-column" style="">Date</th>
                <th scope="col" id="name" width="15%" class="manage-column" style="">Name</th>
                <th scope="col" id="name" width="15%" class="manage-column" style="">E-Mail</th>                    
                <th scope="col" id="name" width="15%" class="manage-column" style="">Amount</th>
                <th scope="col" id="name" width="13%" class="manage-column" style="">Status</th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th scope="col" id="name" width="15%" class="manage-column" style="">Contact Form</th>
					<th scope="col" id="name" width="" class="manage-column" style="">Order ID</th>
					<th scope="col" id="name" width="" class="manage-column" style="">Date</th>
					<th scope="col" id="name" width="15%" class="manage-column" style="">Name</th>
                    <th scope="col" id="name" width="15%" class="manage-column" style="">E-Mail</th>                    
					<th scope="col" id="name" width="15%" class="manage-column" style="">Amount</th>
					<th scope="col" id="name" width="13%" class="manage-column" style="">Status</th>
				</tr>
			</tfoot>
			<tbody>';
		if (count($transactions) == 0) {
			echo '<tr class="alternate author-self status-publish iedit" valign="top">
					<td class="" colspan="7">No Data</td>
				</tr>';
		} else {
			foreach ($transactions as $transaction) {
				echo '<tr class="alternate author-self status-publish iedit" valign="top">
					<td class="">' . get_the_title($transaction['form_id']) . '</td>';
                echo '<td class="">' . $transaction['id'] . '</td>';
				echo '<td class="">' . strftime("%B %e, %Y %r", $transaction['submit_time']).'</td>';
				echo '<td class="">' . $transaction['name'] . '</td>';
				echo '<td class="">' . $transaction['email'] . '</td>';
				echo '<td class="">' . $transaction['amount'] . '</td>';
                echo '<td class="">' . $transaction['status'] . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table><br>';
		$page_links = paginate_links(array(
			'base' => add_query_arg('pagenum', '%#%'),
			'format' => '',
			'prev_text' => __('&laquo;', 'aag'),
			'next_text' => __('&raquo;', 'aag'),
			'total' => $num_of_pages,
			'current' => $pagenum
		));
		if ($page_links) {
			echo '<center><div class="tablenav"><div class="tablenav-pages"  style="float:none; margin: 1em 0">' . $page_links . '</div></div></center>';
		}
		echo '<br><hr></div>';
    }

    function cf7_save_settings($cf7) {


        $post_id = sanitize_text_field($_POST['post']);
        if (!empty($_POST['coinsnap_enable'])) {
            $coinsnap_enable = sanitize_text_field($_POST['coinsnap_enable']);
            update_post_meta($post_id, "_cf7_coinsnap_enable", $coinsnap_enable);
        } else {
            update_post_meta($post_id, "_cf7_coinsnap_enable", 0);
        }


        update_post_meta($post_id, "_cf7_coinsnap_amounts", sanitize_text_field($_POST['coinsnap_amounts']));
        update_post_meta($post_id, "_cf7_coinsnap_name", sanitize_text_field($_POST['coinsnap_name']));
        update_post_meta($post_id, "_cf7_coinsnap_email", sanitize_text_field($_POST['coinsnap_email']));

        update_post_meta($post_id, "_cf7_coinsnap_currency", sanitize_text_field($_POST['coinsnap_currency']));
        update_post_meta($post_id, "_cf7_coinsnap_store_id", sanitize_text_field($_POST['coinsnap_store_id']));
        update_post_meta($post_id, "_cf7_coinsnap_api_key", sanitize_text_field($_POST['coinsnap_api_key']));
        update_post_meta($post_id, "_cf7_coinsnap_s_url", sanitize_text_field($_POST['coinsnap_s_url']));

    }


    function cf7_coinsnap_editor_panels($panels)
	{
		$new_page = array(
			'coinsnap' => array(
				'title' => __('Coinsnap', 'coinsnap_payment_for_cf7'),
				'callback' => array($this, 'cf7_coinsnap_admin_after_additional_settings')
			)
		);
		$panels = array_merge($panels, $new_page);
		return $panels;
	}

    function cf7_coinsnap_admin_after_additional_settings($cf7)
	{

		$post_id = sanitize_text_field($_GET['post']);


        $enable = get_post_meta($post_id, "_cf7_coinsnap_enable", true);
        $amount_field = get_post_meta($post_id, "_cf7_coinsnap_amounts", true);
        $name_field = get_post_meta($post_id, "_cf7_coinsnap_name", true);
        $email_field = get_post_meta($post_id, "_cf7_coinsnap_email", true);

        $coinsnap_store_id = get_post_meta($post_id, "_cf7_coinsnap_store_id", true);
        $coinsnap_api_key = get_post_meta($post_id, "_cf7_coinsnap_api_key", true);
        $coinsnap_s_url = get_post_meta($post_id, "_cf7_coinsnap_s_url", true);

        $coinsnap_currency = get_post_meta($post_id, "_cf7_coinsnap_currency", true);
        if (empty($coinsnap_currency)) $coinsnap_currency = 'USD';


        $checked = ($enable == "1") ? "CHECKED" : '';



		$admin_settings = '<div class="cf7-coinsnap">';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <div class="cf7-coinsnap-field inline-form">
                           <input type="checkbox" value="1" name="coinsnap_enable" ' . $checked . '><label>Enable Coinsnap on this form</label>
                          </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <label>Amount Field (required)</label>
                            <div class="cf7-coinsnap-field"><input required type="text" value="' . $amount_field . '" name="coinsnap_amounts">
                            <div class="description">Please enter the name of the form element that specifies the amount (in your currency) to be paid exactly as you defined it in the “form” tab of your Contact Form 7 contact form editor.</div>
                          </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                        <label>Customer Name</label>
                          <div class="cf7-coinsnap-field"><input type="text" value="' . $name_field . '" name="coinsnap_name">
                          <div class="description">Please input a field name that will be used in the CF7 form to capture the customer name.</div>
                        </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                        <label>E-Mail Field</label>
                          <div class="cf7-coinsnap-field"><input type="text" value="' . $email_field . '" name="coinsnap_email">
                          <div class="description">Please input a field name that will be used in the CF7 form to capture the customer email.</div>
                        </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row"><hr></div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <label>Currency Code (required)</label>
                          <div class="cf7-coinsnap-field"><input type="text" value="' . $coinsnap_currency . '" name="coinsnap_currency">
                          </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <label>Store ID (required)</label>
                          <div class="cf7-coinsnap-field"><input required class="long-input" type="text" value="' . $coinsnap_store_id . '" name="coinsnap_store_id">
                          <div class="description">Please input your personal Store ID, which you will find in your Coinsnap account.</div>
                          </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <label>API Key (required)</label>
                          <div class="cf7-coinsnap-field"><input required class="long-input" type="text" value="' . $coinsnap_api_key . '" name="coinsnap_api_key">
                          <div class="description">Please input the API Key that you will find in your Coinsnap account.</div>
                          </div>
                        </div>';
		$admin_settings .= '<div class="cf7-coinsnap_row">
                          <label>Success URL</label>
                          <div class="cf7-coinsnap-field"><input class="long-input" type="text" value="' . $coinsnap_s_url . '" name="coinsnap_s_url">
                          <div class="description">Please enter here the URL of the page on your website that the buyer will be re-directed to after he finalizes the transaction. (Note: You must create this page, i.e. a “thank you”-page, yourself on your website!)</div> 
                          </div>
                        </div>';
		$admin_settings .= '<input type="hidden" name="post" value="' . $post_id . '"></div>';

		echo $admin_settings;

	}





    public function redirect_payment($cf7)
    {
        global $wpdb;
		global $postid;


        $post_id = $cf7->id();
        $this->_postid = $post_id;


        $enable = get_post_meta($post_id, "_cf7_coinsnap_enable", true);
        if (! $enable) {
            return false;
        }

        $wpcf7 = WPCF7_ContactForm::get_current();
        $submission = WPCF7_Submission::get_instance();
        $submission_data = $submission->get_posted_data();
        $amount_field = get_post_meta($post_id, "_cf7_coinsnap_amounts", true);
        $name_field = get_post_meta($post_id, "_cf7_coinsnap_name", true);
        $email_field = get_post_meta($post_id, "_cf7_coinsnap_email", true);


        $payment_amount = $submission_data[$amount_field];

        $currency  = get_post_meta($post_id, "_cf7_coinsnap_currency", true);

        $buyerEmail = $submission_data[$email_field];
        $buyerName = $submission_data[$name_field];



        $webhook_url = $this->get_webhook_url();


        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)){
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(),$webhook_url)) {
                echo (__('unable to set Webhook url.', 'cf7_coinsnap'));
                exit;
            }
         }
         $table_name = $this->get_tablename();

         $wpdb->insert($table_name, $trans = array(
			'form_id'      => $post_id,
            'field_values'  => json_encode($submission_data, true),
            'submit_time'  => time(),
            'name'  => $buyerName,
            'email'  => $buyerEmail,
            'amount'  => $payment_amount,
		), $schema = array('%d', '%s', '%s','%s','%s','%d'));
        if ($wpdb->last_error != ''){
            echo $wpdb->last_error;
            exit;
        }

        $return_url = get_post_meta($post_id, "_cf7_coinsnap_s_url", true);

        $invoice_no =  $wpdb->insert_id;
		$amount = round($payment_amount, 2);
        $metadata = [];
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;


        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $return_url );
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);

        $csinvoice = $client->createInvoice(
				    $this->getStoreId(),
			    	strtoupper( $currency ),
			    	$camount,
			    	$invoice_no,
			    	$buyerEmail,
			    	$buyerName,
			    	$return_url,
			    	'',
			    	$metadata,
			    	$checkoutOptions
		    	);


        $payurl = $csinvoice->getData()['checkoutLink'] ;
		wp_redirect( $payurl );
        exit();

    }

    public function get_tablename()
    {
        global $wpdb;
        return $wpdb->prefix . "cf7_coinsnap_extension";
    }


    public function process_webhook()
    {
        global $wpdb;

        if ( ! isset( $_GET['cf7-listener'] ) || $_GET['cf7-listener'] !== 'coinsnap' ) {
            return;
        }


        $this->_postid = $_GET['form_id'];

        $notify_json = file_get_contents('php://input');

        $notify_ar = json_decode($notify_json, true);
        $invoice_id = $notify_ar['invoiceId'];

        try {
			$client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );
			$csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
			$status = $csinvoice->getData()['status'] ;
			$order_id = $csinvoice->getData()['orderId'] ;

		}catch (\Throwable $e) {
				echo "Error";
				exit;
		}
        $payment_res = $csinvoice->getData();
        unset($payment_res['qrCodes']);
        unset($payment_res['lightningInvoice']);
        $table_name = $this->get_tablename();
        $wpdb->update( $table_name, array( 'payment_details' => json_encode($payment_res, true), 'status'=>$status),
				array( 'id' => $order_id ), array( '%s','%s' ), array( '%d' )
			);


        echo "OK";
        exit;
    }





    public function get_webhook_url() {
        return get_site_url() . '/?cf7-listener=coinsnap&form_id='.$this->_postid;
    }
	public function getStoreId() {

        return get_post_meta($this->_postid, "_cf7_coinsnap_store_id", true);
    }
    public function getApiKey() {
        return get_post_meta($this->_postid, "_cf7_coinsnap_api_key", true) ;
    }

    public function getApiUrl() {
        return 'https://app.coinsnap.io';
    }

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {
        try {
            $whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );
            $Webhooks = $whClient->getWebhooks( $storeId );



            foreach ($Webhooks as $Webhook){
                //self::deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
                if ($Webhook->getData()['url'] == $webhook) return true;
            }
        }catch (\Throwable $e) {
            return false;
        }

        return false;
    }
    public  function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {
        try {
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);

            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
                $webhook, //$url
                self::WEBHOOK_EVENTS,
                null    //$secret
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {

        try {
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);

            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url
            );
            return true;
        } catch (\Throwable $e) {

            return false;
        }
    }



}
