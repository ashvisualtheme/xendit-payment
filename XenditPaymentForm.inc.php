<?php

/**
 * @file XenditPaymentForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class XenditPaymentForm
 *
 * Form for Xendit-based payments.
 *
 */

import('lib.pkp.classes.form.Form');
import('classes.i18n.AppLocale');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class XenditPaymentForm extends Form {
	/** @var XenditPaymentPlugin */
	var $_xenditPaymentPlugin;

	/** @var QueuedPayment */
	var $_queuedPayment;

	function __construct($xenditPaymentPlugin, $queuedPayment) {
		$this->_xenditPaymentPlugin = $xenditPaymentPlugin;
		$this->_queuedPayment = $queuedPayment;
		parent::__construct(null);
	}

	function display($request = null, $template = null) {
		$journal = $request->getJournal();
		$paymentManager = Application::getPaymentManager($journal);
		
		try {
			// 1. Get Credentials
			$apiKey = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'apiKey');
			$host = 'https://api.xendit.co';

			// 2. Prepare Redirect URLs
			$webhookUrl = $request->url(null, 'payment', 'plugin', array($this->_xenditPaymentPlugin->getName(), 'return'));
			$successUrl = $this->_queuedPayment->getRequestUrl();
			$failureUrl = $request->url(null, 'index');

			// Get OJS User Data
			$userDao = DAORegistry::getDAO('UserDAO');
			$user = $userDao->getById($this->_queuedPayment->getUserId());
			if (!$user) {
				throw new \Exception('User not found for this payment (ID: ' . $this->_queuedPayment->getUserId() . ').');
			}

			$locale = AppLocale::getLocale();
			$givenName = $user->getGivenName($locale);
			$familyName = $user->getFamilyName($locale);
			$phone = $user->getPhone();
			// Create customer object
			$customerData = [
				'reference_id' => 'OJS_USER_' . $user->getId(),
				'type' => 'INDIVIDUAL',
				'individual_detail' => [
					'given_names' => $givenName,
					'surname' => $familyName ? $familyName : $givenName // Fallback to givenName if familyName is empty
				],
				'email' => $user->getEmail(),
			];

			// Add mobile number if available
			if (!empty($phone)) {
				// Clean the phone number to include only digits and the leading '+'
				$cleanedPhone = preg_replace('/[^\d+]/', '', $phone);
				$customerData['mobile_number'] = $cleanedPhone;
			}

			// 3. Prepare Invoice Data for v2 API
			$paymentDescription = strip_tags($paymentManager->getPaymentName($this->_queuedPayment));
			$paymentAmount = (float) number_format($this->_queuedPayment->getAmount(), 2, '.', '');
			$itemName = $paymentDescription; // Short description for item name

			// Use a switch statement for cleaner logic and extensibility
			$externalId = (string) $this->_queuedPayment->getId(); // Default external_id
			$assocId = $this->_queuedPayment->getAssocId();
			
			switch ($this->_queuedPayment->getType()) {
				case PAYMENT_TYPE_PUBLICATION:
				case PAYMENT_TYPE_PURCHASE_ARTICLE:
					$externalId = 'Article-' . $assocId;
					$submissionDao = DAORegistry::getDAO('SubmissionDAO');
					/** @var Submission */
					$submission = $submissionDao->getById($assocId);
					if ($submission) {
						$submissionTitle = $submission->getLocalizedTitle();
						$paymentDescription .= ': ' . strip_tags($submissionTitle);
					}
					break;
				
				case PAYMENT_TYPE_PURCHASE_ISSUE:
					$externalId = 'Issue-' . $assocId;
					$issueDao = DAORegistry::getDAO('IssueDAO');
					/** @var Issue */
					$issue = $issueDao->getById($assocId);
					if ($issue) {
						$issueTitle = $issue->getLocalizedTitle();
						if (empty($issueTitle)) {
							$issueTitle = $issue->getIssueIdentification();
						}
						$paymentDescription .= ': ' . strip_tags($issueTitle);
					}
					break;
				
				case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
				case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
					$externalId = 'Subscription-' . $assocId;
					// The description from getPaymentName is already detailed enough
					// (e.g., "Purchase Subscription (Subscription Type Name)")
					break;

				case PAYMENT_TYPE_MEMBERSHIP:
					// assocId is not consistently used for membership, so we use the user ID for a unique reference.
					$externalId = 'Membership-' . $this->_queuedPayment->getUserId();
					break;

				case PAYMENT_TYPE_DONATION:
					$externalId = 'Donation-' . $this->_queuedPayment->getId();
					break;
				
				default:
					// Fallback for any other payment types. The default externalId (queued_payment_id) is used.
					break;
			}

			// Get invoice duration from plugin settings (in days), convert to seconds
			$invoiceDurationDays = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'invoiceDuration') ?? 30;
			$invoiceDurationSeconds = (int) $invoiceDurationDays * 24 * 60 * 60;

			$invoiceData = [
				'external_id' => $externalId,
				'amount' => $paymentAmount,
				'currency' => $this->_queuedPayment->getCurrencyCode(),
				'payer_email' => $user->getEmail(),
				'description' => $paymentDescription,
				'customer' => $customerData,
				'success_redirect_url' => $successUrl,
				'failure_redirect_url' => $failureUrl,
				'invoice_duration' => $invoiceDurationSeconds,
				// Add item details for a better display on the Xendit invoice
				'items' => [[
					'name' => $itemName,
					'quantity' => 1,
					'price' => $paymentAmount
				]]
			];

			// 4. Add Customer Notification Preferences from plugin settings
			$notificationChannels = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'notificationChannels');
			if (!empty($notificationChannels)) {
				$invoiceData['customer_notification_preference'] = [
					// Apply selected channels to all relevant invoice events
					'invoice_created' => $notificationChannels,
					'invoice_reminder' => $notificationChannels,
					'invoice_paid' => $notificationChannels
				];
			}

			// 5. Create Guzzle Client headers for v2 API
			$headers = [
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'OJS-Xendit-Plugin/1.2',
				'Authorization' => 'Basic ' . base64_encode($apiKey . ':') 
			];

			// 6. Create Guzzle Client and send the request
			$client = new Client();
			$response = $client->request('POST', $host . '/v2/invoices', [
				'headers' => $headers,
				'body' => json_encode($invoiceData)
			]);

			// 7. Process the response
			if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
				$resultBody = $response->getBody()->getContents();
				$resultData = json_decode($resultBody);

				if ($resultData && isset($resultData->invoice_url)) {
					$request->redirectUrl($resultData->invoice_url);
				} else {
					throw new \Exception('Failed to get invoice_url from Xendit.');
				}
			} else {
				throw new \Exception('Request to Xendit Invoice v2 failed with status: ' . $response->getStatusCode());
			}
			
		} catch (RequestException $e) {
			error_log('Xendit Guzzle Invoice v2 exception: ' . $e->getMessage());
			if ($e->hasResponse()) {
				error_log('Guzzle Response Body v2: ' . $e->getResponse()->getBody()->getContents());
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		} catch (\Exception $e) {
			error_log('Xendit transaction Invoice v2 exception: ' . $e->getMessage());
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		}
	}
}