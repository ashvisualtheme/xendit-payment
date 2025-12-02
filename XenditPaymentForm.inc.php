<?php

/**
 * @file XenditPaymentForm.inc.php
 *
 * Copyright (c) 2025 AshVisual Theme
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
		
		$client = new Client();
		$apiKey = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'apiKey');
		
		$host = 'https://api.xendit.co';
		$headers = [
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'OJS-Xendit-Plugin/1.0.0.0 (OJS ' . Application::get()->getCurrentVersion()->getVersionString() . ')',
			'Authorization' => 'Basic ' . base64_encode($apiKey . ':')
		];

		try {
			$successUrl = $this->_queuedPayment->getRequestUrl();
			$failureUrl = $request->url(null, 'index');

			// Get OJS User Data.
			// For publication fees, the user should be the primary author of the submission,
			// not necessarily the user who is currently logged in (e.g., an admin).
			$paymentType = $this->_queuedPayment->getType();
			$userDao = DAORegistry::getDAO('UserDAO');
			$customerData = []; // Initialize customer data
			$payerEmail = '';
			$user = null;

			if ($paymentType == PAYMENT_TYPE_PUBLICATION) {
				$submissionId = $this->_queuedPayment->getAssocId();
				$submissionDao = DAORegistry::getDAO('SubmissionDAO');
				/** @var Submission */
				$submission = $submissionDao->getById($submissionId); // $assocId is the submission ID
				if (!$submission) {
					throw new \Exception("Submission (ID: {$submissionId}) not found for publication payment.");
				}

				/** @var Author */
				$primaryAuthor = $submission->getPrimaryAuthor();
				if (!$primaryAuthor) {
					$authors = $submission->getAuthors();
					$primaryAuthor = $authors[0] ?? null;
				}

				if (!$primaryAuthor) {
					throw new \Exception("Primary author not found for submission ID: {$submissionId}.");
				}

				$locale = AppLocale::getLocale();
				$givenName = $primaryAuthor->getGivenName($locale);
				$familyName = $primaryAuthor->getFamilyName($locale);
				$payerEmail = $primaryAuthor->getEmail();

				$authorEmail = $primaryAuthor->getEmail();
				$authorUserId = null;
				if ($authorEmail) {
					// UserDAO is already loaded above
					$authorUser = $userDao->getUserByEmail($authorEmail);
					
					if ($authorUser) {
						$authorUserId = $authorUser->getId(); // Get UserID if the user exists
					}
				}
								
				if ($authorUserId && $authorUserId != $this->_queuedPayment->getUserId()) {
					// Update the object in memory
					$this->_queuedPayment->setUserId($authorUserId);
					// Persist the changes to the database
					$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
					$queuedPaymentDao->updateObject($this->_queuedPayment->getId(), $this->_queuedPayment);
				}

				$mobileNumber = method_exists($primaryAuthor, 'getPhone') ? $primaryAuthor->getPhone() : '-';

				$customerData = [
					'given_names' => $givenName,
					'surname' => $familyName ? $familyName : $givenName,
					'email' => $payerEmail,
				];

				if (!empty($mobileNumber)) {
					$customerData['mobile_number'] = $mobileNumber;
				}

				$country = $primaryAuthor->getCountry();
				$affiliation = $primaryAuthor->getAffiliation($locale);

				$address = ['country' => $country];
				if (!empty($affiliation)) {
					$address['street_line1'] = $affiliation;
				}
				$customerData['addresses'] = [$address];

			}

			// For other payment types, or if the above logic didn't set $user (which it should for publication payments),
			// use the user who initiated the queued payment.
			if (empty($user)) { // Use empty() to also catch null or 0
				$user = $userDao->getById($this->_queuedPayment->getUserId());
			}
			
			// If customer data was not populated from author details, populate it from the user who initiated the payment.
			if (empty($customerData)) {
				if (!$user) {
					throw new \Exception('User not found for this payment (ID: ' . $this->_queuedPayment->getUserId() . ').');
				}
				
				$locale = AppLocale::getLocale();
				$givenName = $user->getGivenName($locale);
				$familyName = $user->getFamilyName($locale);
				$payerEmail = $user->getEmail();

				$mobileNumber = $user->getPhone() ?? '';

				$customerData = [
					'given_names' => $givenName,
					'surname' => $familyName ? $familyName : $givenName,
					'email' => $payerEmail,
				];

				if (!empty($mobileNumber)) {
					$customerData['mobile_number'] = $mobileNumber;
				}

				$country = $user->getCountry();
				$affiliation = $user->getAffiliation($locale);

				$address = ['country' => $country];
				if (!empty($affiliation)) {
					$address['street_line1'] = $affiliation;
				}
				$customerData['addresses'] = [$address];
			}

			$paymentDescription = strip_tags($paymentManager->getPaymentName($this->_queuedPayment));
			// Set the item name from the base description, ensuring it's clean.
			$itemName = rtrim($paymentDescription, ': ');

			$queuedPaymentId = $this->_queuedPayment->getId();
			$assocId = $this->_queuedPayment->getAssocId();
			$paymentType = $this->_queuedPayment->getType();
			$userId = $this->_queuedPayment->getUserId(); // This should now be the Author ID (if applicable)
			$externalId = (string) $queuedPaymentId;
			
			switch ($paymentType) {
				case PAYMENT_TYPE_PUBLICATION:
				case PAYMENT_TYPE_PURCHASE_ARTICLE:
					$externalId .= "-ART-{$assocId}";
					$submissionDao = DAORegistry::getDAO('SubmissionDAO');
					/** @var Submission */
					$submission = $submissionDao->getById($assocId);
					if ($submission) {
						$paymentDescription .= ': ' . $submission->getLocalizedTitle();
					}
					break;
				case PAYMENT_TYPE_PURCHASE_ISSUE:
					$externalId .= "-ISS-{$assocId}";
					$issueDao = DAORegistry::getDAO('IssueDAO');
					/** @var Issue */
					$issue = $issueDao->getById($assocId);
					if ($issue) {
						$paymentDescription .= ': ' . $issue->getLocalizedTitle();
					}
					break;
				case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
				case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
					$externalId .= "-SUB-{$userId}";
					break;
				case PAYMENT_TYPE_MEMBERSHIP:
					$externalId .= "-MEM-{$userId}";
					break;
				case PAYMENT_TYPE_DONATION:
					$externalId .= "-DON-{$userId}";
					break;
				default:
					// Fallback for other or future payment types
					$externalId .= "-GEN-{$assocId}";
					break;
			}
			
			// Check if an invoice already exists and is pending
			try {
				$checkResponse = $client->request('GET', $host . '/v2/invoices?external_id=' . urlencode($externalId), ['headers' => $headers]);
				$existingInvoices = json_decode($checkResponse->getBody()->getContents());

				if (!empty($existingInvoices) && is_array($existingInvoices)) {
					// Invoices are returned newest first. Check the first one.
					foreach ($existingInvoices as $inv) {
						if ($inv->status === 'PENDING') {
							// Found an active invoice, redirect user to it
							$request->redirectUrl($inv->invoice_url);
							return; // Stop further execution
						}
					}
				}
			} catch (RequestException $e) {
				// A 404 Not Found error is expected if no invoice exists.
				// We can ignore it and proceed to create a new one.
				if (!$e->hasResponse() || $e->getResponse()->getStatusCode() !== 404) {
					// For other errors (e.g., auth), re-throw the exception.
					throw $e;
				}
			}

			$paymentAmount = (float) number_format($this->_queuedPayment->getAmount(), 2, '.', '');

			// Get invoice duration from plugin settings (in days), convert to seconds
			$invoiceDurationDays = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'invoiceDuration') ?? 30;
			$invoiceDurationSeconds = (int) $invoiceDurationDays * 24 * 60 * 60;

			$invoiceData = [
				'external_id' => $externalId, // Use the new deterministic ID
				'amount' => $paymentAmount,
				'currency' => $this->_queuedPayment->getCurrencyCode(),
				'description' => $paymentDescription,
				'customer' => $customerData,
				'success_redirect_url' => $successUrl,
				'failure_redirect_url' => $failureUrl,
				'invoice_duration' => $invoiceDurationSeconds,
				// Add item details for a better display on the Xendit invoice
				'items' => [[
					'name' => $itemName,
					'quantity' => 1,
					'price' => $paymentAmount,
					'category' => 'Digital Product',
					'url' => $request->getRequestUrl()
				]]
			];

			$notificationChannels = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'notificationChannels');
			if (!empty($notificationChannels)) {
				$invoiceData['customer_notification_preference'] = [
					// Apply selected channels to all relevant invoice events
					'invoice_created' => $notificationChannels,
					'invoice_reminder' => $notificationChannels,
					'invoice_paid' => $notificationChannels
				];
			}
			
			// Add OJS-specific context as metadata for easier debugging and reconciliation
			$invoiceData['metadata'] = [
				'ojs_version' => Application::get()->getCurrentVersion()->getVersionString(),
				'plugin_version' => $this->_xenditPaymentPlugin->getCurrentVersion()->getVersionString(),
				'ojs_queued_payment_id' => $queuedPaymentId,
				'ojs_context_id' => $journal->getId(),
				'ojs_payment_type' => $paymentType,
			];

			$response = $client->request('POST', $host . '/v2/invoices', [
				'headers' => $headers,
				'body' => json_encode($invoiceData)
			]);

			if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
				$resultBody = $response->getBody()->getContents(); // This is a new invoice
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
			if ($e->hasResponse()) {
				$errorBody = $e->getResponse()->getBody()->getContents();
				// Attempt to decode JSON for more specific Xendit error messages
				$errorJson = json_decode($errorBody);
				// if (json_last_error() === JSON_ERROR_NONE && isset($errorJson->message)) {
				// 	error_log('Xendit DEBUG (FORM): Xendit Error Message: ' . $errorJson->message);
				// }
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		} catch (\Exception $e) {
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		}
	}
}