<?php

/**
 * @file PaystackPaymentForm.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackPaymentForm
 *
 * Form for Paystack-based payments.
 *
 */

namespace APP\plugins\paymethod\paystack;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\form\Form;
use PKP\payment\PaymentManager;
use APP\plugins\paymethod\paystack\classes\ApcOwnerCompatibility;
use PKP\payment\QueuedPayment;

class PaystackPaymentForm extends Form
{
    /** @var PaystackPlugin */
    public $_paystackPaymentPlugin;

    /** @var QueuedPayment */
    public $_queuedPayment;

    /**
     * @param PaystackPlugin $paystackPaymentPlugin
     * @param QueuedPayment $queuedPayment
     */
    public function __construct($paystackPaymentPlugin, $queuedPayment)
    {
        $this->_paystackPaymentPlugin = $paystackPaymentPlugin;
        $this->_queuedPayment = $queuedPayment;
        parent::__construct(null);
    }

    /**
     * @copydoc Form::display()
     *
     * @param null|Request $request
     * @param null|mixed $template
     */
    public function display($request = null, $template = null)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            if ($request && $request->getJournal()) {
                \APP\plugins\paymethod\paystack\classes\Logger::info((int) $request->getJournal()->getId(), 'Paystack payment blocked in sandbox mode');
            }
            TemplateManager::getManager($request)
                ->assign('message', 'common.sandbox')
                ->display('frontend/pages/message.tpl');
            return;
        }

        $journal = $request->getJournal();
        // Enforce HTTPS when not in test mode
        try {
            $contextId = $journal ? $journal->getId() : null;
            if ($contextId && !\APP\plugins\paymethod\paystack\PaystackPlugin::isHttpsRequest()) {
                $isTest = (bool) $this->_paystackPaymentPlugin->getSetting($contextId, 'testMode');
                if (!$isTest) {
                    $templateMgr = TemplateManager::getManager($request);
                    $templateMgr->assign('message', 'plugins.paymethod.paystack.error.httpsRequired');
                    $templateMgr->display('frontend/pages/message.tpl');
                    return;
                }
            }
        } catch (\Throwable $e) {}
        $paymentManager = Application::get()->getPaymentManager($journal);
        
            $publicKey = $this->_paystackPaymentPlugin->getPublicKey($journal->getId());
            $secretKey = $this->_paystackPaymentPlugin->getSecretKey($journal->getId());
        
        if (!$publicKey || !$secretKey) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.paystack.error.configuration');
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }
        if (!$this->_paystackPaymentPlugin->isCurrencyAllowed((int) $journal->getId(), (string) $this->_queuedPayment->getCurrencyCode())) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.paystack.error');
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }

        // Show payment details page instead of directly redirecting
        $templateMgr = TemplateManager::getManager($request);
        $currentUser = $request->getUser();
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
        if (!ApcOwnerCompatibility::authorizeAndRepair($this->_queuedPayment, $currentUser, $queuedPaymentDao)) {
            $templateMgr->assign('message', 'user.authorization.accessDenied');
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }
        
        // Get payment name - OJSPaymentManager has getPaymentName method
        $paymentName = __('payment.type.' . strtolower($this->_queuedPayment->getType()));
        if (method_exists($paymentManager, 'getPaymentName')) {
            $paymentName = $paymentManager->getPaymentName($this->_queuedPayment);
        }
        
        // For publication fees, get article name if available
        $articleTitle = null;
        if (in_array((int) $this->_queuedPayment->getType(), [
            PaymentManager::PAYMENT_TYPE_PUBLICATION,
            \APP\payment\ojs\OJSPaymentManager::PAYMENT_TYPE_SUBMISSION,
        ], true)) {
            try {
                $submission = Repo::submission()->get($this->_queuedPayment->getAssocId());
                if ($submission) {
                    $publication = $submission->getCurrentPublication();
                    if ($publication) {
                        $articleTitle = $publication->getLocalizedFullTitle(null, 'html');
                        if ($articleTitle) {
                            $articleTitle = strip_tags($articleTitle);
                            $paymentName = (int) $this->_queuedPayment->getType() === \APP\payment\ojs\OJSPaymentManager::PAYMENT_TYPE_SUBMISSION
                                ? __('payment.type.submission') . ' — ' . $articleTitle
                                : $articleTitle . ' ' . __('payment.type.publication');
                        }
                    }
                }
            } catch (\Exception $e) {
                // If we can't get the article, just use the default payment name
                \APP\plugins\paymethod\paystack\classes\Logger::debug((int) $journal->getId(), 'Paystack payment name fallback used');
            }
        }
        
        // Get currency symbol using centralized method
        $currencySymbol = \APP\plugins\paymethod\paystack\PaystackPlugin::getCurrencySymbol($this->_queuedPayment->getCurrencyCode());
        
        $templateMgr->assign([
            'paymentName' => $paymentName,
            'articleTitle' => $articleTitle,
            'amount' => $this->_queuedPayment->getAmount(),
            'currencyCode' => $this->_queuedPayment->getCurrencyCode(),
            'currencySymbol' => $currencySymbol,
            'initiatePaymentUrl' => $request->url(null, 'payment', 'plugin', [
                $this->_paystackPaymentPlugin->getName(),
                'initiate'
            ], [
                'queuedPaymentId' => $this->_queuedPayment->getId()
            ]),
            'cancelUrl' => $this->_queuedPayment->getRequestUrl(),
        ]);
        
        // Display plugin template - use template resource so theme can resolve includes
        $templateResource = $this->_paystackPaymentPlugin->getTemplateResource('paymentDetails.tpl');
        $templateMgr->display($templateResource);
    }

    /**
     * Initiate payment - redirect to Paystack checkout
     *
     * @param Request $request
     */
    public function initiate($request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            if ($request && $request->getJournal()) {
                \APP\plugins\paymethod\paystack\classes\Logger::info((int) $request->getJournal()->getId(), 'Paystack initiate blocked in sandbox mode');
            }
            TemplateManager::getManager($request)
                ->assign('message', 'common.sandbox')
                ->display('frontend/pages/message.tpl');
            return;
        }

        try {
            $journal = $request->getJournal();
            $paymentManager = Application::get()->getPaymentManager($journal);
            // Enforce HTTPS when not in test mode
            try {
                $contextId = $journal ? $journal->getId() : null;
                if ($contextId && !\APP\plugins\paymethod\paystack\PaystackPlugin::isHttpsRequest()) {
                    $isTest = (bool) $this->_paystackPaymentPlugin->getSetting($contextId, 'testMode');
                    if (!$isTest) {
                        $templateMgr = TemplateManager::getManager($request);
                        $templateMgr->assign('message', 'plugins.paymethod.paystack.error.httpsRequired');
                        $templateMgr->display('frontend/pages/message.tpl');
                        return;
                    }
                }
            } catch (\Throwable $e) {}
            
            $publicKey = $this->_paystackPaymentPlugin->getPublicKey($journal->getId());
            $secretKey = $this->_paystackPaymentPlugin->getSecretKey($journal->getId());
            
            if (!$publicKey || !$secretKey) {
                throw new \Exception('Paystack API keys are not configured!');
            }
            if (!$this->_paystackPaymentPlugin->isCurrencyAllowed((int) $journal->getId(), (string) $this->_queuedPayment->getCurrencyCode())) {
                throw new \Exception('Currency not allowed for this gateway configuration');
            }

            // Verify queued payment ID matches
            $queuedPaymentId = $request->getUserVar('queuedPaymentId');
            if (!$queuedPaymentId || $queuedPaymentId != $this->_queuedPayment->getId()) {
                throw new \Exception('Invalid payment request!');
            }
            $user = $request->getUser();
            $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
            if (!ApcOwnerCompatibility::authorizeAndRepair($this->_queuedPayment, $user, $queuedPaymentDao)) {
                throw new \Exception('Unauthorized payment request');
            }

            // Generate a unique reference for this transaction (using cryptographically secure random)
            $reference = 'OJS_' . $this->_queuedPayment->getId() . '_' . time() . '_' . random_int(10000, 99999);
            
            // Convert amount to smallest currency unit (kobo for NGN, cents for USD, etc.)
            // Paystack expects amount in the smallest currency unit (multiply by 100)
            $amountInSmallestUnit = (int) round($this->_queuedPayment->getAmount() * 100);
            
            // Prepare callback URL - Paystack redirects back to this URL after payment
            $callbackUrl = $request->url(null, 'payment', 'plugin', [
                $this->_paystackPaymentPlugin->getName(), 
                'callback'
            ], [
                'queuedPaymentId' => $this->_queuedPayment->getId(),
                'reference' => $reference
            ]);

            // Get payment name/description - same logic as in display() method
            $paymentName = __('payment.type.' . strtolower($this->_queuedPayment->getType()));
            if (method_exists($paymentManager, 'getPaymentName')) {
                $paymentName = $paymentManager->getPaymentName($this->_queuedPayment);
            }
            
            // For publication fees, get article title if available
            if (in_array((int) $this->_queuedPayment->getType(), [
            PaymentManager::PAYMENT_TYPE_PUBLICATION,
            \APP\payment\ojs\OJSPaymentManager::PAYMENT_TYPE_SUBMISSION,
        ], true)) {
                try {
                    $submission = Repo::submission()->get($this->_queuedPayment->getAssocId());
                    if ($submission) {
                        $publication = $submission->getCurrentPublication();
                        if ($publication) {
                            $articleTitle = $publication->getLocalizedFullTitle(null, 'html');
                            if ($articleTitle) {
                                $articleTitle = strip_tags($articleTitle);
                                $paymentName = (int) $this->_queuedPayment->getType() === \APP\payment\ojs\OJSPaymentManager::PAYMENT_TYPE_SUBMISSION
                                ? __('payment.type.submission') . ' — ' . $articleTitle
                                : $articleTitle . ' ' . __('payment.type.publication');
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // If we can't get the article, just use the default payment name
                    \APP\plugins\paymethod\paystack\classes\Logger::debug((int) $journal->getId(), 'Paystack payment name fallback used');
                }
            }

            // Initialize payment with Paystack
            // Note: Paystack uses the same API URL for test and live mode
            // Test/live mode is determined by the API keys used
            $client = new \GuzzleHttp\Client();
            $response = $client->post(\APP\plugins\paymethod\paystack\PaystackPlugin::PAYSTACK_API_URL . '/transaction/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => $user->getEmail(),
                    'amount' => $amountInSmallestUnit,
                    'currency' => strtoupper($this->_queuedPayment->getCurrencyCode() ?: 'NGN'),
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                    'description' => $paymentName, // Displayed on Paystack checkout page
                    'metadata' => [
                        'queuedPaymentId' => $this->_queuedPayment->getId(),
                        'paymentName' => $paymentName,
                    ],
                ],
                'verify' => true, // Verify SSL certificate
                'timeout' => 30,
                'http_errors' => false,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            if (!$responseData['status']) {
                throw new \Exception('Paystack initialization failed: ' . ($responseData['message'] ?? 'Unknown error'));
            }

            // Redirect to Paystack checkout page
            $authorizationUrl = $responseData['data']['authorization_url'];
            $request->redirectUrl($authorizationUrl);
            
        } catch (\Exception $e) {
            \APP\plugins\paymethod\paystack\classes\Logger::error((int) $journal->getId(), 'Paystack initiate failed', ['error' => $e->getMessage()]);
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.paystack.error');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }
    
}
