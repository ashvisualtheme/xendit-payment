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
		error_log('Xendit DEBUG: Webhook endpoint hit.'); // <- LOG ANDA SUDAH DI SINI
		$this->import('XenditWebhookHandler');
		$journal = $request->getJournal();
		if (!$journal) {
			error_log('Xendit DEBUG: Webhook error: No journal context!');
			header('HTTP/1.1 400 Bad Request');
			return;
		}
		error_log('Xendit DEBUG: Journal context loaded: ' . $journal->getPath());

		$handler = new XenditWebhookHandler($this, $journal);

		error_log('Xendit DEBUG: Calling handler->verify()...');
		if (!$handler->verify()) {
			error_log('Xendit DEBUG: handler->verify() returned false. Stopping.');
			header('HTTP/1.1 401 Unauthorized');
			return;
		}
		error_log('Xendit DEBUG: handler->verify() returned true.');

		error_log('Xendit DEBUG: Calling handler->parsePayload()...');
		$data = $handler->parsePayload();
		if (!$data) { 
			error_log('Xendit DEBUG: handler->parsePayload() returned null/false. Invalid payload.');
			header('HTTP/1.1 400 Bad Request');
			return;
		}
		error_log('Xendit DEBUG: handler->parsePayload() OK.');

		error_log('Xendit DEBUG: Calling handler->getPaymentId()...');
		$paymentId = $handler->getPaymentId($data);

		if ($paymentId) {
			error_log('Xendit DEBUG: Payment ID (external_id) found: ' . $paymentId);
			try {
				$this->import('XenditPaymentProcessor');
				$processor = new XenditPaymentProcessor($this, $request);
				
				error_log('Xendit DEBUG: Calling processor->process() with paymentId: ' . $paymentId);
				$processor->process($paymentId); // This method now handles idempotency internally
				error_log('Xendit DEBUG: processor->process() finished.');

				echo 'Webhook received and processed';
				header('HTTP/1.1 200 OK');
			} catch (\Exception $e) {
				error_log('Xendit DEBUG: Webhook processing error: ' . $e->getMessage());
				header('HTTP/1.1 500 Internal Server Error');
			}
		} else {
			error_log('Xendit DEBUG: No relevant Payment ID found in payload. Event not processed.');
			echo 'Webhook acknowledged (Event not processed)';
			header('HTTP/1.1 200 OK');
		}
	}


	/**
	 * @see Plugin::getInstallEmailTemplatesFile
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . DIRECTORY_SEPARATOR . 'emailTemplates.xml');
	}
}