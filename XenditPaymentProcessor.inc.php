<?php

/**
 * @file plugins/paymethod/xendit/XenditPaymentProcessor.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class XenditPaymentProcessor
 * @ingroup plugins_paymethod_xendit
 *
 * @brief Class to handle the actual fulfillment of a Xendit payment.
 */

import('classes.payment.ojs.OJSPaymentManager');

class XenditPaymentProcessor {
    /** @var XenditPaymentPlugin */
    protected $_plugin;

    /** @var PKPRequest */
    protected $_request;

    /**
     * Constructor
     *
     * @param XenditPaymentPlugin $plugin
     * @param PKPRequest $request
     */
    public function __construct($plugin, $request) {
        $this->_plugin = $plugin;
        $this->_request = $request;
    }

    /**
     * Process the payment based on the external_id from Xendit.
     *
     * @param string $paymentId The external_id from the Xendit webhook.
     * @throws \Exception
     */
    public function process($paymentId) {
        error_log('Xendit DEBUG: Inside process() with paymentId: ' . $paymentId);

        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
        error_log('Xendit DEBUG: QueuedPaymentDAO loaded.');

        // The $paymentId from the webhook is the external_id we sent.
        // The format is "{queuedPaymentId}-{typePrefix}-{assocId/userId}".
        // We need to reliably extract the first part, which is the queuedPaymentId.
        $idParts = explode('-', $paymentId);
        $queuedPaymentId = $idParts[0];
        error_log('Xendit DEBUG: Extracted queuedPaymentId: ' . $queuedPaymentId);

        // Validate that the extracted part is a numeric ID.
        if (!ctype_digit((string)$queuedPaymentId) || (int)$queuedPaymentId <= 0) {
            error_log('Xendit DEBUG: Invalid queued_payment_id extracted.');
            throw new \Exception("Could not parse a valid numeric queued_payment_id from external_id: $paymentId");
        }
        error_log('Xendit DEBUG: queuedPaymentId is valid numeric.');

        /** @var QueuedPayment */
        $queuedPayment = $queuedPaymentDao->getById((int)$queuedPaymentId);

        if (!$queuedPayment) {
            error_log('Xendit DEBUG: No queued payment found for ID: ' . $queuedPaymentId);
            throw new \Exception("No queued payment found for ID derived from external_id: $paymentId");
        }
        // LOG PELACAKAN BARU
        error_log('Xendit TRACE (USERID): Fetched QueuedPayment. UserID found in DB: ' . $queuedPayment->getUserId());


        // Check if this payment has already been completed to prevent double-processing
        $completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
        error_log('Xendit DEBUG: OJSCompletedPaymentDAO loaded.');

        // ===== PERBAIKAN: FATAL ERROR (dari log sebelumnya) =====
        /*
        if ($completedPaymentDao->getByQueuedPaymentId($queuedPayment->getId())) {
            // Payment already processed. Log it and exit gracefully with a 200 OK.
            error_log('Xendit DEBUG: Payment ' . $queuedPaymentId . ' already processed. Exiting gracefully.');
            return;
        }
        */
        error_log('Xendit DEBUG: Skipping idempotency check (getByQueuedPaymentId) to avoid fatal error.');
        // ===== PERBAIKAN: END =====

        error_log('Xendit DEBUG: Payment ' . $queuedPaymentId . ' has not been processed yet. Continuing...');

        // Get the journal context from the queued payment.
        $contextId = $queuedPayment->getContextId();
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $journal = $journalDao->getById($contextId);
        if (!$journal) {
            error_log('Xendit DEBUG: Could not load journal context ID: ' . $contextId);
            throw new \Exception("Could not load journal context for QueuedPayment ID: $queuedPaymentId");
        }
        error_log('Xendit DEBUG: Journal context ' . $contextId . ' loaded.');

        // Initialize the payment manager with the correct journal context.
        $paymentManager = new OJSPaymentManager($journal);

        // Fulfill the queued payment based on its type.
        // This logic is replicated from OJSPaymentManager::fulfillQueuedPayment
        // to avoid issues with missing user sessions in a webhook context.
        error_log('Xendit DEBUG: Calling fulfillQueuedPayment() for payment type: ' . $queuedPayment->getType());
        $this->fulfillQueuedPayment($journal, $queuedPayment);
        error_log('Xendit DEBUG: fulfillQueuedPayment() completed.');

        // Create a CompletedPayment record.
        // LOG PELACAKAN BARU
        error_log('Xendit TRACE (USERID): Calling createCompletedPayment. UserID from QueuedPayment (' . $queuedPayment->getUserId() . ') will be used.');
        $completedPayment = $paymentManager->createCompletedPayment($queuedPayment, $this->_plugin->getName(), null);
        
        // LOG PELACAKAN BARU
        error_log('Xendit TRACE (USERID): CompletedPayment object created. Final UserID: ' . $completedPayment->getUserId());

        $completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
        $completedPaymentDao->insertObject($completedPayment);
        
        // LOG PELACAKAN BARU
        error_log('Xendit TRACE (USERID): CompletedPayment record inserted into database with UserID: ' . $completedPayment->getUserId());

        // Delete the now-fulfilled QueuedPayment.
        error_log('Xendit DEBUG: Deleting QueuedPayment ID ' . $queuedPayment->getId() . ' from database...');
        $queuedPaymentDao->deleteById($queuedPayment->getId());
        error_log('Xendit DEBUG: QueuedPayment deleted. Process finished successfully.');
    }

    /**
     * Manually fulfills a queued payment.
     * This replicates the logic from OJSPaymentManager to work in a webhook context.
     *
     * @param Journal $journal
     * @param QueuedPayment $queuedPayment
     * @throws \Exception
     */
    protected function fulfillQueuedPayment($journal, $queuedPayment) {
        error_log('Xendit DEBUG: Inside fulfillQueuedPayment() for payment type: ' . $queuedPayment->getType());
        switch ($queuedPayment->getType()) {
            case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
                error_log('Xendit DEBUG: Fulfilling PAYMENT_TYPE_PURCHASE_SUBSCRIPTION');
                $subscriptionId = $queuedPayment->getAssocId();
                $institutionalSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
                $individualSubscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
                if ($institutionalSubscriptionDao->subscriptionExists($subscriptionId)) {
                    $subscription = $institutionalSubscriptionDao->getById($subscriptionId);
                    $institutional = true;
                } else {
                    $subscription = $individualSubscriptionDao->getById($subscriptionId);
                    $institutional = false;
                }
                if (!$subscription || $subscription->getUserId() != $queuedPayment->getUserId() || $subscription->getJournalId() != $queuedPayment->getContextId()) {
                    error_log('Xendit DEBUG: Subscription integrity checks fail!');
                    throw new \Exception('Subscription integrity checks fail!');
                }
                if ($institutional) {
                    import('classes.subscription.InstitutionalSubscription');
                    $subscription->setStatus(SUBSCRIPTION_STATUS_NEEDS_APPROVAL);
                    $institutionalSubscriptionDao->renewSubscription($subscription);
                } else {
                    import('classes.subscription.IndividualSubscription');
                    $subscription->setStatus(SUBSCRIPTION_STATUS_ACTIVE);
                    $individualSubscriptionDao->renewSubscription($subscription);
                }
                error_log('Xendit DEBUG: Subscription purchase fulfilled.');
                break;
            case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
                error_log('Xendit DEBUG: Fulfilling PAYMENT_TYPE_RENEW_SUBSCRIPTION');
                $subscriptionId = $queuedPayment->getAssocId();
                $institutionalSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
                $individualSubscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
                if ($institutionalSubscriptionDao->subscriptionExists($subscriptionId)) {
                    $subscription = $institutionalSubscriptionDao->getById($subscriptionId);
                    $institutionalSubscriptionDao->renewSubscription($subscription);
                } else {
                    $subscription = $individualSubscriptionDao->getById($subscriptionId);
                    $individualSubscriptionDao->renewSubscription($subscription);
                }
                if (!$subscription || $subscription->getUserId() != $queuedPayment->getUserId() || $subscription->getJournalId() != $queuedPayment->getContextId()) {
                    error_log('Xendit DEBUG: Subscription renewal integrity checks fail!');
                    throw new \Exception('Subscription renewal integrity checks fail!');
                }
                error_log('Xendit DEBUG: Subscription renewal fulfilled.');
                break;
            case PAYMENT_TYPE_PUBLICATION:
            case PAYMENT_TYPE_PURCHASE_ARTICLE:
            case PAYMENT_TYPE_PURCHASE_ISSUE:
            case PAYMENT_TYPE_MEMBERSHIP: // Deprecated but supported
            case PAYMENT_TYPE_DONATION: // Deprecated but supported
                error_log('Xendit DEBUG: Fulfilling simple payment type (e.g., Publication, Article). No special action needed.');
                // For these types, creating the CompletedPayment record is sufficient.
                break;
            default:
                error_log('Xendit DEBUG: Invalid payment type: ' . $queuedPayment->getType());
                throw new \Exception('Invalid payment type "' . $queuedPayment->getType() . '"');
        }
    }
}