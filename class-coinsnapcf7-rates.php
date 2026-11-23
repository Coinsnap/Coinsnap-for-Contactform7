<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CoinGecko exchange rates, cached, and the Coinsnap amount check.
 *
 * Kept in the plugin's own class: the bundled Coinsnap\ library is shared by all Coinsnap plugins,
 * so on a site with several of them another plugin's copy of Coinsnap\Client\Invoice may be loaded.
 */
final class CoinsnapCf7_Rates {

    private const URL = 'https://api.coingecko.com/api/v3/exchange_rates';
    private const CACHE = 'coinsnapcf7_exchange_rates';            // transient: fresh rates
    private const LAST_GOOD = 'coinsnapcf7_exchange_rates_last';   // option: last good rates, used when CoinGecko fails
    private const BACKOFF = 'coinsnapcf7_exchange_rates_backoff';  // transient: don't ask CoinGecko again for a minute after a failure
    private const TTL = 600;
    private const LAST_GOOD_MAX_AGE = 86400;

    /**
     * Rates by lower-case currency code (['value' => units of the currency per 1 BTC, ...]), or null
     * when they are unknown. Cached for 10 minutes; when CoinGecko fails (it rate-limits with 429/403),
     * the last good rates are used if they are at most a day old.
     */
    public static function get(): ?array {
        $cached = get_transient(self::CACHE);
        if (is_array($cached) && $cached) {
            return $cached;
        }
        $rates = get_transient(self::BACKOFF) ? null : self::fetch();
        if ($rates !== null) {
            set_transient(self::CACHE, $rates, self::TTL);
            update_option(self::LAST_GOOD, ['time' => time(), 'rates' => $rates], false);
            return $rates;
        }
        set_transient(self::BACKOFF, 1, 60);
        $last = get_option(self::LAST_GOOD);
        if (is_array($last) && !empty($last['rates']) && is_array($last['rates']) && (int) ($last['time'] ?? 0) > time() - self::LAST_GOOD_MAX_AGE) {
            return $last['rates'];
        }
        return null;
    }

    /**
     * Gives the cached rates to the bundled library's checkPaymentData() (BTCPay), which reads them
     * from COINSNAP_CURRENCY_RATES when that is defined instead of asking CoinGecko on every submission.
     */
    public static function share_with_library(): void {
        if (defined('COINSNAP_CURRENCY_RATES')) {
            return;
        }
        $rates = self::get();
        define('COINSNAP_CURRENCY_RATES', $rates !== null ? ['result' => true, 'data' => $rates] : ['result' => false, 'error' => 'ratesListError']);
    }

    /**
     * The Coinsnap amount check, with the result shape of the library's checkPaymentData(): a supported
     * currency and at least the smallest unit. It needs no exchange rate. Coinsnap decides about small
     * amounts (a store without Lightning refuses less than 294 sats), and its refusal is shown to the buyer.
     */
    public static function check_coinsnap_amount(float $amount, string $currency, array $supported, bool $calculation = false): array {
        $currency = strtoupper($currency);
        if (!in_array($currency, $supported, true)) {
            return ['result' => false, 'error' => 'currencyError', 'min_value' => ''];
        }
        $minimums = ['SATS' => 1, 'JPY' => 1, 'RUB' => 1, 'BTC' => 0.000001];
        $min = (float) ($minimums[$currency] ?? 0.01);
        if ($calculation) {
            return ['result' => true, 'min_value' => $min];
        }
        if ($amount <= 0 || round($amount, 8) < round($min, 8)) {
            return ['result' => false, 'error' => 'amountError', 'min_value' => $min];
        }
        return ['result' => true, 'rate' => null];
    }

    /** The rates from CoinGecko, or null when they can't be loaded. At most two tries, none more after 403/429. */
    private static function fetch(): ?array {
        $args = [
            'method' => 'GET',
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0',
                'Accept' => 'application/json',
            ],
        ];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = wp_remote_request(self::URL, $args);
            $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($status === 200) {
                $body = json_decode((string) wp_remote_retrieve_body($response), true);
                return (is_array($body) && !empty($body['rates']) && is_array($body['rates'])) ? $body['rates'] : null;
            }
            if ($status === 403 || $status === 429) {
                return null;
            }
        }
        return null;
    }
}
