<?php
declare(strict_types=1);
namespace Coinsnap\Exception;

if (!defined('ABSPATH')) {
    exit;
}

class RequestException extends CSException {
    // Host only, and no response body: the path holds store and invoice IDs, and error
    // replies can echo request data (6.13).
    public function __construct(string $method, string $url, int $status, string $body){
        $message = 'Error during ' . $method . ' to ' . (wp_parse_url($url, PHP_URL_HOST) ?: 'the payment server') . '. Got response (' . $status . ').';
        parent::__construct($message, $status);
    }
}
