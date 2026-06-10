{**
 * Refund form
 *}
<div class="pkp_form psx-panel">
  <style>
    .psx-card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:14px; margin:12px 0; }
  </style>
  <h3>Refund Payment</h3>
  <div class="psx-card">
    <p><strong>Payment ID:</strong> {$payment->getId()} &nbsp; <strong>Amount:</strong> {$payment->getAmount()} {$payment->getCurrencyCode()}</p>
    <p><strong>Reference:</strong> {$meta.reference|escape}</p>
    <form method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="paymethod" plugin=$pluginName verb="refund" paymentId=$payment->getId() doRefund=true}">
      {csrf}
      <p>Enter amount to refund (leave empty for full refund):</p>
      <p><input type="text" name="amount" class="field text" placeholder="{$payment->getAmount()}" /></p>
      <p><button type="submit" class="pkpButton pkpButton--isPrimary">Submit Refund</button></p>
    </form>
    {if isset($result)}
      {if $result.ok}
        <p style="color:#065f46">{$result.message|escape}</p>
      {else}
        <p style="color:#b91c1c">{$result.message|escape}</p>
      {/if}
    {/if}
  </div>
</div>

