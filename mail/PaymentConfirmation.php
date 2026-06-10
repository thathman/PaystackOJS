<?php

/**
 * @file plugins/paymethod/paystack/mail/PaymentConfirmation.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaymentConfirmation
 *
 * @brief Email sent to user confirming successful payment completion
 */

namespace APP\plugins\paymethod\paystack\mail;

use APP\core\Application;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\journal\Journal;
use APP\mail\variables\ContextEmailVariable;
use PKP\payment\PaymentManager;
use APP\plugins\paymethod\paystack\mail\traits\PaystackVariables;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\variables\ContextEmailVariable as PKPContextEmailVariable;
use PKP\mail\variables\QueuedPaymentEmailVariable;
use PKP\payment\QueuedPayment;
use PKP\security\Role;

class PaymentConfirmation extends Mailable
{
    use Configurable;
    use Recipient;
    use PaystackVariables;

    protected static ?string $name = 'mailable.paystack.paymentConfirmation.name';
    protected static ?string $description = 'mailable.paystack.paymentConfirmation.description';
    protected static ?string $emailTemplateKey = 'PAYSTACK_PAYMENT_CONFIRMATION';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR, Role::ROLE_ID_READER, Role::ROLE_ID_SUBSCRIPTION_MANAGER];

    protected static string $paymentReceiptUrl = 'paymentReceiptUrl';

    protected QueuedPayment $queuedPayment;
    protected ?string $transactionReference;
    protected ?string $transactionIdValue;

    public static function getName(): string
    {
        $v = __(static::$name);
        if (!$v || $v === static::$name || preg_match('/^##.+##$/', $v)) {
            return 'Paystack Payment Confirmation';
        }
        return $v;
    }

    public static function getDescription(): string
    {
        $v = __(static::$description);
        if (!$v || $v === static::$description || preg_match('/^##.+##$/', $v)) {
            return 'Email sent to users after a successful Paystack payment.';
        }
        return $v;
    }

    public function __construct(Journal $context, QueuedPayment $queuedPayment, ?string $transactionReference = null, ?string $transactionId = null)
    {
        parent::__construct([$context, $queuedPayment]);
        $this->queuedPayment = $queuedPayment;
        $this->transactionReference = $transactionReference;
        $this->transactionIdValue = $transactionId;
        
        // Setup common Paystack variables from trait
        $this->setupPaystackVariables($context, $queuedPayment, $transactionReference, $transactionId);
        
        // Setup payment-specific variables
        $this->setupPaymentConfirmationVariables($context, $queuedPayment);
    }

    protected function setupPaymentConfirmationVariables(Journal $context, QueuedPayment $queuedPayment)
    {
        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $paymentManager = Application::get()->getPaymentManager($context);
        
        // Get payment name - same logic as in PaystackPaymentForm
        $paymentName = __('payment.type.' . strtolower($queuedPayment->getType()));
        if (method_exists($paymentManager, 'getPaymentName')) {
            $paymentName = $paymentManager->getPaymentName($queuedPayment);
        }
        
        // For publication fees, get article title if available
        if ($queuedPayment->getType() == PaymentManager::PAYMENT_TYPE_PUBLICATION) {
            try {
                $submission = Repo::submission()->get($queuedPayment->getAssocId());
                if ($submission) {
                    $publication = $submission->getCurrentPublication();
                    if ($publication) {
                        $articleTitle = $publication->getLocalizedFullTitle(null, 'html');
                        if ($articleTitle) {
                            $articleTitle = strip_tags($articleTitle);
                            $paymentName = $articleTitle . ' ' . __('payment.type.publication');
                        }
                    }
                }
            } catch (\Exception $e) {
                // If we can't get the article, just use the default payment name
                error_log('Paystack: Could not get article title for email: ' . $e->getMessage());
            }
        }
        
        $this->addData([
            'paymentName' => $paymentName,
        ]);
    }


    public static function getDataDescriptions(): array
    {
        return array_merge(
            parent::getDataDescriptions(),
            static::getPaystackDataDescriptions(),
            [
                static::$paymentReceiptUrl => __('plugins.paymethod.paystack.email.paymentReceiptUrl'),
                QueuedPaymentEmailVariable::PAYMENT_NAME => __('emailTemplate.variable.queuedPayment.itemName'),
                QueuedPaymentEmailVariable::PAYMENT_AMOUNT => __('emailTemplate.variable.queuedPayment.itemCost'),
                QueuedPaymentEmailVariable::PAYMENT_CURRENCY_CODE => __('emailTemplate.variable.queuedPayment.itemCurrencyCode'),
            ]
        );
    }

    /**
     * Setup template variable mapping
     */
    protected static function templateVariablesMap(): array
    {
        $map = parent::templateVariablesMap();
        $map[QueuedPayment::class] = QueuedPaymentEmailVariable::class;
        return $map;
    }

    protected function addFooter(string $locale): self
    {
        // Footer is removed - email template already includes footer, avoid duplication
        $this->footer = '';
        return $this;
    }

    protected function renameContextVariables(string $footer): string
    {
        $map = [
            '{$' . PKPContextEmailVariable::CONTEXT_NAME . '}' => '{$' . ContextEmailVariable::CONTEXT_NAME . '}',
            '{$' . PKPContextEmailVariable::CONTEXT_URL . '}' => '{$' . ContextEmailVariable::CONTEXT_URL . '}',
        ];
        return str_replace(array_keys($map), array_values($map), $footer);
    }
}
