{**
 * plugins/paymethod/paystack/templates/paymentConfirmation.tpl
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Payment confirmation page after successful payment
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.paymethod.paystack.paymentConfirmation.title"}

<div class="page page_paystack">
<main class="pmt-main">
    <div class="pmt-container">
        <div class="pmt-payment-confirmation">
            <div class="pmt-payment-confirmation__header">
                <div class="pmt-payment-confirmation__icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                        <path d="M8 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1>{translate key="plugins.paymethod.paystack.paymentConfirmation.title"}</h1>
                <p class="lead">{translate key="plugins.paymethod.paystack.paymentConfirmation.description"}</p>
            </div>

            <div class="pmt-payment-confirmation__content">
                <div class="pmt-payment-card pmt-payment-card--success">
                    <div class="pmt-payment-card__header">
                        <h2>{translate key="plugins.paymethod.paystack.paymentConfirmation.paymentDetails"}</h2>
                    </div>
                    <div class="pmt-payment-card__body">
                        <div class="pmt-payment-details__info">
                            <div class="pmt-payment-details__row">
                                <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.paymentDetails.paymentFor"}:</span>
                                <span class="pmt-payment-details__value">{$paymentName|escape}</span>
                            </div>
                            <div class="pmt-payment-details__row">
                                <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.paymentDetails.amount"}:</span>
                                <span class="pmt-payment-details__value pmt-payment-details__amount">
                                    <strong>{$currencySymbol|escape}{$amount|escape|number_format:2}</strong>
                                </span>
                            </div>
                            {if $transactionReference}
                            <div class="pmt-payment-details__row">
                                <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.email.paymentReference"}:</span>
                                <span class="pmt-payment-details__value"><code>{$transactionReference|escape}</code></span>
                            </div>
                            {/if}
                            {if $transactionId}
                            <div class="pmt-payment-details__row">
                                <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.email.transactionId"}:</span>
                                <span class="pmt-payment-details__value"><code>{$transactionId|escape}</code></span>
                            </div>
                            {/if}
                            <div class="pmt-payment-details__row">
                                <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.email.paymentDate"}:</span>
                                <span class="pmt-payment-details__value">{$paymentDate|escape}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pmt-payment-confirmation__message">
                    <p>{translate key="plugins.paymethod.paystack.paymentConfirmation.successMessage"}</p>
                </div>

                <div class="pmt-payment-confirmation__actions">
                    <a href="{$returnUrl|escape}" class="pmt-btn pmt-btn--primary">
                        {translate key="plugins.paymethod.paystack.paymentConfirmation.returnToArticle"}
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

{include file="frontend/components/footer.tpl"}
