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
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');

        // The $paymentId from the webhook is the external_id we sent.
        // The format is "{queuedPaymentId}-{typePrefix}-{assocId/userId}".
        // We need to reliably extract the first part, which is the queuedPaymentId.
        $idParts = explode('-', $paymentId);
        $queuedPaymentId = $idParts[0];

        // Validate that the extracted part is a numeric ID.
        if (!ctype_digit((string)$queuedPaymentId) || (int)$queuedPaymentId <= 0) {
            throw new \Exception("Could not parse a valid numeric queued_payment_id from external_id: $paymentId");
        }

        /** @var QueuedPayment */
        $queuedPayment = $queuedPaymentDao->getById((int)$queuedPaymentId);

        if (!$queuedPayment) {
            throw new \Exception("No queued payment found for ID derived from external_id: $paymentId");
        }

        // Check if this payment has already been completed to prevent double-processing
        $completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');

        if ($completedPaymentDao->getByAssoc($queuedPayment->getUserId(), $queuedPayment->getType(), $queuedPayment->getAssocId())) {
            // Payment already processed. Log it and exit gracefully with a 200 OK.
            return;
        }

        // Get the journal context from the queued payment.
        $contextId = $queuedPayment->getContextId();
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $journal = $journalDao->getById($contextId);
        if (!$journal) {
            throw new \Exception("Could not load journal context for QueuedPayment ID: $queuedPaymentId");
        }

        // Validate payment type before proceeding
        $validPaymentTypes = [
            PAYMENT_TYPE_PURCHASE_SUBSCRIPTION,
            PAYMENT_TYPE_RENEW_SUBSCRIPTION,
            PAYMENT_TYPE_PUBLICATION,
            PAYMENT_TYPE_PURCHASE_ARTICLE,
            PAYMENT_TYPE_PURCHASE_ISSUE,
            PAYMENT_TYPE_MEMBERSHIP,
            PAYMENT_TYPE_DONATION
        ];
        if (!in_array($queuedPayment->getType(), $validPaymentTypes)) {
            throw new \Exception('Invalid or unsupported payment type "' . $queuedPayment->getType() . '"');
        }

        // Initialize the payment manager with the correct journal context.
        $paymentManager = new OJSPaymentManager($journal);

        // Fulfill the queued payment based on its type.
        // This logic is replicated from OJSPaymentManager::fulfillQueuedPayment
        // to avoid issues with missing user sessions in a webhook context.
        $this->fulfillQueuedPayment($journal, $queuedPayment);

        // Create a CompletedPayment record.
        $completedPayment = $paymentManager->createCompletedPayment($queuedPayment, $this->_plugin->getName(), null);

        $completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
        $completedPaymentDao->insertObject($completedPayment);

        // Delete the now-fulfilled QueuedPayment.
        $queuedPaymentDao->deleteById($queuedPayment->getId());
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
        switch ($queuedPayment->getType()) {
            case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
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
                break;
            case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
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
                    throw new \Exception('Subscription renewal integrity checks fail!');
                }
                break;
            case PAYMENT_TYPE_PUBLICATION:
            case PAYMENT_TYPE_PURCHASE_ARTICLE:
            case PAYMENT_TYPE_PURCHASE_ISSUE:
            case PAYMENT_TYPE_MEMBERSHIP: // Deprecated but supported
            case PAYMENT_TYPE_DONATION: // Deprecated but supported
                // For these types, creating the CompletedPayment record is sufficient.
                break;
            default:
                error_log('Xendit DEBUG: Invalid payment type: ' . $queuedPayment->getType());
                throw new \Exception('Invalid payment type "' . $queuedPayment->getType() . '"');
        }
    }
}