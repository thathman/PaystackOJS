{**
 * Transaction details
 *}
<div class="pkp_form psx-panel">
  <style>
    .psx-table { width:100%; border-collapse: collapse; }
    .psx-table th, .psx-table td { padding:8px 10px; border-bottom:1px solid #e5e7eb; text-align:left; }
    .psx-table th { background:#f3f4f6; font-weight:600; color:#374151; }
    .psx-actions { margin-top:10px; }
  </style>
  <h3>Transaction Details</h3>
  <table class="psx-table">
    <tbody>
      <tr><th>ID</th><td>{$payment->getId()}</td></tr>
      <tr><th>Type</th><td>{$payment->getType()}</td></tr>
      <tr><th>Assoc ID</th><td>{$payment->getAssocId()}</td></tr>
      <tr><th>Amount</th><td>{$payment->getAmount()} {$payment->getCurrencyCode()}</td></tr>
      <tr><th>Reference</th><td>{$meta.reference|escape}</td></tr>
      <tr><th>Transaction ID</th><td>{$meta.transactionId|escape}</td></tr>
    </tbody>
  </table>
  <div class="psx-actions">
    {if $meta.reference}
      <a class="pkpButton" href="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="paymethod" plugin=$pluginName verb="transactionDetails" paymentId=$payment->getId() reverify=true}">Re-verify with Paystack</a>
    {/if}
  </div>
  {if $meta.verify}
    <h4 style="margin-top:12px">Latest Verification</h4>
    <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:10px;border-radius:6px;">{$meta.verify|@json_encode:128|escape}</pre>
  {/if}
  {if $meta.verify_error}
    <p style="color:#b91c1c">{$meta.verify_error|escape}</p>
  {/if}
</div>

