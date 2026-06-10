{**
 * plugins/paymethod/paystack/templates/paymentReceipt.tpl
 *
 * Neutral, single-column default receipt page for one completed payment.
 * Themes may override this at templates/plugins/paymethod/paystack/templates/.
 *
 * @uses $paymentName $currencySymbol $amount $transactionReference
 *       $transactionId $paymentDate $returnUrl
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.paymethod.paystack.paymentReceipt.title"}

<div class="page page_paystack">
<main class="pmt-main">
  <div class="pmt-container">
    <div class="pmt-payment-card pmt-payment-card--success">
      <div class="pmt-payment-card__header">
        <h1>{translate key="plugins.paymethod.paystack.paymentReceipt.title"}</h1>
      </div>
      <div class="pmt-payment-card__body">
        <div class="pmt-payment-details__info">
          <div class="pmt-payment-details__row">
            <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.paymentDetails.paymentFor"}:</span>
            <span class="pmt-payment-details__value">{$paymentName|escape}</span>
          </div>
          <div class="pmt-payment-details__row">
            <span class="pmt-payment-details__label">{translate key="plugins.paymethod.paystack.paymentDetails.amount"}:</span>
            <span class="pmt-payment-details__value pmt-payment-details__amount"><strong>{$currencySymbol|escape}{$amount|string_format:"%.2f"}</strong></span>
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
    <div class="pmt-payment-confirmation__actions" style="margin-top:1.25rem;">
      <a href="{$returnUrl|escape}" class="pmt-btn pmt-btn--primary">{translate key="plugins.paymethod.paystack.paymentHistory.viewHistory"}</a>
    </div>
  </div>
</main>
</div>

{include file="frontend/components/footer.tpl"}
