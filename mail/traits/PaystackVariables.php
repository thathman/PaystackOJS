<?php

/**
 * @file plugins/paymethod/paystack/mail/traits/PaystackVariables.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackVariables
 *
 * @ingroup mailables_traits
 *
 * @brief Mailable trait to set additional template variables for Paystack-related emails
 */

namespace APP\plugins\paymethod\paystack\mail\traits;

use APP\core\Application;
use APP\file\PublicFileManager;
use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\payment\QueuedPayment;

trait PaystackVariables
{
    protected static string $paymentReference = 'paymentReference';
    protected static string $transactionIdVar = 'transactionId';
    protected static string $paymentDate = 'paymentDate';
    protected static string $currencySymbol = 'currencySymbol';
    protected static string $contextLogoUrl = 'contextLogoUrl';
    protected static string $paymentUrl = 'paymentUrl';
    protected static string $paymentAmount = 'paymentAmount';
    protected static string $paymentCurrency = 'paymentCurrency';

    abstract public function addData(array $data): Mailable;

    /**
     * Description of additional template variables
     */
    public static function getPaystackDataDescriptions(): array
    {
        return [
            self::$paymentReference => __('plugins.paymethod.paystack.email.paymentReference'),
            self::$transactionIdVar => __('plugins.paymethod.paystack.email.transactionId'),
            self::$paymentDate => __('plugins.paymethod.paystack.email.paymentDate'),
            self::$currencySymbol => __('plugins.paymethod.paystack.email.currencySymbol'),
            self::$contextLogoUrl => __('plugins.paymethod.paystack.email.contextLogoUrl'),
            'paymentUrl' => __('plugins.paymethod.paystack.email.paymentUrl'),
            'paymentAmount' => __('plugins.paymethod.paystack.email.paymentAmount'),
            'paymentCurrency' => __('plugins.paymethod.paystack.email.paymentCurrency'),
        ];
    }

    /**
     * Set values for common Paystack email template variables
     * 
     * @param Journal $context
     * @param QueuedPayment $queuedPayment
     * @param string|null $transactionReference
     * @param string|null $transactionId
     */
    protected function setupPaystackVariables(
        Journal $context,
        QueuedPayment $queuedPayment,
        ?string $transactionReference = null,
        ?string $transactionId = null
    ): void {
        $request = Application::get()->getRequest();
        $publicFileManager = new PublicFileManager();

        // Get context logo URL and HTML
        $logoUrl = null;
        $logoHtml = '';
        $logo = $context->getLocalizedData('pageHeaderTitleImage');
        if ($logo) {
            $logoUrl = $request->getBaseUrl() . '/' . $publicFileManager->getContextFilesPath($context->getId()) . '/' . $logo['uploadName'];
            $contextName = $context->getLocalizedData('name');
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($contextName, ENT_QUOTES, 'UTF-8') . '" style="max-width:200px;height:auto;margin-bottom:20px;background:#fff;padding:10px;border-radius:8px;" />';
        }

        // Get currency symbol using centralized method
        $currencySymbol = \APP\plugins\paymethod\paystack\PaystackPlugin::getCurrencySymbol($queuedPayment->getCurrencyCode());

        // Get context contact email
        $contactEmail = $context->getData('contactEmail') ?? '';
        
        // Build transaction ID HTML if available
        $transactionIdHtml = '';
        if ($transactionId) {
            $transactionIdHtml = '<tr><td style="padding:8px 0;font-size:14px;color:#64748b;"><strong style="color:#1e293b;">Transaction ID:</strong></td><td style="padding:8px 0;font-size:14px;color:#1e293b;text-align:right;font-family:\'Courier New\',monospace;">' . htmlspecialchars($transactionId, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $formattedAmount = number_format((float) $queuedPayment->getAmount(), 2, '.', '');
        $currencyCode = strtoupper((string) $queuedPayment->getCurrencyCode());

        $this->addData([
            self::$paymentReference => $transactionReference ?? '',
            self::$transactionIdVar => $transactionId ?? '',
            self::$paymentDate => date('Y-m-d H:i:s'),
            self::$currencySymbol => $currencySymbol,
            self::$contextLogoUrl => $logoHtml, // Now contains HTML instead of URL
            'contextContactEmail' => $contactEmail,
            'transactionIdHtml' => $transactionIdHtml,
            'paymentUrl' => $queuedPayment->getRequestUrl(),
            'paymentAmount' => $formattedAmount,
            'paymentCurrency' => $currencyCode,
        ]);
    }
}
