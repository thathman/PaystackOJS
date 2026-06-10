<?php

/**
 * @file plugins/paymethod/paystack/mail/PaymentConfirmationAdmin.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaymentConfirmationAdmin
 *
 * @brief Email sent to journal administrators notifying them of completed payment
 */

namespace APP\plugins\paymethod\paystack\mail;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\mail\variables\ContextEmailVariable;
use APP\plugins\paymethod\paystack\mail\traits\PaystackVariables;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Sender;
use PKP\mail\variables\ContextEmailVariable as PKPContextEmailVariable;
use PKP\mail\variables\QueuedPaymentEmailVariable;
use PKP\payment\QueuedPayment;
use PKP\security\Role;

class PaymentConfirmationAdmin extends Mailable
{
    use Configurable;
    use Sender;
    use PaystackVariables;

    protected static ?string $name = 'mailable.paystack.paymentConfirmationAdmin.name';
    protected static ?string $description = 'mailable.paystack.paymentConfirmationAdmin.description';
    protected static ?string $emailTemplateKey = 'PAYSTACK_PAYMENT_CONFIRMATION_ADMIN';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUBSCRIPTION_MANAGER];

    public static function getName(): string
    {
        $v = __(static::$name);
        if (!$v || $v === static::$name || preg_match('/^##.+##$/', $v)) {
            return 'Paystack Payment Notification (Administrator)';
        }
        return $v;
    }

    public static function getDescription(): string
    {
        $v = __(static::$description);
        if (!$v || $v === static::$description || preg_match('/^##.+##$/', $v)) {
            return 'Email sent to journal managers after a successful Paystack payment.';
        }
        return $v;
    }

    protected static string $payerName = 'payerName';
    protected static string $payerEmail = 'payerEmail';
    protected static string $paymentManagementUrl = 'paymentManagementUrl';

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
        
        // Setup admin-specific variables
        $this->setupPaymentAdminVariables($context, $queuedPayment);
    }

    protected function setupPaymentAdminVariables(Journal $context, QueuedPayment $queuedPayment)
    {
        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        
        $user = Repo::user()->get($queuedPayment->getUserId());
        
        $paymentManagementUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'management',
            'settings',
            ['website', 'payments']
        );

        $this->addData([
            static::$payerName => $user ? $user->getFullName() : __('common.unknown'),
            static::$payerEmail => $user ? $user->getEmail() : __('common.unknown'),
            static::$paymentManagementUrl => $paymentManagementUrl,
        ]);
    }
    

    public static function getDataDescriptions(): array
    {
        return array_merge(
            parent::getDataDescriptions(),
            static::getPaystackDataDescriptions(),
            [
                static::$payerName => __('plugins.paymethod.paystack.email.payerName'),
                static::$payerEmail => __('plugins.paymethod.paystack.email.payerEmail'),
                static::$paymentManagementUrl => __('plugins.paymethod.paystack.email.paymentManagementUrl'),
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
        $this->footer = $this->renameContextVariables(
            __('plugins.paymethod.paystack.email.paymentConfirmationAdmin.footer', [], $locale)
        );
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
