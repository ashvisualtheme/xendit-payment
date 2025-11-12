<?php

/**
 * @file plugins/paymethod/xendit/XenditPaymentPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class XenditPaymentPlugin
 * @ingroup plugins_paymethod_xendit
 *
 * @brief Xendit payment plugin class
 */

import('lib.pkp.classes.plugins.PaymethodPlugin');
require_once(dirname(__FILE__) . '/vendor/autoload.php');

class XenditPaymentPlugin extends PaymethodPlugin {

	/**
	 * @see Plugin::getName
	 */
	function getName() {
		return 'XenditPayment';
	}

	/**
	 * @see Plugin::getDisplayName
	 */
	function getDisplayName() {
		return __('plugins.paymethod.xendit.displayName');
	}

	/**
	 * @see Plugin::getDescription
	 */
	function getDescription() {
		return __('plugins.paymethod.xendit.description');
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		if (parent::register($category, $path, $mainContextId)) {
			$this->addLocaleData();
			\HookRegistry::register('Form::config::before', array($this, 'addSettings'));
			return true;
		}
		return false;
	}

	/**
	 * Define plugin settings in the OJS admin form.
	 */
	public function addSettings($hookName, $form) {
		import('lib.pkp.classes.components.forms.context.PKPPaymentSettingsForm');
		if ($form->id !== FORM_PAYMENT_SETTINGS) {
			return;
		}

		$context = Application::get()->getRequest()->getContext();
		if (!$context) {
			return;
		}

		$form->addGroup([
				'id' => 'xenditpayment',
				'label' => $this->getDisplayName(),
				'showWhen' => 'paymentsEnabled',
			])
			->addField(new \PKP\components\forms\FieldOptions('testMode', [
				'label' => __('plugins.paymethod.xendit.settings.testMode'),
				'options' => [
					['value' => true, 'label' => __('common.enable')]
				],
				'value' => (bool) $this->getSetting($context->getId(), 'testMode'),
				'groupId' => 'xenditpayment',
			]))
			// Replace clientId/secret with apiKey
			->addField(new \PKP\components\forms\FieldText('apiKey', [
				'label' => __('plugins.paymethod.xendit.settings.apiKey'),
				'description' => __('plugins.paymethod.xendit.settings.apiKey.description'),
				'value' => $this->getSetting($context->getId(), 'apiKey'),
				'groupId' => 'xenditpayment',
			]))
			// Add Webhook Secret for security
			->addField(new \PKP\components\forms\FieldText('webhookSecret', [
				'label' => __('plugins.paymethod.xendit.settings.webhookSecret'),
				'description' => __('plugins.paymethod.xendit.settings.webhookSecret.description'),
				'value' => $this->getSetting($context->getId(), 'webhookSecret'),
				'groupId' => 'xenditpayment',
			]))
			->addField(new \PKP\components\forms\FieldText('invoiceDuration', [
				'label' => __('plugins.paymethod.xendit.settings.invoiceDuration'),
				'description' => __('plugins.paymethod.xendit.settings.invoiceDuration.description'),
				'value' => $this->getSetting($context->getId(), 'invoiceDuration') ?? 30, // Default 30 hari
				'size' => 'small',
				'validation' => ['integer', 'min:1'],
				'groupId' => 'xenditpayment',
			]))
			->addField(new \PKP\components\forms\FieldOptions('notificationChannels', [
				'label' => __('plugins.paymethod.xendit.settings.notificationChannels'),
				'description' => __('plugins.paymethod.xendit.settings.notificationChannels.description'),
				'type' => 'checkbox',
				'options' => [
					['value' => 'email', 'label' => 'Email'],
					['value' => 'whatsapp', 'label' => 'WhatsApp'],
				],
				'value' => $this->getSetting($context->getId(), 'notificationChannels') ?? ['email'],
				'groupId' => 'xenditpayment',
			]));
	}

	/**
	 * Simpan pengaturan
	 */
	public function saveSettings($params, $slimRequest, $request) {
		$allParams = $slimRequest->getParsedBody();
		$saveParams = [];
		foreach ($allParams as $param => $val) {
			switch ($param) {
				case 'apiKey':
				case 'webhookSecret':
					$saveParams[$param] = (string) $val;
					break;
				case 'invoiceDuration':
					$saveParams[$param] = (int) $val;
					break;
				case 'testMode':
					$saveParams[$param] = $val === 'true';
					break;
				case 'notificationChannels':
					$saveParams[$param] = is_array($val) ? $val : [];
					break;
			}
		}
		$contextId = $request->getContext()->getId();
		foreach ($saveParams as $param => $val) {
			$this->updateSetting($contextId, $param, $val);
		}
		return [];
	}

	/**
	 * @copydoc PaymethodPlugin::getPaymentForm()
	 */
	function getPaymentForm($context, $queuedPayment) {
		$this->import('XenditPaymentForm');
		return new XenditPaymentForm($this, $queuedPayment);
	}

	/**
	 * @copydoc PaymethodPlugin::isConfigured
	 */
	function isConfigured($context) {
		if (!$context) return false;
		if ($this->getSetting($context->getId(), 'apiKey') == '') return false;
		return true;
	}

	/**
	 * Handle incoming requests from OJS.
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function handle($args, $request) {
		$op = isset($args[0]) ? $args[0] : null;
		switch ($op) {
			case 'webhook':
				return $this->webhook($args, $request);
			default:
				$request->getDispatcher()->handle404();
				return false;
		}
	}


	/**
	 * Handles incoming webhooks from Xendit.
	 */
	function webhook($args, $request) {
		$journal = $request->getJournal();
		if (!$journal) throw new \Exception('No journal context!');

		// 1. Get 'x-callback-token' from the header in a cross-server compatible way
		$callbackToken = null;
		if (isset($_SERVER['HTTP_X_CALLBACK_TOKEN'])) { // Standard for FastCGI/Nginx
			$callbackToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'];
		} elseif (function_exists('getallheaders')) { // Standard for Apache
			$headers = getallheaders();
			$headers = array_change_key_case($headers, CASE_LOWER);
			if (isset($headers['x-callback-token'])) {
				$callbackToken = $headers['x-callback-token'];
			}
		} else { // Fallback for other server configurations
			foreach ($_SERVER as $key => $value) {
				if (strtolower($key) === 'http_x_callback_token') {
					$callbackToken = $value;
					break;
				}
			}
		}
		
		// 2. Get the secret token from settings
		$webhookSecret = $this->getSetting($journal->getId(), 'webhookSecret');

		// 3. Validate the token
		if (!$webhookSecret || !hash_equals($webhookSecret, (string)$callbackToken)) {
			$logMessage = $callbackToken ? 'Token mismatch' : 'Token not received';
			// Add extensive debugging for 'Token not received' to diagnose server configuration issues.
			if (!$callbackToken) {
				$availableHeaders = 'getallheaders() not available.';
				if (function_exists('getallheaders')) {
					$availableHeaders = json_encode(getallheaders());
				} else {
					$serverHeaders = [];
					foreach ($_SERVER as $key => $value) {
						if (strpos($key, 'HTTP_') === 0) $serverHeaders[$key] = $value;
					}
					$availableHeaders = json_encode($serverHeaders);
				}
				error_log('Xendit webhook validation failed: ' . $logMessage . '. Available headers: ' . $availableHeaders);
			} else {
				error_log('Xendit webhook validation failed: ' . $logMessage);
			}
			header('HTTP/1.1 401 Unauthorized');
			return;
		}

		// 4. Get JSON data from the body
		$jsonPayload = file_get_contents('php://input');
		$data = json_decode($jsonPayload);

		if (!$data) { 
			error_log('Xendit webhook error: Invalid payload');
			header('HTTP/1.1 400 Bad Request');
			return;
		}

		// 5. Check for a successful payment event
		$paymentSuccess = false;
		$paymentId = null;

		// Handle the modern 'invoice.paid' event from the v2 Invoice API.
		// The plugin uses the v2 Invoice API, so this is the primary event to check.
		if (isset($data->event) && $data->event === 'invoice.paid') {
			// The actual invoice data is nested inside the 'data' property
			$invoiceData = $data->data;
			if (isset($invoiceData->status) && $invoiceData->status === 'PAID' && isset($invoiceData->external_id)) {
				$paymentSuccess = true;
				$paymentId = $invoiceData->external_id;
			}
		// Handle the 'payment.succeeded' event from other Xendit products (e.g., Payment Request).
		} else if (isset($data->event) && $data->event === 'payment.succeeded') {
			$paymentData = $data->data;
			// For 'payment.succeeded', the OJS payment ID is stored in the 'external_id' of the invoice,
			// which is available in the payment method's metadata or channel properties.
			if (isset($paymentData->status) && $paymentData->status === 'SUCCEEDED' && isset($paymentData->payment_method->channel_properties->external_id)) {
				$paymentSuccess = true;
				$paymentId = $paymentData->payment_method->channel_properties->external_id;
			}
		} else if (isset($data->status) && $data->status === 'PAID') { // Fallback for older webhook formats
			if (isset($data->external_id)) {
				$paymentSuccess = true;
				$paymentId = $data->external_id;
			}
		}

		// 6. Process the payment if successful
		if ($paymentSuccess && $paymentId) {
			try {
				$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
				import('classes.payment.ojs.OJSPaymentManager');

				// Add a check to ensure the external_id format is what we expect from this plugin.
				// This prevents errors from test webhooks sent from the Xendit dashboard.
				if (strpos($paymentId, 'OJS-') !== 0) {
					// Not a payment initiated by this plugin, ignore it.
					throw new \Exception("Ignoring webhook with non-OJS external_id: $paymentId");
				}

				// The $paymentId from the webhook is the external_id we sent.
				// The format is "OJS-{journalPath}-{typePrefix}-{queuedPaymentId}".
				// We need to reliably extract the last part, which is the queuedPaymentId.
				$idParts = explode('-', $paymentId);
				$queuedPaymentId = end($idParts);

				// Validate that the extracted part is a numeric ID.
				if (!ctype_digit((string)$queuedPaymentId)) {
					throw new \Exception("Could not parse a valid numeric queued_payment_id from external_id: $paymentId");
				}

				/** @var QueuedPayment */
				$queuedPayment = $queuedPaymentDao->getById((int)$queuedPaymentId);

				if (!$queuedPayment) {
					throw new \Exception("No queued payment found for ID derived from external_id: $paymentId");
				}

				// Get the journal context from the queued payment to ensure it's correctly loaded,
				// as webhook requests might not have a journal in the request context.
				$contextId = $queuedPayment->getContextId();
				$journalDao = DAORegistry::getDAO('JournalDAO');
				$journal = $journalDao->getById($contextId);
				if (!$journal) {
					throw new \Exception("Could not load journal context for QueuedPayment ID: $queuedPaymentId");
				}

				// Initialize the payment manager within this scope with the correct journal context.
				$paymentManager = new OJSPaymentManager($journal);

				// Mark the payment as completed
				// Pass null for the request object, as we are in a stateless webhook context.
				// The payment manager will use the context from the QueuedPayment object.
				$paymentManager->fulfillQueuedPayment(null, $queuedPayment, $this->getName());

				// Respond to Xendit with a 200 OK status
				echo 'Webhook received';
				header('HTTP/1.1 200 OK');
				return;

			} catch (\Exception $e) {
				// Log the exception message.
				error_log('Xendit webhook processing error: ' . $e->getMessage());

				// If the exception is for ignoring a webhook, we should return a 200 OK
				// to prevent Xendit from retrying. For all other errors, return 500.
				if (strpos($e->getMessage(), 'Ignoring webhook') === 0) {
					echo 'Webhook acknowledged but ignored as irrelevant.';
					header('HTTP/1.1 200 OK');
				} else {
					header('HTTP/1.1 500 Internal Server Error');
				}
				return;
			}
		}
		
		// If it's not an event we're looking for, still respond with 200 OK
		echo 'Webhook acknowledged (Event not processed)';
		header('HTTP/1.1 200 OK');
		return;
	}

	/**
	 * @see Plugin::getInstallEmailTemplatesFile
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . DIRECTORY_SEPARATOR . 'emailTemplates.xml');
	}
}
