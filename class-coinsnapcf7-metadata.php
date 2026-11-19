<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Invoice metadata for a form entry: the entry ID as orderId, the form and the submitted fields.
 * Values are sent as plain text (no HTML escaping); empty values are left out.
 */
final class CoinsnapCf7_Metadata {

    public const PLATFORM = 'contact-form-7';
    public const MAX_LENGTH = 500;

    /** Field types never sent: files, spam/quiz checks, hidden fields, buttons, the amount. */
    private const SKIP_BASETYPES = ['file', 'captchac', 'captchar', 'quiz', 'hidden', 'submit', 'acceptance', 'password', 'response', 'recaptcha', 'turnstile'];

    /**
     * @param array $args entry_id, form_id, form_title, tags (list of ['basetype' => ..., 'name' => ...]),
     *                    posted (submitted values by field name), name, email, page_url, post_id, version, language
     */
    public static function for_entry(array $args): array {
        $entryId = (string) ($args['entry_id'] ?? '');
        $meta = [];
        // The webhook matches on the entry ID (top-level orderId, metadata orderId / orderNumber): keep these.
        self::put($meta, 'orderId', $entryId);
        self::put($meta, 'orderNumber', $entryId);
        self::put($meta, 'entryId', $entryId);
        self::put($meta, 'formId', (string) ($args['form_id'] ?? ''));
        self::put($meta, 'formTitle', $args['form_title'] ?? '');
        self::put($meta, 'itemDesc', $args['form_title'] ?? '');
        self::put($meta, 'platform', self::PLATFORM);
        self::put($meta, 'pluginVersion', $args['version'] ?? '');
        self::put($meta, 'pageUrl', $args['page_url'] ?? '');
        $postId = (int) ($args['post_id'] ?? 0);
        if ($postId > 0) {
            $meta['postId'] = (string) $postId;
        }
        self::put($meta, 'language', $args['language'] ?? '');

        // Personal data. The plugin has no "send customer data" setting, so it is always sent.
        $posted = is_array($args['posted'] ?? null) ? $args['posted'] : [];
        $name = $args['name'] ?? '';
        $email = $args['email'] ?? '';
        self::put($meta, 'customerName', $name);
        self::put($meta, 'buyerName', $name);
        self::put($meta, 'buyerEmail', $email);
        self::put($meta, 'customerEmail', $email);

        $used = ['cs_amount' => true, 'cs_name' => true, 'cs_email' => true];
        $fields = [];
        foreach ((array) ($args['tags'] ?? []) as $tag) {
            $basetype = strtolower((string) ($tag['basetype'] ?? ''));
            $fieldName = (string) ($tag['name'] ?? '');
            if ($fieldName === '' || isset($used[$fieldName]) || !array_key_exists($fieldName, $posted)) {
                continue;
            }
            $used[$fieldName] = true;
            if (in_array($basetype, self::SKIP_BASETYPES, true) || self::is_secret_name($fieldName)) {
                continue;
            }
            $value = self::clean($posted[$fieldName]);
            if ($value === null) {
                continue;
            }
            if ($basetype === 'email' && !isset($meta['buyerEmail'])) {
                $meta['buyerEmail'] = $value;
            } elseif ($basetype === 'tel' && !isset($meta['buyerPhone'])) {
                $meta['buyerPhone'] = $value;
            } elseif ($basetype === 'textarea' && !isset($meta['buyerMessage'])) {
                $meta['buyerMessage'] = $value;
            } else {
                $fields[$fieldName] = $value;
            }
        }
        if ($fields) {
            $meta['formFields'] = $fields;
        }
        return $meta;
    }

    /** Adds $value under $key unless it is empty. */
    public static function put(array &$meta, string $key, $value): void {
        $value = self::clean($value);
        if ($value !== null) {
            $meta[$key] = $value;
        }
    }

    /** A plain value for metadata, or null to leave it out (empty, not text, or a card number). */
    public static function clean($value) {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                if (is_scalar($part) && !is_bool($part)) {
                    $part = self::clean($part);
                    if ($part !== null) {
                        $parts[] = (string) $part;
                    }
                }
            }
            return $parts ? self::cut(implode(', ', $parts)) : null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || self::looks_like_card_number($value)) {
            return null;
        }
        return self::cut($value);
    }

    /** 13 to 19 digits (spaces and dashes allowed) that pass the Luhn check. */
    public static function looks_like_card_number(string $value): bool {
        $digits = preg_replace('/[\s-]/', '', $value);
        if (!is_string($digits) || !preg_match('/^\d{13,19}$/', $digits)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /** Field names that hold security values (nonces, tokens, captcha answers, passwords). */
    public static function is_secret_name(string $name): bool {
        return $name[0] === '_' || (bool) preg_match('/nonce|token|captcha|password|passwd|secret|honeypot/i', $name);
    }

    private static function cut(string $value): string {
        if (function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > self::MAX_LENGTH ? mb_substr($value, 0, self::MAX_LENGTH, 'UTF-8') : $value;
        }
        return strlen($value) > self::MAX_LENGTH ? substr($value, 0, self::MAX_LENGTH) : $value;
    }
}
