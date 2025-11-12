<?php

/**
 * @file plugins/paymethod/xendit/XenditWebhookHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class XenditWebhookHandler
 * @ingroup plugins_paymethod_xendit
 *
 * @brief Class to handle incoming webhooks from Xendit.
 */

class XenditWebhookHandler {
    /** @var XenditPaymentPlugin */
    protected $_plugin;

    /** @var Journal */
    protected $_journal;

    /**
     * Constructor
     *
     * @param XenditPaymentPlugin $plugin
     * @param Journal $journal
     */
    public function __construct($plugin, $journal) {
        $this->_plugin = $plugin;
        $this->_journal = $journal;
    }

    /**
     * Verify the webhook callback token from Xendit.
     *
     * @return bool
     */
    public function verify() {
        error_log('Xendit DEBUG: Inside verify()...');
        $webhookSecret = $this->_plugin->getSetting($this->_journal->getId(), 'webhookSecret');
        $callbackToken = null;

        if (isset($_SERVER['HTTP_X_CALLBACK_TOKEN'])) {
            $callbackToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'];
        } elseif (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            if (isset($headers['x-callback-token'])) {
                $callbackToken = $headers['x-callback-token'];
            }
        }

        // TAMBAHAN LOG: Tampilkan token untuk perbandingan
        error_log('Xendit DEBUG: Token from OJS Setting (webhookSecret): ' . $webhookSecret);
        error_log('Xendit DEBUG: Token from Xendit Header (x-callback-token): ' . $callbackToken);

        if (!$webhookSecret || !$callbackToken) {
            error_log('Xendit DEBUG: Webhook validation failed: Token not received (Secret: ' . ($webhookSecret ? 'SET' : 'NOT SET') . ', Received: ' . ($callbackToken ? 'RECEIVED' : 'NOT RECEIVED') . ')');
            return false;
        }

        $isValid = hash_equals($webhookSecret, $callbackToken);
        if (!$isValid) {
            error_log('Xendit DEBUG: Webhook validation failed: Token mismatch');
            return false;
        }
        
        error_log('Xendit DEBUG: Token verification successful.');
        return true;
    }

    /**
     * Parse the incoming JSON payload from Xendit.
     *
     * @return stdClass|null
     */
    public function parsePayload() {
        error_log('Xendit DEBUG: Inside parsePayload()...');
        $jsonPayload = file_get_contents('php://input');
        
        // TAMBAHAN LOG: Tampilkan payload mentah dari Xendit
        error_log('Xendit DEBUG: Raw payload from php://input: ' . $jsonPayload);

        $data = json_decode($jsonPayload);

        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log('Xendit DEBUG: Failed to decode JSON payload. Error: ' . json_last_error_msg());
             return null;
        }

        error_log('Xendit DEBUG: Payload parsed successfully.');
        return $data;
    }

    /**
     * Get the external ID from the webhook data.
     *
     * @param stdClass $data
     * @return string|null
     */
    public function getPaymentId($data) {
        error_log('Xendit DEBUG: Inside getPaymentId()...');

        // ===== PERBAIKAN DIMULAI DI SINI =====
        
        // 1. Cek format "Event" (yang diharapkan kode asli)
        if (isset($data->event) && $data->event === 'invoice.paid') {
            error_log('Xendit DEBUG: Found "Event" wrapper. Checking data property.');
            if (isset($data->data) && isset($data->data->status) && $data->data->status === 'PAID') {
                error_log('Xendit DEBUG: Event is "invoice.paid" and data.status is "PAID". External ID: ' . $data->data->external_id);
                return $data->data->external_id;
            } else {
                error_log('Xendit DEBUG: Event is "invoice.paid" but data.status is not PAID or data is missing.');
                return null;
            }
        }

        // 2. Cek format "Invoice" langsung (yang ada di log Anda)
        if (!isset($data->event) && isset($data->status) && $data->status === 'PAID') {
            error_log('Xendit DEBUG: Found "Invoice" payload (no event wrapper). Status is "PAID".');
            if (isset($data->external_id)) {
                error_log('Xendit DEBUG: Returning external_id: ' . $data->external_id);
                return $data->external_id;
            } else {
                error_log('Xendit DEBUG: Status is PAID but external_id is missing!');
                return null;
            }
        }
        
        // ===== PERBAIKAN SELESAI =====

        // Log jika gagal
        error_log('Xendit DEBUG: No relevant PAID status found in either payload format. No payment ID returned.');
        if (isset($data->event)) error_log('Xendit DEBUG: (Debug info) Event was: ' . $data->event);
        if (isset($data->status)) error_log('Xendit DEBUG: (Debug info) Status was: ' . $data->status);

        return null;
    }
}