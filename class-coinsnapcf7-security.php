<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment security for Bitcoin Payment for Contact Form 7 (Coinsnap Plugin Security Guide).
 *
 * A form entry counts as paid only after the Coinsnap or BTCPay API confirms the invoice the
 * plugin created for it, for the amount the form's own configuration allows, and only once.
 */
class CoinsnapCf7_Security {

    const DB_VERSION_KEY = 'coinsnapcf7_db_version';
    const DB_VERSION     = '1';
    const UPGRADED_AT    = 'coinsnapcf7_security_upgraded_at';
    const LEGACY_WINDOW  = DAY_IN_SECONDS;
    const TABLE          = 'coinsnapcf7_invoices';
    const STATE_PREFIX   = 'coinsnapcf7_btcpay_state_';
    const WEBHOOK_META   = '_cf7_coinsnap_webhook';

    /** Only what the plugin uses, for one store: invoices, payment methods and rates, its own webhook. */
    const BTCPAY_PERMISSIONS = [
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewstoresettings',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    /* ---------------------------------------------------------------------
     * Amount from the form configuration, never from the browser alone (3)
     * ------------------------------------------------------------------- */

    /**
     * The amount a submission pays, as decided by the form's cs_amount tag.
     *
     * - hidden or readonly tag: the value written in the form, whatever the browser sent;
     * - select, radio, checkbox: exactly one of the configured values;
     * - number, range, text: the visitor's amount, within the tag's min/max.
     *
     * Returns a normalised decimal string, or null when the submission isn't allowed.
     */
    public static function allowed_amount(string $basetype, bool $fixed, array $configured, ?string $min, ?string $max, $posted): ?string {
        if ($basetype === 'hidden' || $fixed) {
            $amount = self::normalise_amount($configured[0] ?? '');
        } elseif (in_array($basetype, ['select', 'radio', 'checkbox'], true)) {
            if (is_array($posted)) {
                $posted = array_values(array_filter($posted, static function ($v) { return $v !== '' && $v !== null; }));
                if (count($posted) !== 1) {
                    return null;
                }
                $posted = $posted[0];
            }
            if (!is_scalar($posted) || !in_array((string) $posted, array_map('strval', $configured), true)) {
                return null;
            }
            $amount = self::normalise_amount($posted);
        } else {
            if (is_array($posted)) {
                return null;
            }
            $amount = self::normalise_amount($posted);
            if ($amount === null) {
                return null;
            }
            $value = self::to_units($amount, 8);
            if ($min !== null && $min !== '' && ($m = self::to_units(self::normalise_amount($min) ?? '', 8)) !== null && $value < $m) {
                return null;
            }
            if ($max !== null && $max !== '' && ($m = self::to_units(self::normalise_amount($max) ?? '', 8)) !== null && $value > $m) {
                return null;
            }
        }
        if ($amount === null || (int) self::to_units($amount, 8) <= 0) {
            return null;
        }
        return $amount;
    }

    /** "10", "10.5", "10,50" → "10", "10.5", "10.50"; anything else (negative, letters, exponent) → null. */
    public static function normalise_amount($value): ?string {
        if (!is_scalar($value)) {
            return null;
        }
        $s = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,8})?$/', $s)) {
            return null;
        }
        return $s;
    }

    /** The amount for this submission from the form's cs_amount tag (WordPress/CF7 adapter for allowed_amount()). */
    public static function amount_from_form($contact_form, array $posted_data): ?string {
        if (!is_object($contact_form) || !method_exists($contact_form, 'scan_form_tags')) {
            return null;
        }
        $tags = $contact_form->scan_form_tags(['name' => 'cs_amount']);
        if (count($tags) !== 1) {
            return null;
        }
        $tag = $tags[0];
        $configured = (array) $tag->values;
        // With pipes ("Label|10"), the submission holds the part after the pipe.
        if (isset($tag->pipes) && $tag->pipes instanceof WPCF7_Pipes && !$tag->pipes->zero()) {
            $configured = $tag->pipes->collect_afters();
        }
        $min = $tag->get_option('min', 'signed_num', true);
        $max = $tag->get_option('max', 'signed_num', true);
        return self::allowed_amount(
            (string) $tag->basetype,
            $tag->has_option('readonly'),
            $configured,
            ($min === false) ? null : (string) $min,
            ($max === false) ? null : (string) $max,
            $posted_data['cs_amount'] ?? null
        );
    }

    /* ---------------------------------------------------------------------
     * Webhook signature (handler step 1)
     * ------------------------------------------------------------------- */

    /** Null when valid, otherwise the fixed reason for the log. An empty secret rejects every webhook. */
    public static function signature_problem(string $provider, string $header, string $body, string $secret, int $now): ?string {
        if ($provider === 'btcpay') {
            // BTCPay-Sig: sha256=<hex HMAC-SHA256 of the body>
            return ($secret !== '' && $header !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header)) ? null : 'bad signature';
        }
        // X-Coinsnap-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">
        parse_str(str_replace(',', '&', $header), $p);
        $t = (int) ($p['t'] ?? 0);
        $v1 = $p['v1'] ?? '';
        if ($secret === '' || $t <= 0 || !is_string($v1) || !hash_equals(hash_hmac('sha256', $t . '.' . $body, $secret), $v1)) {
            return 'bad signature';
        }
        return (abs($now - $t) > 300) ? 'stale timestamp' : null;
    }

    /* ---------------------------------------------------------------------
     * When is an entry paid (3)
     * ------------------------------------------------------------------- */

    const PAID       = 'paid';
    const MISMATCH   = 'mismatch';   // Settled, but not for what this plugin asked (or marked settled by hand in BTCPay)
    const PROCESSING = 'processing';
    const FAILED     = 'failed';     // Expired or Invalid
    const NONE       = 'none';

    public static function decide(array $invoice, bool $isBtcpay, string $expectedAmount, string $expectedCurrency): string {
        $status = (string) ($invoice['status'] ?? '');
        if ($status === 'Settled') {
            return self::invoice_pays($invoice, $expectedAmount, $expectedCurrency, $isBtcpay) ? self::PAID : self::MISMATCH;
        }
        if ($status === 'Processing') {
            return self::PROCESSING;
        }
        return in_array($status, ['Expired', 'Invalid'], true) ? self::FAILED : self::NONE;
    }

    public static function invoice_pays(array $invoice, string $expectedAmount, string $currency, bool $isBtcpay): bool {
        $currency = strtoupper($currency);
        $expected = self::to_units($expectedAmount, 8);
        if ($expected === null || $expected <= 0 || ($invoice['status'] ?? '') !== 'Settled') {
            return false;
        }
        if ($isBtcpay && ($invoice['additionalStatus'] ?? '') === 'Marked') {
            return false; // settled by hand in BTCPay
        }
        if (strtoupper((string) ($invoice['currency'] ?? '')) !== $currency) {
            return false;
        }
        if ($isBtcpay) {
            $paid = $invoice['amount'] ?? null; // BTCPay: in the invoice currency
        } elseif ($currency === 'SATS') {
            $paid = $invoice['invoiceAmount'] ?? $invoice['amount'] ?? null; // Coinsnap: amount is sats
        } else {
            $paid = $invoice['invoiceAmount'] ?? $invoice['fiatAmount'] ?? null; // Coinsnap: invoice currency
        }
        $paid = ($paid === null || $paid === '') ? null : self::to_units($paid, 8);
        if ($paid === null) {
            return false;
        }
        // One unit of the 8th decimal for BTC, one cent (or one sat / yen) otherwise.
        $tolerance = ($currency === 'BTC') ? 1 : 1000000;
        return abs($paid - $expected) <= $tolerance;
    }

    /** A non-negative amount as whole units of 10^-$decimals, or null. Never compared as floats. */
    public static function to_units($value, int $decimals): ?int {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float) $value, $decimals, '.', '');
        }
        if (!is_string($value) || !preg_match('/^(\d+)(?:\.(\d+))?$/', trim($value), $m)) {
            return null;
        }
        $frac = str_pad($m[2] ?? '', $decimals + 1, '0');
        $roundUp = ((int) $frac[$decimals] >= 5) ? 1 : 0;
        return (int) $m[1] * (10 ** $decimals) + (int) substr($frac, 0, $decimals) + $roundUp;
    }

    /* ---------------------------------------------------------------------
     * Fetching the invoice (handler step 3)
     * ------------------------------------------------------------------- */

    /** The invoice, null if the API doesn't know it, or throws RuntimeException (→ 503). */
    public static function fetch_invoice(string $apiUrl, string $apiKey, string $storeId, string $invoiceId): ?array {
        try {
            $data = (new \Coinsnap\Client\Invoice($apiUrl, $apiKey))->getInvoice($storeId, $invoiceId)->getData();
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array((int) $e->getCode(), [400, 404], true)) {
                return null; // Coinsnap 400 / BTCPay 404: unknown invoice, e.g. a dashboard test webhook
            }
            throw new \RuntimeException('API unavailable');
        } catch (\Throwable $e) {
            throw new \RuntimeException('API unavailable');
        }
        return is_array($data) ? $data : null;
    }

    /* ---------------------------------------------------------------------
     * Invoice records: every invoice the plugin created, acted on once (4, 6.7, 6.9)
     * ------------------------------------------------------------------- */

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(bool $force = false): void {
        global $wpdb;
        if (!$force && get_option(self::DB_VERSION_KEY) === self::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
  invoice_id varchar(64) NOT NULL,
  entry_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  provider varchar(10) NOT NULL,
  amount varchar(32) NOT NULL,
  currency varchar(10) NOT NULL,
  created_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (invoice_id),
  KEY entry_id (entry_id)
) {$charset};");
        if (!get_option(self::UPGRADED_AT)) {
            // Entries started before this version have no record; they are accepted for one day (6.7).
            update_option(self::UPGRADED_AT, time(), false);
        }
        update_option(self::DB_VERSION_KEY, self::DB_VERSION, true);
    }

    public static function record_invoice(string $invoiceId, int $entryId, int $formId, string $provider, string $amount, string $currency): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $r = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (invoice_id, entry_id, form_id, provider, amount, currency, created_at) VALUES (%s, %d, %d, %s, %s, %s, %s)',
            self::table(), $invoiceId, $entryId, $formId, $provider, $amount, strtoupper($currency), current_time('mysql', true)
        ));
        return $r === 1;
    }

    public static function get_record(string $invoiceId): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE invoice_id = %s', self::table(), $invoiceId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function entry_has_record(int $entryId): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE entry_id = %d LIMIT 1', self::table(), $entryId));
    }

    /** True for exactly one caller per invoice, however many webhooks arrive at once. */
    public static function mark_paid_once(string $invoiceId): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $r = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET paid_at = %s WHERE invoice_id = %s AND paid_at IS NULL',
            self::table(), current_time('mysql', true), $invoiceId
        ));
        return $r === 1;
    }

    public static function upgraded_at(): int {
        return (int) get_option(self::UPGRADED_AT, 0);
    }

    public static function in_legacy_window(): bool {
        $at = self::upgraded_at();
        return $at > 0 && time() - $at <= self::LEGACY_WINDOW;
    }

    /* ---------------------------------------------------------------------
     * Stored webhook (id, secret, url) per form, with the provider and store it belongs to (6.4)
     * ------------------------------------------------------------------- */

    public static function get_webhook(int $formId): array {
        $raw = get_post_meta($formId, self::WEBHOOK_META, true);
        if (is_string($raw) && $raw !== '' && is_serialized($raw)) {
            // Written by versions up to 1.5.3 with serialize(); plain arrays only, never objects.
            $raw = @unserialize($raw, ['allowed_classes' => false]); // phpcs:ignore
        }
        if (!is_array($raw)) {
            return [];
        }
        return [
            'id'       => is_scalar($raw['id'] ?? null) ? (string) $raw['id'] : '',
            'secret'   => is_scalar($raw['secret'] ?? null) ? (string) $raw['secret'] : '',
            'url'      => is_scalar($raw['url'] ?? null) ? (string) $raw['url'] : '',
            'provider' => is_scalar($raw['provider'] ?? null) ? (string) $raw['provider'] : '',
            'store'    => is_scalar($raw['store'] ?? null) ? (string) $raw['store'] : '',
        ];
    }

    /** The secret to check webhooks with, only if it belongs to the form's current provider and store. */
    public static function webhook_secret(int $formId, string $provider, string $storeId): string {
        $wh = self::get_webhook($formId);
        if (($wh['secret'] ?? '') === '') {
            return '';
        }
        // Records from before 1.5.4 have no provider/store: they are the form's current webhook.
        if ($wh['provider'] !== '' && ($wh['provider'] !== $provider || $wh['store'] !== $storeId)) {
            return '';
        }
        return $wh['secret'];
    }

    public static function save_webhook(int $formId, string $id, string $secret, string $url, string $provider, string $storeId): void {
        update_post_meta($formId, self::WEBHOOK_META, [
            'id' => $id, 'secret' => $secret, 'url' => $url, 'provider' => $provider, 'store' => $storeId,
        ]);
    }

    /* ---------------------------------------------------------------------
     * BTCPay connect: one-time state, and key permissions asked from BTCPay (1)
     * ------------------------------------------------------------------- */

    /** Called from the admin AJAX handler after capability and nonce checks. */
    public static function btcpay_start_state(int $formId, string $btcpayUrl): string {
        $state = bin2hex(random_bytes(32));
        set_transient(self::STATE_PREFIX . hash('sha256', $state), ['form_id' => $formId, 'url' => $btcpayUrl, 'user' => get_current_user_id()], 10 * MINUTE_IN_SECONDS);
        return $state;
    }

    /** One use, even if what follows fails. Returns ['form_id' => int, 'url' => string] or null. */
    public static function btcpay_consume_state(string $state): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $state)) {
            return null;
        }
        $key = self::STATE_PREFIX . hash('sha256', $state);
        $pending = get_transient($key);
        delete_transient($key);
        if (!is_array($pending) || (int) ($pending['form_id'] ?? 0) <= 0 || !is_string($pending['url'] ?? null) || $pending['url'] === '') {
            return null;
        }
        return ['form_id' => (int) $pending['form_id'], 'url' => $pending['url']];
    }

    /** Permissions of the key as BTCPay reports them (GET /api/v1/api-keys/current), or null. */
    public static function btcpay_key_permissions(string $btcpayUrl, string $apiKey): ?array {
        if ($btcpayUrl === '' || $apiKey === '') {
            return null;
        }
        $response = wp_remote_get(rtrim($btcpayUrl, '/') . '/api/v1/api-keys/current', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'token ' . $apiKey, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $key = json_decode((string) wp_remote_retrieve_body($response), true);
        return (is_array($key) && is_array($key['permissions'] ?? null)) ? array_values(array_map('strval', $key['permissions'])) : null;
    }

    /** The one store the key is limited to, or null if it is unrestricted, spans stores, or lacks a needed permission. */
    public static function btcpay_key_store(array $permissions): ?string {
        $storeId = null;
        foreach ($permissions as $permission) {
            $parts = explode(':', (string) $permission, 2);
            $scope = $parts[1] ?? '';
            if (strpos($parts[0], 'btcpay.store.') !== 0 || $scope === '') {
                return null; // unrestricted, server or user rights, or valid for every store
            }
            if ($storeId !== null && $scope !== $storeId) {
                return null; // more than one store
            }
            $storeId = $scope;
        }
        if ($storeId === null) {
            return null;
        }
        foreach (self::BTCPAY_PERMISSIONS as $needed) {
            if (!in_array($needed . ':' . $storeId, $permissions, true)) {
                return null;
            }
        }
        return $storeId;
    }

    /** The store the key is limited to, if BTCPay confirms it is acceptable (and equals $expectedStoreId when given). */
    public static function btcpay_verify_key(string $btcpayUrl, string $apiKey, string $expectedStoreId = ''): ?string {
        $permissions = self::btcpay_key_permissions($btcpayUrl, $apiKey);
        $storeId = ($permissions === null) ? null : self::btcpay_key_store($permissions);
        if ($storeId === null || ($expectedStoreId !== '' && $storeId !== $expectedStoreId)) {
            return null;
        }
        return $storeId;
    }

    /* ---------------------------------------------------------------------
     * Settings display and logs (5, 6.13)
     * ------------------------------------------------------------------- */

    public static function secret_hint(string $value): string {
        return $value === '' ? '' : '••••••••' . substr($value, -4);
    }

    /** A valid http(s) URL without trailing slash, or ''. */
    public static function clean_server_url($value): string {
        $url = filter_var(trim((string) $value), FILTER_VALIDATE_URL);
        if ($url === false || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return rtrim($url, '/');
    }

    /** $level: 'security' (someone may be faking a payment) or 'info'. Only a fixed reason and the invoice ID. */
    public static function log(string $level, string $reason, string $invoiceId = ''): void {
        if ($level === 'security' && !self::security_log_allowed()) {
            return;
        }
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('[coinsnap-for-contact-form-7] %s webhook reason=%s invoice=%s', strtoupper($level), $reason, $id !== '' ? $id : '-'));
    }

    private static function security_log_allowed(): bool {
        $key = 'coinsnapcf7_seclog_' . gmdate('YmdHi');
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, 2 * MINUTE_IN_SECONDS);
        if ($count === 11) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[coinsnap-for-contact-form-7] SECURITY webhook more rejections this minute, not logged');
        }
        return $count <= 10;
    }
}
