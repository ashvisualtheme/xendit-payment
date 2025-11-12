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
		
		$client = new Client();
		$apiKey = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'apiKey');
		$host = 'https://api.xendit.co';
		$headers = [
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'OJS-Xendit-Plugin/1.3.0', // Version bump for new logic
			'Authorization' => 'Basic ' . base64_encode($apiKey . ':') 
		];

		try {
			// 1. Prepare Redirect URLs
			$successUrl = $this->_queuedPayment->getRequestUrl();
			$failureUrl = $request->url(null, 'index');

			// Get OJS User Data
			$userDao = DAORegistry::getDAO('UserDAO');
			$user = $userDao->getById($this->_queuedPayment->getUserId());
			if (!$user) {
				throw new \Exception('User not found for this payment (ID: ' . $this->_queuedPayment->getUserId() . ').');
			}

			$locale = AppLocale::getLocale(); // 2. Get User Data
			$givenName = $user->getGivenName($locale);
			$familyName = $user->getFamilyName($locale);
			$phone = $user->getPhone();
			$country = $user->getCountry();
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

			// Add address if country is available
			if (!empty($country)) {
				$customerData['addresses'] = [[
					'country' => $country,
					'street_line1' => $user->getMailingAddress(),
					// OJS doesn't have separate fields for city, state, postal_code by default in user profile.
					// This can be extended if custom fields are used.
				]];
			}

			// 3. Generate a deterministic and unique external_id for the OJS payment
			$queuedPaymentId = $this->_queuedPayment->getId();
			$journalPath = $journal->getPath();
			$assocId = $this->_queuedPayment->getAssocId();
			$paymentType = $this->_queuedPayment->getType();
			
			// Create a prefix based on payment type for better identification in Xendit dashboard
			$typePrefix = 'QP'; // Default QueuedPayment
			switch ($paymentType) {
				case PAYMENT_TYPE_PUBLICATION:
				case PAYMENT_TYPE_PURCHASE_ARTICLE:
					$typePrefix = 'ART'; break;
				case PAYMENT_TYPE_PURCHASE_ISSUE:
					$typePrefix = 'ISS'; break;
				case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
				case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
					$typePrefix = 'SUB'; break;
				case PAYMENT_TYPE_MEMBERSHIP:
					$typePrefix = 'MEM'; break;
				case PAYMENT_TYPE_DONATION:
					$typePrefix = 'DON'; break;
			}
			$externalId = "OJS-{$journalPath}-{$typePrefix}-{$queuedPaymentId}";

			// 4. Check if an invoice already exists and is pending
			try {
				$response = $client->request('GET', $host . '/v2/invoices?external_id=' . urlencode($externalId), ['headers' => $headers]);
				$existingInvoices = json_decode($response->getBody()->getContents());

				if (!empty($existingInvoices)) {
					// Invoices are returned newest first. Check the first one.
					$latestInvoice = $existingInvoices[0];
					if ($latestInvoice->status === 'PENDING') {
						// Found an active invoice, redirect user to it
						$request->redirectUrl($latestInvoice->invoice_url);
						return; // Stop further execution
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

			// 5. Prepare data for new invoice creation
			$paymentDescription = strip_tags($paymentManager->getPaymentName($this->_queuedPayment));
			$paymentAmount = (float) number_format($this->_queuedPayment->getAmount(), 2, '.', '');
			$itemName = $paymentDescription;

			// Get invoice duration from plugin settings (in days), convert to seconds
			$invoiceDurationDays = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'invoiceDuration') ?? 30;
			$invoiceDurationSeconds = (int) $invoiceDurationDays * 24 * 60 * 60;

			$invoiceData = [
				'external_id' => $externalId, // Use the new deterministic ID
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
					'price' => $paymentAmount,
					'category' => 'Digital Product',
					'url' => $request->getRequestUrl()
				]]
			];

			// 6. Add Customer Notification Preferences from plugin settings
			$notificationChannels = $this->_xenditPaymentPlugin->getSetting($journal->getId(), 'notificationChannels');
			if (!empty($notificationChannels)) {
				$invoiceData['customer_notification_preference'] = [
					// Apply selected channels to all relevant invoice events
					'invoice_created' => $notificationChannels,
					'invoice_reminder' => $notificationChannels,
					'invoice_paid' => $notificationChannels
				];
			}

			// 7. Send the request to create a new invoice
			$response = $client->request('POST', $host . '/v2/invoices', [
				'headers' => $headers,
				'body' => json_encode($invoiceData)
			]);

			// 7. Process the response
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
			error_log('Xendit Guzzle Invoice exception: ' . $e->getMessage());
			if ($e->hasResponse()) {
				error_log('Guzzle Response Body: ' . $e->getResponse()->getBody()->getContents());
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		} catch (\Exception $e) {
			error_log('Xendit transaction Invoice exception: ' . $e->getMessage());
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('message', 'plugins.paymethod.xendit.error');
			$templateMgr->display('frontend/pages/message.tpl');
		}
	}
}