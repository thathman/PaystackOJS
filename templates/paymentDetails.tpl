{**
 * plugins/paymethod/paystack/templates/paymentDetails.tpl
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Payment details page with Pay button
 *}
{include file="frontend/components/header.tpl" pageTitle="common.payment"}

<div class="page page_paystack">
<main class="pmt-main">
    <div class="pmt-container">
        <div class="pmt-payment-details">
            <div class="pmt-payment-details__header">
                <h1>{translate key="common.payment"}</h1>
                <p class="lead">{translate key="plugins.paymethod.paystack.paymentDetails.description"}</p>
            </div>

            <div class="pmt-payment-details__content">
                <div class="pmt-payment-card">
                    <div class="pmt-payment-card__header">
                        <h2>{translate key="plugins.paymethod.paystack.paymentDetails.paymentInformation"}</h2>
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
                        </div>
                    </div>
                </div>

                <div class="pmt-payment-details__actions">
                    <form method="post" action="{$initiatePaymentUrl|escape}" class="pmt-payment-form">
                        {csrf}
                        <button type="submit" class="pmt-btn pmt-btn--primary">
                            {translate key="plugins.paymethod.paystack.paymentDetails.payNow"}
                        </button>
                        <a href="{$cancelUrl|escape}" class="pmt-btn pmt-btn--ghost">
                            {translate key="common.cancel"}
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

{include file="frontend/components/footer.tpl"}
