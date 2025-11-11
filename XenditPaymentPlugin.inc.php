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
	 * It now handles WEBHOOKS from Xendit.
	 */
	function handle($args, $request) {
		$journal = $request->getJournal();
		if (!$journal) throw new \Exception('No journal context!');

		// 1. Get 'x-callback-token' from the header
		$headers = getallheaders();
		$callbackToken = null;
		if (isset($headers['x-callback-token'])) {
			$callbackToken = $headers['x-callback-token'];
		} else if (isset($headers['X-Callback-Token'])) {
			// Some servers change the capitalization
			$callbackToken = $headers['X-Callback-Token'];
		}
		
		// 2. Get the secret token from settings
		$webhookSecret = $this->getSetting($journal->getId(), 'webhookSecret');

		// 3. Validate the token
		if (!$webhookSecret || !hash_equals($webhookSecret, (string)$callbackToken)) {
			error_log('Xendit webhook validation failed: Invalid token');
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

		// 5. Check the event
		// (Fallback for the 'PAID' invoice event is included)
		$paymentSuccess = false;
		$paymentId = null;

		if (isset($data->event) && $data->event == 'payment.capture' && isset($data->data->status) && $data->data->status == 'SUCCEEDED') {
			// This is a PaymentRequest payload (SDK v7)
			if (isset($data->data->reference_id)) {
				$paymentSuccess = true;
				$paymentId = $data->data->reference_id;
			}
		} else if (isset($data->status) && $data->status == 'PAID') {
			// This is a legacy Invoice payload
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
				
				// Try to find the payment by assoc_id (submission_id) first, then by queued_payment_id
				$queuedPayment = $queuedPaymentDao->getByAssoc(
					ASSOC_TYPE_SUBMISSION,
					$paymentId
				);
				
				if (!$queuedPayment) {
					// Fallback to searching by queued_payment_id
					$queuedPayment = $queuedPaymentDao->getById($paymentId);
				}
				
				if (!$queuedPayment) {
					throw new \Exception("Invalid queued payment ID $paymentId!");
				}

				// Mark the payment as completed
				$paymentManager = Application::getPaymentManager($journal);
				$paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());

				// Respond to Xendit with a 200 OK status
				echo 'Webhook received';
				header('HTTP/1.1 200 OK');
				return;

			} catch (\Exception $e) {
				error_log('Xendit webhook exception: ' . $e->getMessage());
				header('HTTP/1.1 500 Internal Server Error');
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
