<a href="https://www.xendit.co/" target="_blank"><img src="https://www.xendit.co/wp-content/uploads/2024/04/logo-xendit-2023.svg" alt="Xendit Logo" width="250"></a>
# Xendit Payment Gateway for OJS
<p>
  <img alt="OJS Compatibility" src="https://img.shields.io/badge/OJS-3.3.x+-blue.svg">
  <img alt="PHP Version" src="https://img.shields.io/badge/PHP-7.4+-blueviolet.svg">
  <img alt="License" src="https://img.shields.io/badge/License-GPLv3-green.svg">
</p>

Xendit payment plugin for Open Journal Systems (OJS). This plugin integrates OJS with Xendit, allowing your journal to accept payments for publication fees, subscriptions, or donations through various payment channels supported by Xendit across Southeast Asia, including Indonesia, Philippines, Malaysia, Thailand, Vietnam, Hongkong and Singapore.

## Supported OJS Versions

This plugin is compatible with OJS 3.3.x and newer versions.

## ✨ Features

*   **Easy Integration**: Simply enable and enter your API credentials to get started.
*   **Secure Redirect**: Users are redirected to a secure Xendit payment page to complete the transaction, ensuring no sensitive card data is stored on your OJS server.
*   **Multi-Channel Support**: Supports all payment methods available in your Xendit account (Credit/Debit Cards, Virtual Accounts, E-Wallets, Retail Outlets).
*   **Automatic Webhooks**: Uses secure webhook verification to automatically confirm payment status and mark invoices as paid in OJS.
*   **Test Mode**: Includes a "Test Mode" option for testing the payment flow without real transactions.
*   **Customer Details**: Automatically includes the OJS user's name and email in the Xendit invoice for easy identification.

## 📋 Prerequisites

1.  OJS version 3.3.x or newer.
2.  PHP 7.4 or newer.
3.  PHP extensions `curl` and `json`.
4.  An active Xendit account.
5.  Composer v2 to manage PHP dependencies.

## 🚀 Installation

### 1. Via OJS Admin Dashboard (Recommended Method)

a. Download the latest release package (`.tar.gz`) from this repository's Releases page.
b. Log in to your OJS dashboard as an Administrator.
c. Navigate to `Settings` > `Website` > `Plugins`.
d. Click the `Upload A New Plugin` tab.
e. Upload the `.tar.gz` file you downloaded.
f. After installation is complete, enable the "Xendit Payment Plugin" from the plugin list under the "Paymethod Plugins" category.

### 2. Manual Installation

a. Download and extract the latest release package.
b. Copy the `xenditPayment` directory into the `plugins/paymethod/` directory of your OJS installation.
c. From your terminal, navigate to the newly copied plugin directory:
   ```bash
   cd /path/to/your/ojs/plugins/paymethod/xenditPayment
   ```
d. Run Composer to install the required dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
e. Log in to your OJS dashboard, navigate to `Settings` > `Website` > `Plugins`, and enable the plugin.

## ⚙️ Configuration

Once the plugin is enabled, you need to configure it with your Xendit credentials.

### 1. Get Credentials from Xendit Dashboard

a. **API Key**:
   - Log in to your Xendit Dashboard.
   - Navigate to `Settings` > `Developers` > `API Keys`.
   - Create a new "Secret Key" with `Write` permissions for the `Money-In` product. Copy this key.

b. **Webhook Secret (Callback Verification Token)**:
   - Navigate to `Settings` > `Developers` > `Webhooks`.
   - Set your Webhook URL to: `https://[YOUR_JOURNAL_URL]/index.php/[context]/payment/plugin/XenditPayment/webhook`
     - Replace `https://YOUR_JOURNAL_URL` with your OJS site's URL.
     - Replace `context` with your journal's path (e.g., `biologyjournal`).
   - **Important Note**: If the `disable_path_info` setting in your OJS `config.inc.php` file is set to `On`, you must use the following URL format instead:
   
     `https://YOUR_JOURNAL_URL/index.php?journal=context&page=payment&op=plugin&plugin=XenditPayment&path=webhook`
   - Click "Verify Token" to view or set your verification token. Copy this token.
   - Ensure you check the `Invoice paid` event in the webhook settings.

### 2. Configure in OJS

a. In the OJS dashboard, navigate to `Distribution` > `Payments`.
b. Enable general payments (`Enable payments`).
c. Scroll down to the **Xendit Payment Plugin** section.
d. **API Key**: Paste the *Secret Key* you obtained from the Xendit dashboard.
e. **Webhook Secret**: Paste the *Callback Verification Token* you obtained from the Xendit webhook settings.
f. **Test Mode**: You can enable this mode for testing. Make sure you use an API Key from the test mode in the Xendit dashboard if this option is enabled.
g. Click `Save`.

## 💳 Payment Workflow

1.  A user chooses to make a payment in OJS (e.g., for a submission fee).
2.  OJS displays Xendit as one of the payment methods.
3.  After selecting Xendit, the user is redirected to a secure Xendit invoice page.
4.  The user selects their desired payment method (e.g., Virtual Account, Credit Card) and completes the payment.
5.  Upon successful payment, Xendit sends a notification (webhook) to your OJS server.
6.  The plugin verifies this notification and automatically updates the payment status in OJS to "Completed".
7.  The user is redirected back to a success page in OJS.

## 📄 License

This plugin is released under the GNU General Public License v3. See the `docs/COPYING` file included with OJS for full details.
