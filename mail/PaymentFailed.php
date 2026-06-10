<?php

/**
 * @file plugins/paymethod/paystack/mail/PaymentFailed.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaymentFailed
 *
 * @brief Email sent to user when payment fails
 */

namespace APP\plugins\paymethod\paystack\mail;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use PKP\payment\PaymentManager;
use APP\plugins\paymethod\paystack\mail\traits\PaystackVariables;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\variables\ContextEmailVariable as PKPContextEmailVariable;
use PKP\mail\variables\QueuedPaymentEmailVariable;
use PKP\payment\QueuedPayment;
use PKP\security\Role;

class PaymentFailed extends Mailable
{
    use Configurable;
    use Recipient;
    use PaystackVariables;

    protected static ?string $name = 'mailable.paystack.paymentFailed.name';
    protected static ?string $description = 'mailable.paystack.paymentFailed.description';
    protected static ?string $emailTemplateKey = 'PAYSTACK_PAYMENT_FAILED';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR, Role::ROLE_ID_READER, Role::ROLE_ID_SUBSCRIPTION_MANAGER];

    public static function getName(): string
    {
        $v = __(static::$name);
        if (!$v || $v === static::$name || preg_match('/^##.+##$/', $v)) {
            return 'Paystack Payment Failed';
        }
        return $v;
    }

    public static function getDescription(): string
    {
        $v = __(static::$description);
        if (!$v || $v === static::$description || preg_match('/^##.+##$/', $v)) {
            return 'Email sent to users when a Paystack payment attempt fails.';
        }
        return $v;
    }

    protected QueuedPayment $queuedPayment;
    protected ?string $transactionReference;
    protected ?string $transactionIdValue;

    public function __construct(Journal $context, QueuedPayment $queuedPayment, ?string $transactionReference = null, ?string $transactionId = null)
    {
        parent::__construct([$context, $queuedPayment]);
        $this->queuedPayment = $queuedPayment;
        $this->transactionReference = $transactionReference;
        $this->transactionIdValue = $transactionId;
        
        // Setup common Paystack variables from trait
        $this->setupPaystackVariables($context, $queuedPayment, $transactionReference, $transactionId);
        
        // Setup payment-specific variables
        $this->setupPaymentFailedVariables($context, $queuedPayment);
    }

    protected function setupPaymentFailedVariables(Journal $context, QueuedPayment $queuedPayment)
    {
        $request = Application::get()->getRequest();
        $paymentManager = Application::get()->getPaymentManager($context);
        
        // Get payment name
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
                // Ignore
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
}
