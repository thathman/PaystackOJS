<?php

/**
 * @file plugins/paymethod/paystack/PaystackPlugin.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackPlugin
 *
 * @ingroup plugins_paymethod_paystack
 *
 * @brief Paystack payment plugin class
 */

namespace APP\plugins\paymethod\paystack;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\template\TemplateManager;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use PKP\mail\Mailable;
use PKP\security\Role;
use PKP\core\PKPApplication;
use PKP\components\forms\context\PKPPaymentSettingsForm;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\plugins\Hook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\plugins\PaymethodPlugin;
use APP\plugins\paymethod\paystack\classes\Logger;
use APP\plugins\paymethod\paystack\classes\ApcOwnerCompatibility;
use APP\plugins\paymethod\paystack\mail\PaymentConfirmation;
use APP\plugins\paymethod\paystack\mail\PaymentConfirmationAdmin;
use APP\plugins\paymethod\paystack\mail\PaymentFailed;
require_once(dirname(__FILE__) . '/PaystackWebhookTableMigration.php');
require_once(dirname(__FILE__) . '/mail/traits/PaystackVariables.php');
require_once(dirname(__FILE__) . '/mail/PaymentFailed.php');
require_once(dirname(__FILE__) . '/mail/PaymentConfirmation.php');
require_once(dirname(__FILE__) . '/mail/PaymentConfirmationAdmin.php');

class PaystackPlugin extends PaymethodPlugin
{
    /**
     * Avoid duplicate hook registrations when the plugin is loaded from multiple categories.
     */
    private static bool $hooksRegistered = false;

    /**
     * Paystack API base URL
     */
    const PAYSTACK_API_URL = 'https://api.paystack.co';
    /**
     * Currencies supported by this plugin integration.
     */
    const SUPPORTED_CURRENCIES = ['NGN', 'USD', 'GHS', 'ZAR', 'KES', 'XOF'];
    /**
     * IPs Paystack documents as webhook sources (defense-in-depth; the HMAC
     * signature remains the primary check). https://support.paystack.com/en/articles/2130946
     */
    const PAYSTACK_WEBHOOK_IPS = ['52.31.139.75', '52.49.173.169', '52.214.14.220'];

    /**
     * @see Plugin::getName
     * 
     * Note: This must return the class name (normalized) to match OJS's database join
     * in VersionDAO::getCurrentProducts() which joins on LOWER(product_class_name).
     * The display name is handled by getDisplayName().
     */
    public function getName()
    {
        // Use the class name to match OJS database expectations
        // The normalized class name will be used for plugin_settings.plugin_name
        $classNameParts = explode('\\', get_class($this));
        return strtolower(end($classNameParts)); // Returns 'paystackplugin'
    }

    /**
     * @see Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return $this->tr('plugins.paymethod.paystack.displayName', 'Paystack Payment Gateway');
    }

    /**
     * @see Plugin::getDescription
     */
    public function getDescription()
    {
        return $this->tr('plugins.paymethod.paystack.description', 'Payments will be processed using the Paystack payment gateway.');
    }

    /**
     * Safe translation helper to avoid rendering raw locale keys in UI.
     */
    private function tr(string $key, string $fallback): string
    {
        $value = __($key);
        if (!$value || $value === $key || preg_match('/^##.+##$/', (string) $value)) {
            return $fallback;
        }
        return (string) $value;
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        // Always add locale data
        $this->addLocaleData();

        // Remove legacy duplicate registration under plugins.generic.
        // This plugin should only be registered as plugins.paymethod.
        $this->cleanupLegacyGenericWrapperVersionRecord();

        if (!self::$hooksRegistered) {
            // Always register these hooks
            Hook::add('Form::config::before', $this->addSettings(...));
            Hook::add('Mailer::Mailables', $this->addMailable(...));

            // Inject frontend stylesheets for payment pages
            Hook::add('TemplateManager::display', $this->loadFrontendStyles(...));
            // Inject backend styles/scripts for plugin settings and tab modals
            Hook::add('TemplateManager::display', $this->loadBackendAssets(...));
            Hook::add('TemplateManager::fetch', $this->loadBackendAssets(...));

            self::$hooksRegistered = true;
        }
        return true;
    }

    /**
     * Inject frontend CSS globally for Paystack payment pages
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     */
    public function loadFrontendStyles(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = $args[1] ?? '';

        if (
            strpos($template, 'templates/paymentDetails.tpl') !== false ||
            strpos($template, 'templates/paymentConfirmation.tpl') !== false ||
            strpos($template, 'templates/paymentHistory.tpl') !== false ||
            strpos($template, 'templates/paymentReceipt.tpl') !== false
        ) {
            $request = Application::get()->getRequest();
            $templateMgr->addStyleSheet(
                'paystackFrontendCss',
                $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/frontend.css',
                ['contexts' => 'frontend']
            );
        }

        return false;
    }

    /**
     * Inject backend UI assets for Paystack settings and admin tabs.
     */
    public function loadBackendAssets(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = (string) ($args[1] ?? '');
        $request = Application::get()->getRequest();

        if (!$request || !$request->getUser()) {
            return false;
        }

        $matchesBackendTemplate =
            strpos($template, 'management') !== false
            || strpos($template, 'settings') !== false
            || strpos($template, 'plugins') !== false
            || strpos($template, 'controllers') !== false
            || strpos($template, 'grid') !== false;

        if (!$matchesBackendTemplate) {
            return false;
        }

        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $cssPath = __DIR__ . '/css/backend.css';
        $jsPath = __DIR__ . '/js/backend.js';
        $cssVersion = file_exists($cssPath) ? (string) filemtime($cssPath) : (string) time();
        $jsVersion = file_exists($jsPath) ? (string) filemtime($jsPath) : (string) time();
        $templateMgr->addStyleSheet(
            'paystackBackendCss',
            $base . '/css/backend.css?v=' . rawurlencode($cssVersion),
            ['contexts' => 'backend']
        );
        $templateMgr->addJavaScript(
            'paystackBackendJs',
            $base . '/js/backend.js?v=' . rawurlencode($jsVersion),
            ['contexts' => 'backend']
        );

        return false;
    }

    /**
     * Remove any legacy wrapper row that registered this plugin under plugins.generic.
     */
    private function cleanupLegacyGenericWrapperVersionRecord(): void
    {
        try {
            $exists = DB::table('versions')
                ->where('product_type', 'plugins.generic')
                ->where('product', 'paystack')
                ->exists();
            if (!$exists) {
                return;
            }

            DB::table('versions')
                ->where('product_type', 'plugins.generic')
                ->where('product', 'paystack')
                ->delete();
        } catch (\Throwable $e) {
            error_log('Paystack cleanupLegacyGenericWrapperVersionRecord failed: ' . $e->getMessage());
        }
    }

    /**
     * Expose additional plugin actions (Emails tab)
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $dispatcher = $request->getDispatcher();
        $transactionsTabLabel = $this->tr('plugins.paymethod.paystack.transactions.tab', 'Transactions');
        // Single clean settings action: the standard "Settings" link (keys/currency,
        // from the parent) plus a Transactions list with refund. Email templating is
        // handled natively by OJS under Settings; this plugin adds no email UI.
        $actions[] = new \PKP\linkAction\LinkAction(
            'transactions',
            new \PKP\linkAction\request\AjaxModal(
                $dispatcher->url($request, \APP\core\Application::ROUTE_COMPONENT, null, 'grid.settings.plugins.SettingsPluginGridHandler', 'manage', null, ['verb' => 'transactions', 'plugin' => $this->getName(), 'category' => 'paymethod']),
                $transactionsTabLabel
            ),
            $transactionsTabLabel
        );
        return $actions;
    }

    /**
     * Handle plugin manage requests (Emails tab)
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'transactions') {
            $context = $request->getContext();
            if (!$context) return parent::manage($args, $request);
            $tm = TemplateManager::getManager($request);
            $contextId = $context->getId();
            $rows = [];
            $completedPaymentDao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO'); /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $completedPaymentDao */
            $payments = $completedPaymentDao->getByContextId($contextId);
            foreach ($payments as $p) {
                $meta = $this->getPaymentMetadata($p->getId(), $contextId) ?: [];
                $symbol = self::getCurrencySymbol($p->getCurrencyCode());
                $amountFormatted = $symbol . number_format((float) $p->getAmount(), 2, '.', ',');
                $typeLabel = $this->paymentTypeLabel((int) $p->getType());
                $rows[] = [
                    'id' => $p->getId(),
                    'type' => $typeLabel,
                    'assocId' => $p->getAssocId(),
                    'amountFormatted' => $amountFormatted,
                    'reference' => $meta['reference'] ?? '',
                    'transactionId' => $meta['transactionId'] ?? '',
                    'viewUrl' => $this->buildPaystackDashboardUrl($meta['reference'] ?? ''),
                    'detailsUrl' => $request->getDispatcher()->url($request, \APP\core\Application::ROUTE_COMPONENT, null, 'grid.settings.plugins.SettingsPluginGridHandler', 'manage', null, ['verb' => 'transactionDetails', 'plugin' => $this->getName(), 'category' => 'paymethod', 'paymentId' => $p->getId(), 'plain' => 1]),
                    'refundUrl' => $request->getDispatcher()->url($request, \APP\core\Application::ROUTE_COMPONENT, null, 'grid.settings.plugins.SettingsPluginGridHandler', 'manage', null, ['verb' => 'refund', 'plugin' => $this->getName(), 'category' => 'paymethod', 'paymentId' => $p->getId(), 'plain' => 1]),
                ];
            }
            $tm->assign('transactions', $rows);
            return new \PKP\core\JSONMessage(true, $tm->fetch($this->getTemplateResource('transactions.tpl')));
        }
        if ($request->getUserVar('verb') === 'transactionDetails') {
            $context = $request->getContext(); if (!$context) return parent::manage($args, $request);
            $tm = TemplateManager::getManager($request);
            $contextId = $context->getId();
            $paymentId = $this->validateInt($request->getUserVar('paymentId'));
            if (!$paymentId) return new \PKP\core\JSONMessage(false, __('common.error'));
            $cpDao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO'); /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $cpDao */
            $p = $cpDao->getById($paymentId);
            if (!$p || $p->getContextId() != $contextId) return new \PKP\core\JSONMessage(false, __('common.error'));
            $meta = $this->getPaymentMetadata($p->getId(), $contextId) ?: [];
            if ($request->getUserVar('reverify') && !empty($meta['reference'])) {
                try { $meta['verify'] = $this->verifyTransaction($contextId, $meta['reference']); } catch (\Exception $e) { $meta['verify_error'] = $e->getMessage(); }
            }
            $tm->assign('payment', $p); $tm->assign('meta', $meta);
            $tm->assign('pluginName', $this->getName());
            if ($request->getUserVar('plain')) {
                return new \PKP\core\JSONMessage(true, $tm->fetch($this->getTemplateResource('transactionDetails.tpl')));
            }
            // Default render (Ajax JSON)
            return new \PKP\core\JSONMessage(true, $tm->fetch($this->getTemplateResource('transactionDetails.tpl')));
        }

        if ($request->getUserVar('verb') === 'refund') {
            $context = $request->getContext(); if (!$context) return parent::manage($args, $request);
            $tm = TemplateManager::getManager($request);
            $contextId = $context->getId();
            $paymentId = $this->validateInt($request->getUserVar('paymentId'));
            if (!$paymentId) return new \PKP\core\JSONMessage(false, __('common.error'));
            $cpDao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO'); /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $cpDao */
            $p = $cpDao->getById($paymentId);
            if (!$p || $p->getContextId() != $contextId) return new \PKP\core\JSONMessage(false, __('common.error'));
            $meta = $this->getPaymentMetadata($p->getId(), $contextId) ?: [];
            if ($request->getUserVar('doRefund')) {
                $result = ['ok' => false, 'message' => __('common.error')];
                if (!$request->checkCSRF()) {
                    $result['message'] = __('form.csrfInvalid');
                } elseif (!$this->isPostRequest()) {
                    $result['message'] = __('common.invalidAction');
                } elseif (empty($meta['reference'])) {
                    $result['message'] = __('plugins.paymethod.paystack.error');
                } else {
                    try {
                        $secretKey = $this->getSecretKey((int) $contextId);
                        if (!$secretKey) {
                            throw new \Exception('Missing secret key');
                        }

                        $amountVar = $request->getUserVar('amount');
                        $amountMajor = null;
                        if ($amountVar !== null && trim((string) $amountVar) !== '') {
                            $amountMajor = (float) $amountVar;
                            if ($amountMajor <= 0 || $amountMajor > (float) $p->getAmount()) {
                                throw new \Exception(__('plugins.paymethod.paystack.error'));
                            }
                        }

                        $payload = ['transaction' => (string) $meta['reference']];
                        if ($amountMajor !== null) {
                            $payload['amount'] = (int) round($amountMajor * 100);
                        }

                        $client = new \GuzzleHttp\Client(['timeout' => 20, 'verify' => true, 'http_errors' => false]);
                        $resp = $client->post(self::PAYSTACK_API_URL . '/refund', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $secretKey,
                                'Content-Type' => 'application/json',
                            ],
                            'json' => $payload,
                        ]);
                        $body = json_decode((string) $resp->getBody(), true) ?: [];
                        if (($body['status'] ?? false) !== true) {
                            $msg = isset($body['message']) ? (string) $body['message'] : __('common.error');
                            throw new \Exception($msg);
                        }
                        $result = ['ok' => true, 'message' => __('common.changesSaved')];
                    } catch (\Throwable $e) {
                        $result = ['ok' => false, 'message' => (string) $e->getMessage()];
                    }
                }
                $tm->assign('result', $result);
            }
            $tm->assign('payment', $p); $tm->assign('meta', $meta); $tm->assign('pluginName', $this->getName());
            if ($request->getUserVar('plain')) {
                 return new \PKP\core\JSONMessage(true, $tm->fetch($this->getTemplateResource('refund.tpl')));
            }
            return new \PKP\core\JSONMessage(true, $tm->fetch($this->getTemplateResource('refund.tpl')));
        }
        return parent::manage($args, $request);
    }

    /**
     * Add Paystack fields to the payment settings form.
     */
    public function addSettings($hookName, $form)
    {
        if ($form->id !== PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS) {
            return;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) { return; }
        $contextId = (int) $context->getId();

        // URLs
        $callbackUrl = $request->url(null, 'payment', 'plugin', [$this->getName(), 'callback']);
        $webhookUrl = $request->url(null, 'payment', 'plugin', [$this->getName(), 'webhook']);

        $hasTestSecret = (string) $this->getSetting($contextId, 'testSecretKey');
        $hasLiveSecret = (string) $this->getSetting($contextId, 'liveSecretKey');

        // Add group for Paystack fields
        $form->addGroup([
            'id' => 'paystackpayment',
            'label' => __('plugins.paymethod.paystack.settings'),
        ]);

        // Settings + keys
        $testModeEnabled = (bool) $this->getSetting($contextId, 'testMode');

        $form->addField(new \PKP\components\forms\FieldOptions('testMode', [
            'label' => __('plugins.paymethod.paystack.settings.testMode'),
            'description' => __('plugins.paymethod.paystack.settings.testMode.description'),
            'options' => [['value' => true, 'label' => __('common.enable')]],
            'value' => $testModeEnabled,
            'groupId' => 'paystackpayment',
        ]));

        // Banner when test mode is enabled
        if ($testModeEnabled) {
            $form->addField(new \PKP\components\forms\FieldHTML('paystackTestModeBanner', [
                'groupId' => 'paystackpayment',
                'label' => '',
                'description' =>
                    '<div class="pkpNotification pkpNotification--warning" style="margin: 0.5rem 0 1rem;">'
                    . '<p style="margin:0;"><strong>' . htmlspecialchars(__('plugins.paymethod.paystack.settings.testModeBanner.title'), ENT_QUOTES, 'UTF-8') . '</strong> '
                    . htmlspecialchars(__('plugins.paymethod.paystack.settings.testModeBanner.body'), ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p style="margin:0.5rem 0 0;">'
                    . '<span style="font-weight:600;">' . htmlspecialchars(__('plugins.paymethod.paystack.settings.callbackUrl'), ENT_QUOTES, 'UTF-8') . ':</span> <code>' . htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8') . '</code><br>'
                    . '<span style="font-weight:600;">' . htmlspecialchars(__('plugins.paymethod.paystack.settings.webhookUrl'), ENT_QUOTES, 'UTF-8') . ':</span> <code>' . htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') . '</code>'
                    . '</p>'
                    . '</div>',
            ]));
        }

        $keepHint = '<br><small>' . __('plugins.paymethod.paystack.settings.secretKeyMasked')
            . ' ' . __('plugins.paymethod.paystack.settings.secretKeyKeepHint') . '</small>';

        $form
            ->addField(new \PKP\components\forms\FieldText('testPublicKey', [
                'label' => __('plugins.paymethod.paystack.settings.testPublicKey'),
                'description' => __('plugins.paymethod.paystack.settings.testPublicKey.description'),
                'value' => (string) $this->getSetting($contextId, 'testPublicKey'),
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('testSecretKey', [
                'label' => __('plugins.paymethod.paystack.settings.testSecretKey'),
                // Never prefill secrets into the input. If a key exists, show a masked hint in the description.
                // Requirement: no mask/hint when empty.
                'description' => __('plugins.paymethod.paystack.settings.testSecretKey.description')
                    . ($hasTestSecret ? $keepHint : ''),
                // Show the masked value inside the input for clarity. This is not the real secret.
                // saveSettings() intentionally ignores masked placeholder values.
                'value' => $hasTestSecret ? (string) $this->maskSecretKey($hasTestSecret) : '',
                'inputType' => 'text',
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('livePublicKey', [
                'label' => __('plugins.paymethod.paystack.settings.livePublicKey'),
                'description' => __('plugins.paymethod.paystack.settings.livePublicKey.description'),
                'value' => (string) $this->getSetting($contextId, 'livePublicKey'),
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('liveSecretKey', [
                'label' => __('plugins.paymethod.paystack.settings.liveSecretKey'),
                // Never prefill secrets into the input. If a key exists, show a masked hint in the description.
                // Requirement: no mask/hint when empty.
                'description' => __('plugins.paymethod.paystack.settings.liveSecretKey.description')
                    . ($hasLiveSecret ? $keepHint : ''),
                // Show the masked value inside the input for clarity. This is not the real secret.
                // saveSettings() intentionally ignores masked placeholder values.
                'value' => $hasLiveSecret ? (string) $this->maskSecretKey($hasLiveSecret) : '',
                'inputType' => 'text',
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldHTML('callbackUrlInfo', [
                'label' => __('plugins.paymethod.paystack.settings.callbackUrl'),
                'description' => __('plugins.paymethod.paystack.settings.callbackUrl.description') . '<br><code>' . htmlspecialchars($callbackUrl) . '</code>',
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldHTML('webhookUrlInfo', [
                'label' => __('plugins.paymethod.paystack.settings.webhookUrl'),
                'description' => __('plugins.paymethod.paystack.settings.webhookUrl.description') . '<br><code>' . htmlspecialchars($webhookUrl) . '</code>',
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldHTML('supportedCurrencies', [
                'label' => __('plugins.paymethod.paystack.settings.supportedCurrencies'),
                'description' => __('plugins.paymethod.paystack.settings.supportedCurrencies.description')
                    . $this->buildSupportedCurrenciesHtml(self::SUPPORTED_CURRENCIES, [
                        'NGN' => 'Nigerian Naira',
                        'USD' => 'US Dollar',
                        'GHS' => 'Ghanaian Cedi',
                        'ZAR' => 'South African Rand',
                        'KES' => 'Kenyan Shilling',
                        'XOF' => 'West African CFA Franc',
                    ]),
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldSelect('logLevel', [
                'label' => __('plugins.paymethod.paystack.settings.logLevel'),
                'description' => __('plugins.paymethod.paystack.settings.logLevel.description'),
                'options' => array_map(function($level, $name) { return ['value' => $level, 'label' => $name]; }, array_keys(\APP\plugins\paymethod\paystack\classes\Logger::getLevels()), \APP\plugins\paymethod\paystack\classes\Logger::getLevels()),
                'value' => \APP\plugins\paymethod\paystack\classes\Logger::getLogLevel($contextId),
                'groupId' => 'paystackpayment',
            ]))
            ->addField(new \PKP\components\forms\FieldOptions('enforceIpAllowlist', [
                'label' => __('plugins.paymethod.paystack.settings.enforceIpAllowlist'),
                'description' => __('plugins.paymethod.paystack.settings.enforceIpAllowlist.description'),
                'options' => [['value' => true, 'label' => __('common.enable')]],
                'value' => (bool) ($this->getSetting($contextId, 'enforceIpAllowlist') ?? false),
                'groupId' => 'paystackpayment',
            ]));

    }

    /**
     * Build Paystack dashboard search URL for a reference
     */
    private function buildPaystackDashboardUrl(?string $reference): ?string
    {
        if (!$reference) return null;
        return 'https://dashboard.paystack.com/#/search?model=transactions&query=' . rawurlencode($reference);
    }

    private function getPaymentMetadata($completedPaymentId, $contextId)
    {
        $key = 'payment_' . (int) $completedPaymentId;
        $json = $this->getSetting($contextId, $key);
        if (!$json) return [];
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }

    private function verifyTransaction(int $contextId, string $reference): array
    {
        $secretKey = $this->getSecretKey($contextId);
        if (!$secretKey) { throw new \Exception('Missing secret key'); }
        $client = new \GuzzleHttp\Client(['timeout' => 15, 'verify' => true]);
        $resp = $client->get(self::PAYSTACK_API_URL . '/transaction/verify/' . rawurlencode($reference), [
            'headers' => [ 'Authorization' => 'Bearer ' . $secretKey ]
        ]);
        $json = json_decode((string) $resp->getBody(), true);
        if (!is_array($json)) throw new \Exception('Invalid response');
        return $json;
    }

    public static function getCurrencySymbol(?string $code): string
    {
        $map = [ 'NGN'=>'₦','USD'=>'$','GHS'=>'₵','KES'=>'KSh','ZAR'=>'R','XOF'=>'CFA' ];
        $code = strtoupper((string) $code);
        return $map[$code] ?? $code;
    }

    private function buildSupportedCurrenciesHtml(array $codes, array $labels): string
    {
        $html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:8px;">';
        foreach ($codes as $code) {
            $name = $labels[$code] ?? $code;
            $html .= '<div style="background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e5e7eb"><strong>'
                . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
                . '</strong><div>'
                . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . '</div></div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function paymentTypeLabel(int $type): string
    {
        try {
            if ($type === \PKP\payment\PaymentManager::PAYMENT_TYPE_PUBLICATION) return __('payment.type.publication');
        } catch (\Throwable $e) {}
        return (string) $type;
    }

    /**
     * Return the Paystack payment form for a queued payment
     */
    public function getPaymentForm($context, $queuedPayment)
    {
        return new \APP\plugins\paymethod\paystack\PaystackPaymentForm($this, $queuedPayment);
    }

    /**
     * Save Paystack-related payment settings via API hook
     * Signature required by PKP\plugins\PaymethodPlugin
     */
    public function saveSettings(string $hookName, array $args)
    {
        // args: [Illuminate\\Http\\Request $illuminateRequest, APP\\core\\Request $request, mixed $response, Illuminate\\Support\\Collection $updatedSettings]
        if (count($args) < 4) { return; }
        $illuminateRequest = $args[0];
        $request = $args[1];
        $updatedSettings = $args[3];
        $context = $request->getContext();
        if (!$context) { return; }
        $contextId = $context->getId();

        $all = (array) $illuminateRequest->input();
        $toSave = [];

        // Keys
        foreach (['testPublicKey','testSecretKey','livePublicKey','liveSecretKey'] as $k) {
            if (array_key_exists($k, $all)) {
                $val = (string) $all[$k];
                if ($k === 'testSecretKey' || $k === 'liveSecretKey') {
                    // Never overwrite stored secrets with masked placeholders or empty values.
                    if ($val === '' || strpos($val, '****') !== false || preg_match('/^[*•]+$/', $val)) { continue; }
                }
                $toSave[$k] = $val;
            }
        }

        // Toggles (optional – only saved if provided)
        foreach (['testMode','enforceIpAllowlist'] as $k) {
            if (array_key_exists($k, $all)) {
                $toSave[$k] = $all[$k] === true || $all[$k] === 'true' || $all[$k] === 1 || $all[$k] === '1';
            }
        }

        // Log level (0..4)
        if (array_key_exists('logLevel', $all)) {
            $lvl = (int) $all['logLevel'];
            if ($lvl < 0 || $lvl > 4) { $lvl = \APP\plugins\paymethod\paystack\classes\Logger::LEVEL_WARNING; }
            $toSave['logLevel'] = $lvl;
        }

        foreach ($toSave as $k => $v) {
            $this->updateSetting($contextId, $k, $v);
            if ($updatedSettings instanceof \Illuminate\Support\Collection) {
                $updatedSettings->put($k, $v);
            }
        }
    }

    /**
     * Mask a secret key for display (do not leak the full value).
     * Shows only the last 4 chars when possible.
     */
    private function maskSecretKey(string $secret): string
    {
        $secret = trim($secret);
        $len = strlen($secret);
        if ($len <= 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        // Keep the mask very short so the last characters are visible in the input without scrolling.
        return '****' . substr($secret, -4);
    }

    /**
     * Payment entrypoint for callback/webhook/initiate routes
     */
    public function handle($args, $request)
    {
        $context = $request->getContext();
        if (!$context) { return; }
        $journal = $context; // alias
        $contextId = (int) $journal->getId();

        // Enforce HTTPS in production (non-test) for all plugin endpoints
        $isTestMode = (bool) ($this->getSetting($contextId, 'testMode') ?? false);
        if (!$isTestMode && !self::isHttpsRequest()) {
            $routePeek = is_array($args) && count($args) ? $args[0] : 'callback';
            if ($routePeek === 'webhook') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['status' => false, 'message' => 'HTTPS required']);
                exit;
            }
            // For browser-facing routes, try to redirect to HTTPS
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            if ($host && $uri) {
                header('Location: https://' . $host . $uri, true, 302);
                return;
            }
            $this->renderMessage($request, 'plugins.paymethod.paystack.error.httpsRequired');
            return;
        }

        $route = is_array($args) && count($args) ? array_shift($args) : 'callback';

        // Initiate redirect to Paystack from our form
        if ($route === 'initiate') {
            if (!$this->isPostRequest() || !$request->checkCSRF()) {
                $this->renderMessage($request, 'form.csrfInvalid');
                return;
            }
            $queuedPaymentId = (int) $request->getUserVar('queuedPaymentId');
            if ($queuedPaymentId > 0) {
                $queuedPaymentDao = \PKP\db\DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */
                $qp = $queuedPaymentDao ? $queuedPaymentDao->getById($queuedPaymentId) : null;
                if ($qp) {
                    $user = $request->getUser();
                    if (!ApcOwnerCompatibility::authorizeAndRepair($qp, $user, $queuedPaymentDao)) {
                        $this->renderMessage($request, 'user.authorization.accessDenied');
                        return;
                    }
                    if (!$this->isCurrencyAllowed($contextId, (string) $qp->getCurrencyCode())) {
                        $this->renderMessage($request, 'plugins.paymethod.paystack.error');
                        return;
                    }
                    $form = $this->getPaymentForm($journal, $qp);
                    if (method_exists($form, 'initiate')) { $form->initiate($request); }
                }
            }
            return;
        }

        // Webhook: verify signature + process
        if ($route === 'webhook') {
            if (!$this->isPostRequest()) {
                http_response_code(405);
                header('Content-Type: application/json');
                echo json_encode(['status' => false, 'message' => 'Method not allowed']);
                exit;
            }
            // Optional IP allowlist (defense-in-depth on top of the HMAC check)
            if ((bool) ($this->getSetting($contextId, 'enforceIpAllowlist') ?? false)) {
                $clientIp = $this->getClientIp();
                if (!in_array($clientIp, self::PAYSTACK_WEBHOOK_IPS, true)) {
                    Logger::warning($contextId, 'Paystack webhook rejected by IP allowlist', ['ip' => $clientIp]);
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => false, 'message' => 'IP not allowed']);
                    exit;
                }
            }
            // Get raw payload
            $payload = file_get_contents('php://input') ?: '';
            // Verify signature
            $secret = (string) $this->getSecretKey($contextId);
            if (!$secret) { http_response_code(500); echo json_encode(['status'=>false,'message'=>'Missing secret']); exit; }
            $sig = $this->getHeaderCaseInsensitive('X-Paystack-Signature');
            if (!$sig) { http_response_code(401); echo json_encode(['status'=>false,'message'=>'Signature missing']); exit; }
            $expected = hash_hmac('sha512', $payload, $secret);
            if (!hash_equals($expected, $sig)) { http_response_code(401); echo json_encode(['status'=>false,'message'=>'Invalid signature']); exit; }

            $eventData = json_decode($payload, true);
            if (!is_array($eventData) || empty($eventData['event']) || empty($eventData['data'])) {
                http_response_code(400); echo json_encode(['status'=>false,'message'=>'Invalid payload']); exit; }

            $event = (string) $eventData['event'];
            $data  = (array) $eventData['data'];
            $reference = isset($data['reference']) ? $this->sanitizeInput($data['reference'], 'token') : null;

            // Store log (DB table if present, otherwise plugin setting fallback)
            try {
                $this->storeWebhookLog($contextId, $event, (string) $reference, $eventData, true);
            } catch (\Throwable $e) { /* ignore logging errors */ }

            // Idempotency per event+reference (DB-backed, bounded + TTL cleanup)
            if ($reference) {
                $this->purgeWebhookDedupeTTL();
                if ($this->isWebhookEventProcessed($contextId, $event, $reference)) {
                    http_response_code(200);
                    echo json_encode(['status' => true]);
                    exit;
                }
            }

            // Handle payment events
            if (in_array($event, ['charge.success','transaction.success','charge.failed'], true)) {
                // Figure queuedPaymentId
                $queuedPaymentId = null;
                if (isset($data['metadata']['queuedPaymentId'])) { $queuedPaymentId = (int) $data['metadata']['queuedPaymentId']; }
                if (!$queuedPaymentId && $reference && preg_match('/^OJS_(\d+)_/', $reference, $m)) { $queuedPaymentId = (int) $m[1]; }
                if (!$queuedPaymentId) { http_response_code(400); echo json_encode(['status'=>false,'message'=>'Missing queuedPaymentId']); exit; }

                $queuedPaymentDao = \PKP\db\DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */
                $queuedPayment = $queuedPaymentDao ? $queuedPaymentDao->getById($queuedPaymentId) : null;
                if (!$queuedPayment || $queuedPayment->getContextId() != $contextId) { http_response_code(404); echo json_encode(['status'=>false,'message'=>'Invalid payment']); exit; }

                // Verify amount/currency (if present in payload).
                // As with the callback, Paystack may add its fee on top of the requested
                // amount (customer-bears-fees), so match on `requested_amount` when present
                // and otherwise require the charged amount to be at least the expected amount.
                $amount = isset($data['amount']) ? ((float) $data['amount'] / 100) : null; // kobo → major
                $requestedAmount = isset($data['requested_amount']) ? ((float) $data['requested_amount'] / 100) : null;
                $currency = isset($data['currency']) ? strtoupper($this->sanitizeInput($data['currency'],'alpha')) : '';
                $expAmount = (float) $queuedPayment->getAmount();
                $expCurrency = strtoupper((string) $queuedPayment->getCurrencyCode());
                $amountMatches = $requestedAmount !== null
                    ? (abs($requestedAmount - $expAmount) <= 0.01)
                    : ($amount === null || $amount >= ($expAmount - 0.01));
                if (!$amountMatches || ($currency !== '' && $currency !== $expCurrency)) {
                    http_response_code(400); echo json_encode(['status'=>false,'message'=>'Amount/currency mismatch']); exit; }
                if ($currency && !$this->isCurrencyAllowed($contextId, $currency)) {
                    http_response_code(400); echo json_encode(['status'=>false,'message'=>'Currency not allowed']); exit; }

                $transactionId = isset($data['id']) ? $this->sanitizeInput((string)$data['id'],'token') : null;

                // Handle Failure
                if ($event === 'charge.failed') {
                    // Send fail email if enabled
                    try { $this->sendPaymentConfirmationEmails($journal, $queuedPayment, (string)$reference, $transactionId, true); } catch (\Throwable $e) { /* swallow */ }
                    if ($reference) { $this->markWebhookEventProcessed($contextId, $event, $reference); }
                    http_response_code(200); echo json_encode(['status'=>true]); exit;
                }

                // Handle Success
                // Idempotent: already completed?
                if ($reference) {
                    $completed = $this->getCompletedPaymentByReference($reference, $contextId);
                    if ($completed) { $this->markWebhookEventProcessed($contextId, $event, $reference); http_response_code(200); echo json_encode(['status'=>true,'message'=>'Already processed']); exit; }
                }

                // Fulfill (guarded against the webhook/callback race)
                $fulfilled = $this->fulfillPaymentAtomically($request, $journal, $queuedPayment, (string) $reference);
                if (!$fulfilled) {
                    if ($reference) { $this->markWebhookEventProcessed($contextId, $event, $reference); }
                    http_response_code(200);
                    echo json_encode(['status' => true, 'message' => 'Already fulfilled']);
                    exit;
                }

                // Store metadata with reference
                if ($reference) {
                    $completed = $this->getCompletedPaymentByAssoc($queuedPayment);
                    if ($completed) { $this->storeTransactionMetadata($completed->getId(), $contextId, $reference, $transactionId); }
                    $this->markWebhookEventProcessed($contextId, $event, $reference);
                    // Send emails (idempotent)
                    try { $this->sendPaymentConfirmationEmails($journal, $queuedPayment, $reference, $transactionId); } catch (\Throwable $e) { /* swallow */ }
                }

                http_response_code(200); echo json_encode(['status'=>true]); exit;
            }

            // Other events: acknowledge
            if ($reference) { $this->markWebhookEventProcessed($contextId, $event, $reference); }
            http_response_code(200); echo json_encode(['status'=>true]); exit;
        }

        // Callback route
            if ($route === 'callback') {
            $reference = $this->sanitizeInput((string) ($request->getUserVar('reference') ?: $request->getUserVar('trxref') ?: ''), 'token');
            $queuedPaymentId = (int) $request->getUserVar('queuedPaymentId');
            if (!$reference || !$queuedPaymentId) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }

            $queuedPaymentDao = \PKP\db\DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */
            $queuedPayment = $queuedPaymentDao ? $queuedPaymentDao->getById($queuedPaymentId) : null;
            if (!$queuedPayment || $queuedPayment->getContextId() != $contextId) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }

            // Verify with Paystack
            try {
                $verify = $this->verifyTransaction($contextId, $reference);
                $ok = (bool) ($verify['status'] ?? false);
                $data = (array) ($verify['data'] ?? []);
                $status = isset($data['status']) ? strtolower((string) $data['status']) : '';
                $amount = isset($data['amount']) ? ((float) $data['amount'] / 100) : null;
                $requestedAmount = isset($data['requested_amount']) ? ((float) $data['requested_amount'] / 100) : null;
                $currency = isset($data['currency']) ? strtoupper($this->sanitizeInput($data['currency'],'alpha')) : '';
                $verifiedRef = isset($data['reference']) ? $this->sanitizeInput((string) $data['reference'], 'token') : '';
                $metaQueuedPaymentId = isset($data['metadata']['queuedPaymentId']) ? (int) $data['metadata']['queuedPaymentId'] : null;
                if (!$ok || $status !== 'success') { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
                // Paystack adds its transaction fee on top of the amount we requested when the
                // customer bears fees, so the charged `amount` can exceed the expected amount.
                // `requested_amount` is exactly what we instructed Paystack to charge — match on
                // that when present; otherwise require the charged amount to be at least expected
                // (the journal never accepts less than it asked for).
                $expectedAmount = (float) $queuedPayment->getAmount();
                $amountMatches = $requestedAmount !== null
                    ? (abs($requestedAmount - $expectedAmount) <= 0.01)
                    : ($amount !== null && $amount >= ($expectedAmount - 0.01));
                if (!$amountMatches || $currency !== strtoupper((string)$queuedPayment->getCurrencyCode())) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
                if (!$this->isCurrencyAllowed($contextId, $currency)) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
                if ($verifiedRef === '' || !hash_equals($reference, $verifiedRef)) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
                if ($metaQueuedPaymentId !== null && $metaQueuedPaymentId !== (int) $queuedPaymentId) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
                if ($metaQueuedPaymentId === null) {
                    if (!preg_match('/^OJS_(\d+)_/', $reference, $m) || (int) $m[1] !== (int) $queuedPaymentId) {
                        $this->renderMessage($request, 'plugins.paymethod.paystack.error');
                        return;
                    }
                }

                // Idempotent (guarded against the webhook/callback race)
                $txId = isset($data['id']) ? (string) $data['id'] : null;
                $completed = $this->getCompletedPaymentByReference($reference, $contextId);
                if (!$completed) {
                    $this->fulfillPaymentAtomically($request, $journal, $queuedPayment, $reference);
                    $completed = $this->getCompletedPaymentByAssoc($queuedPayment);
                    if ($completed) { $this->storeTransactionMetadata($completed->getId(), $contextId, $reference, $txId); }
                }
                // Send emails (idempotent)
                try { $this->sendPaymentConfirmationEmails($journal, $queuedPayment, $reference, $txId ?? null); } catch (\Throwable $e) { /* swallow */ }
            } catch (\Exception $e) {
                $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return;
            }

            // Render a confirmation page instead of redirecting to workflow
            try {
                $tm = TemplateManager::getManager($request);
                $paymentManager = Application::get()->getPaymentManager($journal);
                $paymentName = __('payment.type.' . strtolower($queuedPayment->getType()));
                if (method_exists($paymentManager, 'getPaymentName')) {
                    $paymentName = $paymentManager->getPaymentName($queuedPayment);
                }
                // If publication fee, append the publication title to the name
                if ($queuedPayment->getType() === \PKP\payment\PaymentManager::PAYMENT_TYPE_PUBLICATION) {
                    try {
                        $submission = \APP\facades\Repo::submission()->get($queuedPayment->getAssocId());
                        if ($submission) {
                            $pub = $submission->getCurrentPublication();
                            if ($pub) {
                                $t = $pub->getLocalizedFullTitle(null, 'html');
                                if ($t) {
                                    $t = strip_tags($t);
                                    // Example: "My Article Title — Publication Fee"
                                    $paymentName = trim($t . ' — ' . $paymentName);
                                }
                            }
                        }
                    } catch (\Throwable $e) {}
                }
                $currencySymbol = self::getCurrencySymbol($queuedPayment->getCurrencyCode());
                $tm->assign([
                    'paymentName' => $paymentName,
                    'amount' => $queuedPayment->getAmount(),
                    'currencySymbol' => $currencySymbol,
                    'transactionReference' => $reference,
                    'transactionId' => $txId ?? null,
                    'paymentDate' => date('Y-m-d H:i'),
                    'returnUrl' => $queuedPayment->getRequestUrl(),
                ]);
                $tm->display($this->getTemplateResource('paymentConfirmation.tpl'));
            } catch (\Throwable $e) {
                // Fallback to redirect if template rendering fails
                $request->redirectUrl($queuedPayment->getRequestUrl());
            }
            return;
        }

        // User payment history (logged-in user's own completed payments)
        if ($route === 'history') {
            $user = $request->getUser();
            if (!$user) { $request->redirect(null, 'login'); return; }
            $tm = TemplateManager::getManager($request);
            $payments = $this->getUserPaymentRows($contextId, (int) $user->getId());
            $tm->assign([
                'payments' => $payments,
                'paymentsSummary' => $this->buildPaymentSummary($payments),
                'pluginName' => $this->getName(),
            ]);
            $tm->display($this->getTemplateResource('paymentHistory.tpl'));
            return;
        }

        // Single payment receipt (ownership-checked)
        if ($route === 'receipt') {
            $user = $request->getUser();
            if (!$user) { $request->redirect(null, 'login'); return; }
            $paymentId = (int) (is_array($args) && count($args) ? array_shift($args) : $request->getUserVar('paymentId'));
            $row = $paymentId > 0 ? $this->getUserPaymentRow($contextId, (int) $user->getId(), $paymentId) : null;
            if (!$row) { $this->renderMessage($request, 'plugins.paymethod.paystack.error'); return; }
            $tm = TemplateManager::getManager($request);
            $tm->assign([
                'payment' => $row,
                'paymentName' => $row['paymentName'],
                'amount' => $row['amount'],
                'currencySymbol' => $row['currencySymbol'],
                'transactionReference' => $row['transactionReference'],
                'transactionId' => $row['transactionId'],
                'paymentDate' => $row['formattedDate'],
                'billedToName' => $user->getFullName(),
                'billedToEmail' => $user->getEmail(),
                'billedToAffiliation' => (string) $user->getLocalizedData('affiliation'),
                'paymentMethodLabel' => 'Paystack',
                'returnUrl' => $request->url(null, 'payment', 'plugin', [$this->getName(), 'history']),
            ]);
            $tm->display($this->getTemplateResource('paymentReceipt.tpl'));
            return;
        }
    }

    /**
     * Build a logged-in user's completed-payment rows for this context,
     * shaped for the history/receipt templates. Theme-agnostic.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getUserPaymentRows(int $contextId, int $userId): array
    {
        $rows = [];
        try {
            $records = DB::table('completed_payments')
                ->where('context_id', '=', $contextId)
                ->where('user_id', '=', $userId)
                ->orderByDesc('timestamp')
                ->get();
            foreach ($records as $r) {
                $rows[] = $this->shapePaymentRow($contextId, $r);
            }
        } catch (\Throwable $e) {
            Logger::warning($contextId, 'Paystack: could not load payment history', ['error' => $e->getMessage()]);
        }
        return $rows;
    }

    /** Fetch one of the user's completed payments by id (ownership enforced). */
    private function getUserPaymentRow(int $contextId, int $userId, int $paymentId): ?array
    {
        try {
            $r = DB::table('completed_payments')
                ->where('completed_payment_id', '=', $paymentId)
                ->where('context_id', '=', $contextId)
                ->where('user_id', '=', $userId)
                ->first();
            return $r ? $this->shapePaymentRow($contextId, $r) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Human label for a payment. For publication fees, prepend the article's
     * full title — e.g. "My Article Title — Publication Fee".
     */
    private function resolvePaymentName(int $type, int $assocId): string
    {
        $name = __('payment.type.' . $this->paymentTypeKey($type));
        if ($type === \PKP\payment\PaymentManager::PAYMENT_TYPE_PUBLICATION && $assocId > 0) {
            try {
                $submission = \APP\facades\Repo::submission()->get($assocId);
                if ($submission && ($pub = $submission->getCurrentPublication())) {
                    $title = trim(strip_tags((string) $pub->getLocalizedFullTitle(null, 'html')));
                    if ($title !== '') {
                        $name = $title . ' — ' . $name;
                    }
                }
            } catch (\Throwable $e) { /* fall back to the generic label */ }
        }
        return $name;
    }

    /** Aggregate summary stats for the payment-history header. */
    private function buildPaymentSummary(array $payments): array
    {
        $count = count($payments);
        $total = 0.0;
        $symbol = '';
        foreach ($payments as $p) {
            $total += (float) ($p['amount'] ?? 0);
            if ($symbol === '' && !empty($p['currencySymbol'])) {
                $symbol = (string) $p['currencySymbol'];
            }
        }
        return [
            'count' => $count,
            'totalFormatted' => $symbol . number_format($total, 2),
            'first' => $count ? (string) ($payments[$count - 1]['formattedDate'] ?? '') : '',
            'latest' => $count ? (string) ($payments[0]['formattedDate'] ?? '') : '',
        ];
    }

    /** Normalise a completed_payments record into template-friendly fields. */
    private function shapePaymentRow(int $contextId, $r): array
    {
        $type = (int) $r->payment_type;
        $currency = strtoupper((string) ($r->currency_code_alpha ?? ''));
        $meta = $this->getPaymentMetadata((int) $r->completed_payment_id, $contextId);
        return [
            'id' => (int) $r->completed_payment_id,
            'timestamp' => $r->timestamp,
            'formattedDate' => $r->timestamp ? date('Y-m-d H:i', strtotime((string) $r->timestamp)) : '',
            'paymentName' => $this->resolvePaymentName($type, isset($r->assoc_id) ? (int) $r->assoc_id : 0),
            'amount' => (float) $r->amount,
            'currencyCode' => $currency,
            'currencySymbol' => self::getCurrencySymbol($currency),
            'transactionReference' => (string) ($meta['reference'] ?? ''),
            'transactionId' => (string) ($meta['transactionId'] ?? ''),
            'paymentMethod' => (string) ($r->payment_method_plugin_name ?? ''),
        ];
    }

    /** Map an OJS payment-type constant to its `payment.type.*` locale-key suffix. */
    private function paymentTypeKey(int $type): string
    {
        $M = \APP\payment\ojs\OJSPaymentManager::class;
        $map = [
            $M::PAYMENT_TYPE_MEMBERSHIP => 'membership',
            $M::PAYMENT_TYPE_PURCHASE_SUBSCRIPTION => 'purchaseSubscription',
            $M::PAYMENT_TYPE_RENEW_SUBSCRIPTION => 'renewSubscription',
            $M::PAYMENT_TYPE_PURCHASE_ARTICLE => 'purchaseArticle',
            $M::PAYMENT_TYPE_PURCHASE_ISSUE => 'purchaseIssue',
            $M::PAYMENT_TYPE_PUBLICATION => 'publication',
            $M::PAYMENT_TYPE_DONATION => 'donation',
            $M::PAYMENT_TYPE_SUBMISSION => 'submission',
            $M::PAYMENT_TYPE_FASTTRACK => 'fastTrack',
        ];
        return $map[$type] ?? 'publication';
    }


    private function renderMessage($request, string $messageKey): void
    {
        $tm = TemplateManager::getManager($request);
        $tm->assign('message', $messageKey);
        $tm->display('frontend/pages/message.tpl');
    }

    private function getCompletedPaymentByAssoc($queuedPayment)
    {
        $completedPaymentDao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO'); /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $completedPaymentDao */
        return $completedPaymentDao->getByAssoc($queuedPayment->getUserId(), $queuedPayment->getType(), $queuedPayment->getAssocId());
    }

    private function getCompletedPaymentByReference(string $reference, int $contextId)
    {
        $completedPaymentDao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO'); /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $completedPaymentDao */
        // Fastest path: reverse index written at fulfillment time.
        $indexed = (int) ($this->getSetting($contextId, 'payref_' . $this->sanitizeInput($reference, 'token')) ?: 0);
        if ($indexed > 0) {
            $completed = $completedPaymentDao->getById($indexed);
            if ($completed && (int) $completed->getContextId() === $contextId) {
                return $completed;
            }
        }
        // Fast path: search settings rows likely to contain payment metadata for this reference.
        try {
            $rows = DB::table('plugin_settings')
                ->select(['setting_name', 'setting_value'])
                ->where('plugin_name', '=', $this->getName())
                ->where('context_id', '=', $contextId)
                ->where('setting_name', 'LIKE', 'payment\_%')
                ->where('setting_value', 'LIKE', '%' . $reference . '%')
                ->limit(10)
                ->get();
            foreach ($rows as $row) {
                $meta = json_decode((string) ($row->setting_value ?? ''), true);
                if (!is_array($meta) || ($meta['reference'] ?? null) !== $reference) {
                    continue;
                }
                if (preg_match('/^payment_(\d+)$/', (string) $row->setting_name, $m)) {
                    $completed = $completedPaymentDao->getById((int) $m[1]);
                    if ($completed && (int) $completed->getContextId() === $contextId) {
                        return $completed;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to slow-path scan.
        }

        // Slow path fallback.
        $payments = $completedPaymentDao->getByContextId($contextId);
        foreach ($payments as $p) {
            $meta = $this->getPaymentMetadata($p->getId(), $contextId);
            if (($meta['reference'] ?? null) === $reference) {
                return $p;
            }
        }
        return null;
    }

    private function storeTransactionMetadata(int $completedPaymentId, int $contextId, ?string $reference, ?string $transactionId): void
    {
        $this->updateSetting($contextId, 'payment_'.$completedPaymentId, json_encode([
            'reference' => $reference,
            'transactionId' => $transactionId,
            'completedPaymentId' => $completedPaymentId,
        ]));
        // Reverse index for O(1) reference → completed-payment lookups.
        if ($reference) {
            $this->updateSetting($contextId, 'payref_' . $this->sanitizeInput($reference, 'token'), (string) $completedPaymentId);
        }
    }

    /**
     * Purge webhook-dedupe rows older than 30 days.
     */
    private function purgeWebhookDedupeTTL(): void
    {
        try {
            if (!Schema::hasTable('paystack_webhook_dedupe')) {
                return;
            }
            DB::table('paystack_webhook_dedupe')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - (30 * 86400)))
                ->delete();
        } catch (\Throwable $e) {
            // no-op
        }
    }

    private function isWebhookEventProcessed(int $contextId, string $event, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }
        try {
            if (Schema::hasTable('paystack_webhook_dedupe')) {
                return DB::table('paystack_webhook_dedupe')
                    ->where('context_id', '=', $contextId)
                    ->where('event', '=', $event)
                    ->where('reference', '=', $reference)
                    ->exists();
            }
        } catch (\Throwable $e) {
            // fallback below
        }
        return (bool) $this->getSetting($contextId, 'webhook_event_' . $event . '_' . $reference);
    }

    private function markWebhookEventProcessed(int $contextId, string $event, string $reference): void
    {
        if ($reference === '') {
            return;
        }
        try {
            if (Schema::hasTable('paystack_webhook_dedupe')) {
                DB::table('paystack_webhook_dedupe')->insertOrIgnore([
                    'context_id' => $contextId,
                    'event' => substr((string) $event, 0, 100),
                    'reference' => substr((string) $reference, 0, 128),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
        } catch (\Throwable $e) {
            // fallback below
        }
        $this->updateSetting($contextId, 'webhook_event_' . $event . '_' . $reference, 1);
    }

    /**
     * Claim the fulfillment for a reference via a unique insert; a second
     * concurrent caller (webhook vs callback) loses the claim and skips.
     */
    private function claimFulfillmentGuard(int $contextId, int $queuedPaymentId, ?string $reference): bool
    {
        if (!Schema::hasTable('paystack_fulfillment_guards')) {
            return true;
        }
        try {
            DB::table('paystack_fulfillment_guards')->insert([
                'context_id' => $contextId,
                'queued_payment_id' => $queuedPaymentId,
                'reference' => $reference ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function fulfillPaymentAtomically($request, $journal, $queuedPayment, ?string $reference): bool
    {
        $contextId = (int) $queuedPayment->getContextId();
        $queuedPaymentId = (int) $queuedPayment->getId();
        return DB::transaction(function () use ($request, $journal, $queuedPayment, $contextId, $queuedPaymentId, $reference): bool {
            if (!$this->claimFulfillmentGuard($contextId, $queuedPaymentId, $reference)) {
                return false;
            }
            Application::get()->getPaymentManager($journal)->fulfillQueuedPayment($request, $queuedPayment);
            return true;
        });
    }

    private function getHeaderCaseInsensitive(string $name): ?string
    {
        if (function_exists('getallheaders')) {
            $h = array_change_key_case((array) getallheaders(), CASE_LOWER);
            return $h[strtolower($name)] ?? null;
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    /**
     * Store webhook payload either in a dedicated table or plugin_settings fallback
     */
    private function storeWebhookLog(int $contextId, string $event, ?string $reference, array $payload, bool $verified = true): void
    {
        try {
            $now = date('c');
            if (Schema::hasTable('paystack_webhook_logs')) {
                DB::table('paystack_webhook_logs')->insert([
                    'context_id' => $contextId,
                    'event' => substr((string)$event, 0, 64),
                    'reference' => $reference ? substr($reference, 0, 128) : null,
                    'verified' => $verified ? 1 : 0,
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    // TIMESTAMP columns reject ISO-8601 strings under strict SQL mode
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
            // Fallback to plugin settings
            $key = 'webhook_' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$event)
                . '_' . ($reference ? preg_replace('/[^A-Za-z0-9_\-]/', '', $reference) : 'noref')
                . '_' . time();
            $val = [
                'event' => (string) $event,
                'reference' => $reference,
                'verified' => $verified ? 1 : 0,
                'time' => $now,
                'payload' => $payload,
            ];
            $this->updateSetting($contextId, $key, json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // Swallow logging errors to avoid interrupting webhook processing
        }
    }

    private function sanitizeInput(string $value, string $mode): string
    {
        switch ($mode) {
            case 'alpha': return preg_replace('/[^A-Za-z]/', '', $value);
            case 'token': return preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
            default: return trim($value);
        }
    }

    /**
     * Send author confirmation and manager notification emails (idempotent).
     * Attaches a simple PDF receipt to the author email when possible.
     */
	private function sendPaymentConfirmationEmails($context, $queuedPayment, string $reference, ?string $transactionId, bool $isFailed = false): void
    {
        $contextId = (int) $context->getId();
        $payer = $queuedPayment->getUserId() ? Repo::user()->get((int) $queuedPayment->getUserId()) : null;

        // Failure email (email is on by default; OJS owns the editable template)
        if ($isFailed) {
            if (($this->getSetting($contextId, 'notifyAuthorOnFailed') ?? true) && $payer) {
                $mailable = new PaymentFailed($context, $queuedPayment, $reference, $transactionId);
                $this->dispatchConfiguredMailable($mailable, $context, $payer, null,
                    'Payment failed — {$contextName}',
                    '<p>Dear {$recipientName},</p><p>Your payment for <strong>{$paymentName}</strong> could not be completed. Reference: {$paymentReference}.</p><p>Please try again or contact the journal.</p><p>Regards,<br>{$contextName}</p>');
            }
            return;
        }

        // Success emails (idempotent; tracked in the dedupe table with TTL)
        if ($this->isWebhookEventProcessed($contextId, 'email.success', $reference)
            || $this->getSetting($contextId, 'emailed_success_' . $reference) /* legacy keys */) {
            return;
        }

        // Payer confirmation
        if (($this->getSetting($contextId, 'notifyAuthorOnPaid') ?? true) && $payer) {
            $mailable = new PaymentConfirmation($context, $queuedPayment, $reference, $transactionId);
            $this->dispatchConfiguredMailable($mailable, $context, $payer, null,
                'Payment received — {$contextName}',
                '<p>Dear {$recipientName},</p><p>We have received your payment of <strong>{$currencySymbol}{$paymentAmount} {$paymentCurrency}</strong> for {$paymentName}. Reference: {$paymentReference}.</p><p>Regards,<br>{$contextName}</p>');
        }

        // Admin notification
        if ($this->getSetting($contextId, 'notifyManagersOnPaid') ?? true) {
            $adminEmail = (string) ($context->getData('contactEmail') ?: $context->getData('supportEmail') ?: '');
            if ($adminEmail !== '') {
                $mailable = new PaymentConfirmationAdmin($context, $queuedPayment, $reference, $transactionId);
                $this->dispatchConfiguredMailable($mailable, $context, null,
                    [$adminEmail, (string) ($context->getData('contactName') ?: $context->getLocalizedName())],
                    'Payment received — {$paymentReference}',
                    '<p>A payment of <strong>{$currencySymbol}{$paymentAmount} {$paymentCurrency}</strong> has been completed for {$paymentName}. Reference: {$paymentReference}.</p>');
            }
        }

        // Mark as sent
        $this->markWebhookEventProcessed($contextId, 'email.success', $reference);
        Logger::info($contextId, 'Paystack email dispatch completed', ['sent' => 1]);
    }

    /**
     * Load the editable email template (Settings › Emails) for a Configurable
     * mailable, resolve subject/body/sender/recipient, and send.
     *
     * The Configurable trait only registers the template for the UI — it does
     * NOT populate subject/body at send time, so sending a bare mailable throws
     * "Invalid view." and the email is silently dropped. We therefore set them
     * explicitly: the editable DB email template first, then a hardcoded
     * fallback (using the same variable names the mailable provides), so mail
     * is never lost.
     *
     * @param \PKP\mail\Mailable $mailable
     * @param \APP\journal\Journal $context
     * @param \PKP\user\User|null $recipientUser  Recipient when it is a known user
     * @param array|null $recipientEmail          [email, name] when no user (e.g. admin)
     */
    private function dispatchConfiguredMailable(
        \PKP\mail\Mailable $mailable,
        $context,
        $recipientUser,
        ?array $recipientEmail,
        string $hardSubject,
        string $hardBody
    ): void {
        $contextId = (int) $context->getId();

        $subject = '';
        $body = '';
        $key = $mailable::getEmailTemplateKey();
        if ($key) {
            try {
                $tpl = Repo::emailTemplate()->getByKey($contextId, $key);
                if ($tpl) {
                    $subject = (string) $tpl->getLocalizedData('subject');
                    $body = (string) $tpl->getLocalizedData('body');
                }
            } catch (\Throwable $e) {
                Logger::warning($contextId, 'Paystack: could not load email template', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        if (trim($subject) === '') { $subject = $hardSubject; }
        if (trim($body) === '')    { $body    = $hardBody; }

        $fromEmail = (string) ($context->getData('contactEmail') ?: $context->getData('supportEmail') ?: '');
        $fromName  = (string) ($context->getData('contactName') ?: $context->getLocalizedName());
        if ($fromEmail !== '') {
            $mailable->from($fromEmail, $fromName);
        }

        if ($recipientUser) {
            $mailable->recipients([$recipientUser]);
        } elseif ($recipientEmail && !empty($recipientEmail[0])) {
            $mailable->to($recipientEmail[0], $recipientEmail[1] ?? null);
        }

        try {
            $mailable->subject($subject)->body($body);
            Mail::send($mailable);
        } catch (\Throwable $e) {
            Logger::error($contextId, 'Paystack: email send failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }


    public function getPublicKey(int $contextId): ?string
    {
        $test = (bool) $this->getSetting($contextId, 'testMode');
        return $test ? (string) $this->getSetting($contextId, 'testPublicKey') : (string) $this->getSetting($contextId, 'livePublicKey');
    }

    public function getSecretKey(int $contextId): ?string
    {
        $test = (bool) $this->getSetting($contextId, 'testMode');
        return $test ? (string) $this->getSetting($contextId, 'testSecretKey') : (string) $this->getSetting($contextId, 'liveSecretKey');
    }

    private function isPostRequest(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /**
     * Resolve the connecting client IP. When the direct peer is a private or
     * loopback address (i.e. a local reverse proxy), trust the last
     * X-Forwarded-For hop, which that proxy appended.
     */
    private function getClientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $isPrivate = $remote !== ''
            && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if ($isPrivate) {
            $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $hops = array_map('trim', explode(',', $xff));
                $last = end($hops);
                if ($last && filter_var($last, FILTER_VALIDATE_IP)) {
                    return $last;
                }
            }
        }
        return $remote;
    }

    public function isCurrencyAllowed(int $contextId, string $currency): bool
    {
        $currency = strtoupper($this->sanitizeInput($currency, 'alpha'));
        if ($currency === '') {
            Logger::warning($contextId, 'Paystack unsupported currency encountered', ['currency' => $currency]);
            return false;
        }
        $allowed = self::SUPPORTED_CURRENCIES;
        $ok = in_array($currency, $allowed, true);
        if (!$ok) {
            Logger::warning($contextId, 'Paystack unsupported currency encountered', ['currency' => $currency]);
        }
        return $ok;
    }

    private function validateInt($value): ?int
    {
        if ($value === null) { return null; }
        if (is_numeric($value)) { return (int) $value; }
        return null;
    }

    /**
     * Detect if current request is HTTPS (also respects common proxy headers)
     */
    public static function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        $xfp = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($xfp === 'https') return true;
        $xfs = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        if ($xfs === 'on') return true;
        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') return true;
        return false;
    }

    /**
     * @copydoc Plugin::getInstallEmailTemplatesFile
     */
    public function getInstallEmailTemplatesFile()
    {
        // Must be filesystem path (not plugin URL path)
        return __DIR__ . '/emailTemplates.xml';
    }

    /**
     * Add mailable to the list of mailables in the application
     */
    public function addMailable(string $hookName, array $args): void
    {
        $mailables = $args[0]; /** @var \Illuminate\Support\Collection $mailables */
        foreach ([PaymentConfirmation::class, PaymentConfirmationAdmin::class, PaymentFailed::class] as $mailableClass) {
            if (!$mailables->contains($mailableClass)) {
                $mailables->push($mailableClass);
            }
        }
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     *
     * Returning the migration here lets PKP's native install machinery
     * (Plugin::register → Installer::postInstall → Plugin::updateSchema, and
     * the installPluginVersion.php CLI tool) create the plugin tables.
     */
    public function getInstallMigration()
    {
        return new PaystackWebhookTableMigration();
    }
}
