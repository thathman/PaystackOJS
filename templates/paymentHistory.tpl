{**
 * plugins/paymethod/paystack/templates/paymentHistory.tpl
 *
 * Neutral, single-column default page listing the user's completed payments.
 * Themes may override this at templates/plugins/paymethod/paystack/templates/.
 *
 * @uses $payments array<int, array> Completed-payment rows for the current user
 * @uses $pluginName string
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.paymethod.paystack.paymentHistory.title"}

<div class="page page_paystack">
<main class="pmt-main">
  <div class="pmt-container">
    <h1>{translate key="plugins.paymethod.paystack.paymentHistory.title"}</h1>
    <p class="lead">{translate key="plugins.paymethod.paystack.paymentHistory.description"}</p>

    {if $payments|@count > 0}
      <table class="pmt-table" style="width:100%;border-collapse:collapse;margin-top:1.5rem;">
        <thead>
          <tr style="text-align:left;border-bottom:2px solid rgba(0,0,0,.12);">
            <th style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.date"}</th>
            <th style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.itemDescription"}</th>
            <th style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.amount"}</th>
            <th style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.status"}</th>
            <th style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.actions"}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$payments item=payment}
            <tr style="border-bottom:1px solid rgba(0,0,0,.08);">
              <td style="padding:.6rem .5rem;">{$payment.formattedDate|escape}</td>
              <td style="padding:.6rem .5rem;">{$payment.paymentName|escape}</td>
              <td style="padding:.6rem .5rem;"><strong>{$payment.currencySymbol|escape}{$payment.amount|string_format:"%.2f"}</strong></td>
              <td style="padding:.6rem .5rem;">{translate key="plugins.paymethod.paystack.paymentHistory.completed"}</td>
              <td style="padding:.6rem .5rem;">
                <a href="{url page="payment" op="plugin" path=$pluginName|to_array:"receipt":$payment.id}">{translate key="plugins.paymethod.paystack.paymentHistory.viewReceipt"}</a>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <div class="pmt-payment-card" style="margin-top:1.5rem;">
        <h3>{translate key="plugins.paymethod.paystack.paymentHistory.emptyTitle"}</h3>
        <p>{translate key="plugins.paymethod.paystack.paymentHistory.empty"}</p>
      </div>
    {/if}
  </div>
</main>
</div>

{include file="frontend/components/footer.tpl"}
