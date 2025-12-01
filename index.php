<?php
 
/**
 * @file plugins/paymethod/xendit/index.php
 *
 * Copyright (c) 2025 AshVisual Theme
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_paymethod_xendit
 * @brief Wrapper for the Xendit payment plugin.
 */

require_once('XenditPaymentPlugin.inc.php');
return new XenditPaymentPlugin();