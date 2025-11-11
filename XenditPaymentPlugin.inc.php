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
	 * Definisikan pengaturan plugin di form Admin OJS
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
			// --- PERUBAHAN DI SINI ---
			// Ganti clientId/secret dengan apiKey
			->addField(new \PKP\components\forms\FieldText('apiKey', [
				'label' => __('plugins.paymethod.xendit.settings.apiKey'), // Tambahkan string ini ke file locale
				'description' => __('plugins.paymethod.xendit.settings.apiKey.description'), // Tambahkan string ini ke file locale
				'value' => $this->getSetting($context->getId(), 'apiKey'),
				'groupId' => 'xenditpayment',
			]))
			// Tambahkan Webhook Secret untuk keamanan
			->addField(new \PKP\components\forms\FieldText('webhookSecret', [
				'label' => __('plugins.paymethod.xendit.settings.webhookSecret'), // Tambahkan string ini ke file locale
				'description' => __('plugins.paymethod.xendit.settings.webhookSecret.description'), // Tambahkan string ini ke file locale
				'value' => $this->getSetting($context->getId(), 'webhookSecret'),
				'groupId' => 'xenditpayment',
			]));
			// --- AKHIR PERUBAHAN ---
	}

	/**
	 * Simpan pengaturan
	 */
	public function saveSettings($params, $slimRequest, $request) {
		$allParams = $slimRequest->getParsedBody();
		$saveParams = [];
		foreach ($allParams as $param => $val) {
			switch ($param) {
				// --- PERUBAHAN DI SINI ---
				case 'apiKey':
				case 'webhookSecret':
					$saveParams[$param] = (string) $val;
					break;
				// --- AKHIR PERUBAHAN ---
				case 'testMode':
					$saveParams[$param] = $val === 'true';
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
		// --- PERUBAHAN DI SINI ---
		if ($this->getSetting($context->getId(), 'apiKey') == '') return false;
		// --- AKHIR PERUBAHAN ---
		return true;
	}

	/**
	 * Fungsi ini tidak lagi menangani 'return' dari Omnipay.
	 * Sekarang ia menangani WEBHOOK dari Xendit.
	 */
	function handle($args, $request) {
		$journal = $request->getJournal();
		if (!$journal) throw new \Exception('No journal context!');

		// 1. Dapatkan 'x-callback-token' dari header
		$headers = getallheaders();
		$callbackToken = null;
		if (isset($headers['x-callback-token'])) {
			$callbackToken = $headers['x-callback-token'];
		} else if (isset($headers['X-Callback-Token'])) {
			// Beberapa server mengubah kapitalisasinya
			$callbackToken = $headers['X-Callback-Token'];
		}
		
		// 2. Dapatkan token rahasia dari pengaturan
		$webhookSecret = $this->getSetting($journal->getId(), 'webhookSecret');

		// --- TAMBAHKAN 2 BARIS INI UNTUK DEBUG ---
		error_log('Xendit DEBUG: Token diterima dari Xendit: [' . $callbackToken . ']');
		error_log('Xendit DEBUG: Token disimpan di OJS: [' . $webhookSecret . ']');
		// --- AKHIR TAMBAHAN DEBUG ---

		// 3. Validasi token
		if (!$webhookSecret || $callbackToken !== $webhookSecret) {
			error_log('Xendit webhook validation failed: Invalid token');
			header('HTTP/1.1 401 Unauthorized');
			return;
		}

		// 4. Dapatkan data JSON dari body
		$jsonPayload = file_get_contents('php://input'); // PERBAIKAN 2
		$data = json_decode($jsonPayload);

		if (!$data) { 
			error_log('Xendit webhook error: Invalid payload');
			header('HTTP/1.1 400 Bad Request');
			return;
		}

		// 5. Periksa event
		// (Saya tambahkan fallback untuk invoice 'PAID' juga)
		$paymentSuccess = false;
		$paymentId = null;

		if (isset($data->event) && $data->event == 'payment.capture' && isset($data->data->status) && $data->data->status == 'SUCCEEDED') {
			// Ini adalah payload PaymentRequest (SDK v7)
			if (isset($data->data->reference_id)) {
				$paymentSuccess = true;
				$paymentId = $data->data->reference_id;
			}
		} else if (isset($data->status) && $data->status == 'PAID') {
			// Ini adalah payload Invoice lama
			if (isset($data->external_id)) {
				$paymentSuccess = true;
				$paymentId = $data->external_id;
			}
		}

		// 6. Proses pembayaran jika sukses
		if ($paymentSuccess && $paymentId) {
			try {
				$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
				import('classes.payment.ojs.OJSPaymentManager');
				$queuedPayment = $queuedPaymentDao->getById($paymentId);

				if (!$queuedPayment) {
					throw new \Exception("Invalid queued payment ID $paymentId!");
				}

				// Tandai pembayaran sebagai selesai
				$paymentManager = Application::getPaymentManager($journal);
				$paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());

				// Balas Xendit dengan status 200 OK
				echo 'Webhook received';
				header('HTTP/1.1 200 OK');
				return;

			} catch (\Exception $e) {
				error_log('Xendit webhook exception: ' . $e->getMessage());
				header('HTTP/1.1 500 Internal Server Error');
				return;
			}
		}
		
		// Jika event bukan yang kita cari, tetap balas 200 OK
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
