<?php

/**
 * @defgroup plugins_paymethod_xendit Xendit Payment Plugin
 */
 
/**
 * @file plugins/paymethod/xendit/index.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_paymethod_xendit
 * @brief Wrapper for the Xendit payment plugin.
 */

require_once('XenditPaymentPlugin.inc.php');
return new XenditPaymentPlugin();