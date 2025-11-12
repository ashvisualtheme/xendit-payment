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
		error_log('Xendit DEBUG (FORM): display() called. Starting payment form process.');
		$journal = $request->getJournal();
		$paymentManager = Application::getPaymentManager($journal);
		
		$client = new Client();
		$apiKey = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'apiKey');
		// Masking API key for logs
		error_log('Xendit DEBUG (FORM): API Key loaded: ' . substr($apiKey, 0, 8) . '...'); 
		
		$host = 'https://api.xendit.co';
		$headers = [
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'OJS-Xendit-Plugin/1.0',
			'Authorization' => 'Basic ' . base64_encode($apiKey . ':')
		];

		try {
			$successUrl = $this->_queuedPayment->getRequestUrl();
			$failureUrl = $request->url(null, 'index');
			error_log('Xendit DEBUG (FORM): SuccessURL=' . $successUrl . ', FailureURL=' . $failureUrl);

			// Get OJS User Data.
			// For publication fees, the user should be the primary author of the submission,
			// not necessarily the user who is currently logged in (e.g., an admin).
			$paymentType = $this->_queuedPayment->getType();
			$userDao = DAORegistry::getDAO('UserDAO');
			$customerData = [];
			$payerEmail = '';
			$user = null; // Definisikan $user di sini

			error_log('Xendit DEBUG (FORM): PaymentType: ' . $paymentType . '. Finding payer details...');
			// LOG PELACAKAN BARU
			error_log('Xendit TRACE (USERID): Initial QueuedPayment UserID (Payer): ' . $this->_queuedPayment->getUserId());

			if ($paymentType == PAYMENT_TYPE_PUBLICATION) {
				error_log('Xendit DEBUG (FORM): Payment type is PUBLICATION. Looking for submission author.');
				$submissionId = $this->_queuedPayment->getAssocId();
				$submissionDao = DAORegistry::getDAO('SubmissionDAO');
				/** @var Submission */
				$submission = $submissionDao->getById($submissionId); // $assocId is the submission ID
				if (!$submission) {
					error_log('Xendit DEBUG (FORM): ERROR: Submission (ID: ' . $submissionId . ') not found!');
					throw new \Exception("Submission (ID: {$submissionId}) not found for publication payment.");
				}

				/** @var Author */
				$primaryAuthor = $submission->getPrimaryAuthor();
				if (!$primaryAuthor) {
					error_log('Xendit DEBUG (FORM): Primary author not found. Trying first author.');
					$authors = $submission->getAuthors();
					$primaryAuthor = $authors[0] ?? null;
				}

				if (!$primaryAuthor) {
					error_log('Xendit DEBUG (FORM): ERROR: No author found for submission ID: ' . $submissionId);
					throw new \Exception("Primary author not found for submission ID: {$submissionId}.");
				}

				$locale = AppLocale::getLocale();
				$givenName = $primaryAuthor->getGivenName($locale);
				$familyName = $primaryAuthor->getFamilyName($locale);
				$payerEmail = $primaryAuthor->getEmail();

				error_log('Xendit DEBUG (FORM): Payer identified (Author): ' . $givenName . ' ' . $familyName . ' (Email: ' . $payerEmail . ')');
				
				// ===== PERBAIKAN: START (Mengganti getByEmail() dengan getUserEmail()) =====
				$authorEmail = $primaryAuthor->getEmail();
				$authorUserId = null;
				if ($authorEmail) {
					// $userDao sudah di-load di atas
					// PERBAIKAN DI BARIS BERIKUTNYA:
					$authorUser = $userDao->getUserByEmail($authorEmail); // <-- Menggunakan getUserEmail()
					
					if ($authorUser) {
						$authorUserId = $authorUser->getId(); // Dapatkan UserID jika user-nya ada
					}
				}
				
				error_log('Xendit TRACE (USERID): Found Author. Email: ' . ($authorEmail ? $authorEmail : 'NULL') . '. Corresponding UserID: ' . ($authorUserId ? $authorUserId : 'NULL'));
				
				if ($authorUserId && $authorUserId != $this->_queuedPayment->getUserId()) {
					error_log('Xendit TRACE (USERID): UPDATING QueuedPayment ' . $this->_queuedPayment->getId() . ' from ' . $this->_queuedPayment->getUserId() . ' to Author UserID ' . $authorUserId);
					
					// Update objek di memori
					$this->_queuedPayment->setUserId($authorUserId); 
					
					// Update objek di database agar perubahan ini permanen
					$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
					$queuedPaymentDao->updateObject($this->_queuedPayment->getId(), $this->_queuedPayment);
					
					error_log('Xendit TRACE (USERID): QueuedPayment UserID updated in database.');
				} else if ($authorUserId) {
					error_log('Xendit TRACE (USERID): Author UserID (' . $authorUserId . ') matches Payer UserID. No update needed.');
				} else {
					error_log('Xendit TRACE (USERID): Author has no UserID account (email not found in users table). Payment will be associated with Payer UserID (' . $this->_queuedPayment->getUserId() . ').');
				}
				// ===== PERBAIKAN: END =====


				$customerData = [
					'reference_id' => 'OJS_AUTHOR_' . $primaryAuthor->getId(),
					'type' => 'INDIVIDUAL',
					'individual_detail' => [
						'given_names' => $givenName,
						'surname' => $familyName ? $familyName : $givenName
					],
					'email' => $payerEmail,
					'addresses' => [[
						'country' => $primaryAuthor->getCountry(),
					]]
				];
			}

			// For other payment types, or if the above logic didn't set $user (which it should for publication payments),
			// use the user who initiated the queued payment.
			if (empty($user)) { // Use empty() to also catch null or 0
				error_log('Xendit DEBUG (FORM): User not set from author logic. Getting user from QueuedPayment (UserID: ' . $this->_queuedPayment->getUserId() . ').');
				$user = $userDao->getById($this->_queuedPayment->getUserId());
			}
			
			// If customer data was not populated from author details, populate it from the user who initiated the payment.
			if (empty($customerData)) {
				if (!$user) {
					error_log('Xendit DEBUG (FORM): ERROR: User not found for this payment (ID: ' . $this->_queuedPayment->getUserId() . ').');
					throw new \Exception('User not found for this payment (ID: ' . $this->_queuedPayment->getUserId() . ').');
				}
				
				$locale = AppLocale::getLocale();
				$givenName = $user->getGivenName($locale);
				$familyName = $user->getFamilyName($locale);
				$payerEmail = $user->getEmail();
				
				error_log('Xendit DEBUG (FORM): Payer identified (User): ' . $givenName . ' ' . $familyName . ' (Email: ' . $payerEmail . ')');

				$customerData = [
					'reference_id' => 'OJS_USER_' . $user->getId(),
					'type' => 'INDIVIDUAL',
					'individual_detail' => [
						'given_names' => $givenName,
						'surname' => $familyName ? $familyName : $givenName
					],
					'email' => $payerEmail,
					'addresses' => [[
						'country' => $user->getCountry(),
					]]
				];

			}

			$paymentDescription = strip_tags($paymentManager->getPaymentName($this->_queuedPayment));
			// Set the item name from the base description, ensuring it's clean.
			$itemName = rtrim($paymentDescription, ': ');

			$queuedPaymentId = $this->_queuedPayment->getId();
			$assocId = $this->_queuedPayment->getAssocId();
			$paymentType = $this->_queuedPayment->getType();
			$userId = $this->_queuedPayment->getUserId(); // Sekarang ini harusnya ID Author (jika ada)
			$externalId = (string) $queuedPaymentId;
			
			// LOG PELACAKAN BARU
			error_log('Xendit TRACE (USERID): UserID being used for external_id suffix: ' . $userId);
			error_log('Xendit DEBUG (FORM): Building external_id. Base qpId: ' . $queuedPaymentId);

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
			
			error_log('Xendit DEBUG (FORM): Final external_id: ' . $externalId);
			error_log('Xendit DEBUG (FORM): Final payment description: ' . $paymentDescription);

			// Check if an invoice already exists and is pending
			try {
				error_log('Xendit DEBUG (FORM): Checking for existing pending invoice with external_id: ' . $externalId);
				$response = $client->request('GET', $host . '/v2/invoices?external_id=' . urlencode($externalId), ['headers' => $headers]);
				$existingInvoices = json_decode($response->getBody()->getContents());
				error_log('Xendit DEBUG (FORM): GET /v2/invoices returned status: ' . $response->getStatusCode());

				if (!empty($existingInvoices)) {
					error_log('Xendit DEBUG (FORM): Found ' . count($existingInvoices) . ' existing invoice(s).');
					// Invoices are returned newest first. Check the first one.
					$latestInvoice = $existingInvoices[0];
					if ($latestInvoice->status === 'PENDING') {
						error_log('Xendit DEBUG (FORM): Found PENDING invoice: ' . $latestInvoice->id . '. Redirecting user to: ' . $latestInvoice->invoice_url);
						// Found an active invoice, redirect user to it
						$request->redirectUrl($latestInvoice->invoice_url);
						return; // Stop further execution
					}
					error_log('Xendit DEBUG (FORM): Existing invoice is NOT pending (Status: ' . $latestInvoice->status . '). Proceeding to create new invoice.');
				} else {
					error_log('Xendit DEBUG (FORM): No existing invoices found for this external_id.');
				}
			} catch (RequestException $e) {
				// A 404 Not Found error is expected if no invoice exists.
				// We can ignore it and proceed to create a new one.
				if (!$e->hasResponse() || $e->getResponse()->getStatusCode() !== 404) {
					// For other errors (e.g., auth), re-throw the exception.
					error_log('Xendit DEBUG (FORM): Guzzle exception (non-404) while checking for existing invoice: ' . $e->getMessage());
					throw $e;
				}
				error_log('Xendit DEBUG (FORM): GET /v2/invoices returned 404 (Not Found), which is expected. Proceeding to create invoice.');
			}

			$paymentAmount = (float) number_format($this->_queuedPayment->getAmount(), 2, '.', '');
			error_log('Xendit DEBUG (FORM): Payment amount formatted: ' . $paymentAmount . ' ' . $this->_queuedPayment->getCurrencyCode());

			// Get invoice duration from plugin settings (in days), convert to seconds
			$invoiceDurationDays = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'invoiceDuration') ?? 30;
			$invoiceDurationSeconds = (int) $invoiceDurationDays * 24 * 60 * 60;
			error_log('Xendit DEBUG (FORM): Invoice duration: ' . $invoiceDurationDays . ' days (' . $invoiceDurationSeconds . ' seconds).');

			$invoiceData = [
				'external_id' => $externalId, // Use the new deterministic ID
				'amount' => $paymentAmount,
				'currency' => $this->_queuedPayment->getCurrencyCode(),
				'payer_email' => $payerEmail,
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
				error_log('Xendit DEBUG (FORM): Adding notification channels: ' . implode(', ', $notificationChannels));
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

			error_log('Xendit DEBUG (FORM): Final InvoiceData payload (JSON): ' . json_encode($invoiceData));
			error_log('Xendit DEBUG (FORM): Sending POST request to /v2/invoices...');

			$response = $client->request('POST', $host . '/v2/invoices', [
				'headers' => $headers,
				'body' => json_encode($invoiceData)
			]);

			error_log('Xendit DEBUG (FORM): POST /v2/invoices returned status: ' . $response->getStatusCode());

			if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
				$resultBody = $response->getBody()->getContents(); // This is a new invoice
				$resultData = json_decode($resultBody);

				if ($resultData && isset($resultData->invoice_url)) {
					error_log('Xendit DEBUG (FORM): Invoice created successfully. Redirecting user to: ' . $resultData->invoice_url);
					$request->redirectUrl($resultData->invoice_url);
				} else {
					error_log('Xendit DEBUG (FORM): ERROR: Failed to get invoice_url from Xendit response. Response body: ' . $resultBody);
					throw new \Exception('Failed to get invoice_url from Xendit.');
				}
			} else {
				error_log('Xendit DEBUG (FORM): ERROR: Request to Xendit Invoice v2 failed with status: ' . $response->getStatusCode());
				throw new \Exception('Request to Xendit Invoice v2 failed with status: ' . $response->getStatusCode());
			}
			
		} catch (RequestException $e) {
			error_log('Xendit DEBUG (FORM): CRITICAL ERROR (Guzzle RequestException): ' . $e->getMessage());
			if ($e->hasResponse()) {
				$errorBody = $e->getResponse()->getBody()->getContents();
				error_log('Xendit DEBUG (FORM): Guzzle Response Body: ' . $errorBody);
				// Coba decode JSON jika ada, untuk error yang lebih jelas dari Xendit
				$errorJson = json_decode($errorBody);
				if (json_last_error() === JSON_ERROR_NONE && isset($errorJson->message)) {
					error_log('Xendit DEBUG (FORM): Xendit Error Message: ' . $errorJson->message);
				}
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		} catch (\Exception $e) {
			error_log('Xendit DEBUG (FORM): CRITICAL ERROR (Exception): ' . $e->getMessage());
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		}
	}
}