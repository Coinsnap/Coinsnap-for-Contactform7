<?php

class CoinsnapCf7 {
	private static $_instance = null;
	protected $_full_path = __FILE__;
	private $_postid;
	public const WEBHOOK_EVENTS = [ 'New', 'Expired', 'Settled', 'Processing' ];


	public function __construct() {

		add_filter( 'wpcf7_editor_panels', array( $this, 'coinsnapcf7_editor_panels' ) );
		add_action( 'wpcf7_admin_after_additional_settings', array($this,'coinsnapcf7_admin_after_additional_settings') );
		add_action( 'wpcf7_save_contact_form', array( $this, 'coinsnapcf7_save_settings' ) );
                add_action( 'wpcf7_admin_notices', array( $this, 'coinsnapcf7_webhook'));
		add_action( 'wpcf7_mail_sent', array( $this, 'redirect_payment' ) );
		add_action( 'init', array( $this, 'process_webhook' ) );
		add_action( 'admin_menu', array( $this, 'coinsnapcf7_admin_menu' ), 20 );
	}
        
        public function coinsnapcf7_webhook($cf7){
            $post_id = sanitize_text_field( filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT) );
            
            $webhook_status = get_post_meta($post_id , "_cf7_coinsnap_webhook", true );
            if(isset($webhook_status) && !empty($webhook_status)){
                if($webhook_status === 'exists'){
                    echo '<div class="notice notice-info"><p>';
                    esc_html_e('Contact Form 7: Webhook already exists, skipping webhook creation', 'coinsnap-for-contact-form-7');
                    echo '</p></div>';
                }
                elseif($webhook_status === 'failed'){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Contact Form 7: Unable to create webhook on Coinsnap Server', 'coinsnap-for-contact-form-7');
                    echo '</p></div>';
                }
                elseif($webhook_status === 'registered'){
                    echo '<div class="notice notice-success"><p>';
                    esc_html_e('Contact Form 7: Successfully registered webhook on Coinsnap Server', 'coinsnap-for-contact-form-7');
                    echo '</p></div>';
                }
                elseif($webhook_status === 'noconnection'){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Contact Form 7: Coinsnap connection error', 'coinsnap-for-contact-form-7');
                    echo '</p></div>';
                }
                update_post_meta( $post_id, "_cf7_coinsnap_webhook", '' );
            }
        }

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new CoinsnapCf7();
		}

		return self::$_instance;
	}


	function coinsnapcf7_admin_menu() {
		add_submenu_page( 'wpcf7', esc_html__( 'Coinsnap Payments', 'coinsnap-for-contact-form-7' ), esc_html__( 'Coinsnap Payments', 'coinsnap-for-contact-form-7' ), 'wpcf7_edit_contact_forms', 'coinsnapcf7_admin_list_trans', array(
			$this,
			'coinsnapcf7_admin_list_trans'
		) );
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
			echo esc_html('<center><div class="tablenav"><div class="tablenav-pages"  style="float:none; margin: 1em 0">' . $page_links . '</div></div></center>');
		}
		echo '<br><hr></div>';
	}

	function coinsnapcf7_save_settings( $cf7 ) {

            $post_id = sanitize_text_field( filter_input(INPUT_POST,'post',FILTER_VALIDATE_INT) );
            if ( ! empty( filter_input(INPUT_POST,'coinsnap_enable',FILTER_VALIDATE_INT) ) ) {
		$coinsnap_enable = sanitize_text_field( filter_input(INPUT_POST,'coinsnap_enable',FILTER_VALIDATE_INT) );
		update_post_meta( $post_id, "_cf7_coinsnap_enable", $coinsnap_enable );
            }
            else {
                update_post_meta( $post_id, "_cf7_coinsnap_enable", 0 );
            }

            update_post_meta( $post_id, "_cf7_coinsnap_currency", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_currency',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_coinsnap_store_id", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_store_id',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_coinsnap_api_key", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_api_key',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
            update_post_meta( $post_id, "_cf7_coinsnap_s_url", sanitize_text_field( filter_input(INPUT_POST,'coinsnap_s_url',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ) );
                
            $this->_postid = $post_id;
            
            $client = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
            $store = $client->getStore($this->getStoreId());
            if ($store['code'] === 200) {            
                $webhook_url = $this->get_webhook_url();
                if ( ! $this->webhookExists( $this->getStoreId(), $this->getApiKey(), $webhook_url ) ) {
                    if ( ! $this->registerWebhook( $this->getStoreId(), $this->getApiKey(), $webhook_url ) ) {
                        update_post_meta( $post_id, "_cf7_coinsnap_webhook", 'failed' );
                    }
                    else {
                        update_post_meta( $post_id, "_cf7_coinsnap_webhook", 'registered' );
                    }
                }
                else {
                    update_post_meta( $post_id, "_cf7_coinsnap_webhook", 'exists' );
                }
            }
            else {
                update_post_meta( $post_id, "_cf7_coinsnap_webhook", 'noconnection' );
            }
	}
        
        




	function coinsnapcf7_editor_panels( $panels ) {
		$new_page = array(
			'coinsnap' => array(
				'title'    => __( 'Coinsnap', 'coinsnap-for-contact-form-7' ),
				'callback' => array( $this, 'coinsnapcf7_admin_after_additional_settings' )
			)
		);
		$panels   = array_merge( $panels, $new_page );

		return $panels;
	}

	function coinsnapcf7_admin_after_additional_settings( $cf7 ) {

		$post_id = sanitize_text_field( filter_input(INPUT_GET,'post',FILTER_VALIDATE_INT) );


		$enable       = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
		$coinsnap_store_id = get_post_meta( $post_id, "_cf7_coinsnap_store_id", true );
		$coinsnap_api_key  = get_post_meta( $post_id, "_cf7_coinsnap_api_key", true );
		$coinsnap_s_url    = get_post_meta( $post_id, "_cf7_coinsnap_s_url", true );

		$coinsnap_currency = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
		if ( empty( $coinsnap_currency ) ) {
			$coinsnap_currency = 'EUR';
		}

		$checked = ( $enable == "1" ) ? "CHECKED" : '';

		echo '<div class="coinsnapcf7">';
		echo '<div class="coinsnapcf7-row">
                          <div class="coinsnapcf7-field inline-form">
                           <input type="checkbox" value="1" name="coinsnap_enable" ' . esc_html($checked) . '><label>Enable Coinsnap on this form</label>
                          </div>
                        </div>';

		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row">
                          <label>Currency Code (required)</label>
                          <div class="coinsnapcf7-field"><input type="text" value="' . esc_html($coinsnap_currency) . '" placeholder="EUR" name="coinsnap_currency">
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row">
                          <label>Store ID (required)</label>
                          <div class="coinsnapcf7-field"><input class="long-input" type="text" value="' . esc_html($coinsnap_store_id) . '" name="coinsnap_store_id">
                          <div class="description">Please input your personal Store ID, which you will find in your Coinsnap account.</div>
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row">
                          <label>API Key (required)</label>
                          <div class="coinsnapcf7-field"><input class="long-input" type="text" value="' . esc_html($coinsnap_api_key) . '" name="coinsnap_api_key">
                          <div class="description">Please input the API Key that you will find in your Coinsnap account.</div>
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row">
                          <label>Success URL</label>
                          <div class="coinsnapcf7-field"><input class="long-input" type="text" value="' . esc_html($coinsnap_s_url) . '" name="coinsnap_s_url">
                          <div class="description">Please enter here the URL of the page on your website that the buyer will be re-directed to after he finalizes the transaction. (Note: You must create this page, i.e. a “thank you”-page, yourself on your website!)</div> 
                          </div>
                        </div>';
		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row"><h3>How do I integrate Bitcoin-Lighting Payment in my form?</h3></div><ol>';
		echo '<li class="coinsnapcf7-row">To specify the cost of your offering, use the number element and name it <strong>cs_amount</strong>: [number* cs_amount min:0.01]</li>';
		echo '<li class="coinsnapcf7-row">You define the cost of your offering by manipulating the value of the 0.01 in the tag, i.e. writing [number* cs_amount min:2.50]</li>';
		echo '<li class="coinsnapcf7-row">If you want to see the name and/or the email of the buyer in the transaction overview in your Coinsnap account, name the respective fields accordingly <strong>cs_name</strong> and <strong>cs_email</strong>: [text cs_name] [email cs_email]</li>';
		echo '</ol><div class="coinsnapcf7-row"><p>NOTE: <strong>cs_amount</strong> is a mandatory field you must use in your form to make the plugin work. <strong>cs_name</strong> and <strong>cs_email</strong> you only need to use if you want to see this information in your Coinsnap transaction overview.</p></div>';
		echo '<div class="coinsnapcf7-row"><hr></div>';
		echo '<div class="coinsnapcf7-row"><h3>Or copy this code into your form, and then add all other fields needed to create your transactional form:</h3></div>';

		echo '<div class="coinsnapcf7-row">
                    <div class="coinsnapcf7-field">
                        <textarea readonly rows="6">
<label>Enter amount</label>
[number* cs_amount min:0.01]
<label>Your name</label>
[text cs_name]
<label>Your email</label>
[email cs_email]
                        </textarea>
                        <div class="description">Please copy the contents of the textarea above and paste it in your form. Note that the `<strong>cs_amount</strong>` field is mandatory, `<strong>cs_name</strong>` and `<strong>cs_email</strong>` are optional.</div>
                    </div>
                </div>';
		echo '<input type="hidden" name="post" value="' . esc_html($post_id) . '"></div>';

		//echo $admin_settings;
	}

	public function redirect_payment( $cf7 ) {
		global $wpdb;
		global $postid;

		$post_id       = $cf7->id();
		$this->_postid = $post_id;

		$enable = get_post_meta( $post_id, "_cf7_coinsnap_enable", true );
		if ( ! $enable ) {
			return false;
		}

		$wpcf7           = WPCF7_ContactForm::get_current();
		$submission      = WPCF7_Submission::get_instance();
		$amount_field    = 'cs_amount';
		$name_field      = 'cs_name';
		$email_field     = 'cs_email';
		$form_properties = $wpcf7->get_properties();
		$form_content = $form_properties['form'];
		$field_to_check = 'cs_amount';
		if ( !str_contains( $form_content, $field_to_check ) ) {
			return;
		}
		$submission_data = $submission->get_posted_data();
		$payment_amount  = $submission_data[ $amount_field ] ?? '0';
		$currency        = get_post_meta( $post_id, "_cf7_coinsnap_currency", true );
		$buyerEmail      = $submission_data[ $email_field ] ?? '';
		$buyerName       = $submission_data[ $name_field ] ?? '';
		
		// Remove already populated data.
		$remove = [ $amount_field, $name_field, $email_field ];

		$remainingMetadata = array_diff_key( $submission_data, array_flip( $remove ) );
/*
		$webhook_url     = $this->get_webhook_url();
                if ( ! $this->webhookExists( $this->getStoreId(), $this->getApiKey(), $webhook_url ) ) {
			if ( ! $this->registerWebhook( $this->getStoreId(), $this->getApiKey(), $webhook_url ) ) {
				echo( esc_html__( 'unable to set Webhook url.', 'coinsnap-for-contact-form-7' ) );
				exit;
			}
		}
*/
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

		$return_url = get_post_meta( $post_id, "_cf7_coinsnap_s_url", true );

		$invoice_no               = $wpdb->insert_id;
		$amount                   = round( $payment_amount, 2 );
		$metadata                 = [];
		$metadata['orderNumber']  = $invoice_no;
		$metadata['customerName'] = $buyerName;
		$metadata['customerEmail'] = $buyerEmail;

		$checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
		$checkoutOptions->setRedirectURL( $return_url );
		$client  = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );
		$camount = \Coinsnap\Util\PreciseNumber::parseFloat( $amount, 2 );

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
			$checkoutOptions
		);


        $payurl = $csinvoice->getData()['checkoutLink'] ;
		wp_redirect( $payurl );
		exit();

	}

    public function get_tablename()
    {
        global $wpdb;
        return $wpdb->prefix . "coinsnapcf7_extension";
    }


    public function process_webhook() {
        global $wpdb;

        if ( filter_input(INPUT_GET,'cf7-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) === null  || filter_input(INPUT_GET,'cf7-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap' ) {
            return;
	}

		$this->_postid = filter_input(INPUT_GET,'form_id',FILTER_SANITIZE_FULL_SPECIAL_CHARS);

		$notify_json = file_get_contents( 'php://input' );

		$notify_ar  = json_decode( $notify_json, true );
		$invoice_id = $notify_ar['invoiceId'];

		try {
			$client    = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );
			$csinvoice = $client->getInvoice( $this->getStoreId(), $invoice_id );
			$status    = $csinvoice->getData()['status'];
			$order_id  = $csinvoice->getData()['orderId'];

		} catch ( \Throwable $e ) {
			echo "Error";
			exit;
		}
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


	public function get_webhook_url() {
		return get_site_url() . '/?cf7-listener=coinsnap&form_id=' . $this->_postid;
	}

	public function getStoreId() {

		return get_post_meta( $this->_postid, "_cf7_coinsnap_store_id", true );
	}

	public function getApiKey() {
		return get_post_meta( $this->_postid, "_cf7_coinsnap_api_key", true );
	}

	public function getApiUrl() {
		return 'https://app.coinsnap.io';
	}

	public function webhookExists( string $storeId, string $apiKey, string $webhook ): bool {
		try {
			$whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );
			$Webhooks = $whClient->getWebhooks( $storeId );


			foreach ( $Webhooks as $Webhook ) {
				//self::deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
				if ( $Webhook->getData()['url'] == $webhook ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}

	public function registerWebhook( string $storeId, string $apiKey, string $webhook ): bool {
		try {
			$whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );

			$webhook = $whClient->createWebhook(
				$storeId,   //$storeId
				$webhook, //$url
				self::WEBHOOK_EVENTS,
				null    //$secret
			);

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}

	public function deleteWebhook( string $storeId, string $apiKey, string $webhookid ): bool {
		try {
			$whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );
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
