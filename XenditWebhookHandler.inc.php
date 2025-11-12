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

        if (!$webhookSecret || !$callbackToken) {
            return false;
        }

        $isValid = hash_equals($webhookSecret, $callbackToken);
        if (!$isValid) {
            return false;
        }
        return true;
    }

    /**
     * Parse the incoming JSON payload from Xendit.
     *
     * @return stdClass|null
     */
    public function parsePayload() {
        $jsonPayload = file_get_contents('php://input');

        $data = json_decode($jsonPayload);

        if (json_last_error() !== JSON_ERROR_NONE) {
             return null;
        }
        return $data;
    }

    /**
     * Get the external ID from the webhook data.
     *
     * @param stdClass $data
     * @return string|null
     */
    public function getPaymentId($data) {
        if (isset($data->event) && $data->event === 'invoice.paid') {
            if (isset($data->data) && isset($data->data->status) && $data->data->status === 'PAID') {
                return $data->data->external_id;
            } else {
                return null;
            }
        }

        if (!isset($data->event) && isset($data->status) && $data->status === 'PAID') {
            if (isset($data->external_id)) {
                return $data->external_id;
            } else {
                return null;
            }
        }

        return null;
    }
}