{**
 * Transactions list
 *}
<div class="pkp_form psx-panel">
  <style>
    .psx-table { width: 100%; border-collapse: collapse; }
    .psx-table th, .psx-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; }
    .psx-table th { background: #f3f4f6; font-weight: 600; color: #374151; }
    .psx-table tr:nth-child(even) { background: #fafafa; }
    .psx-table a.pkpButton { padding: 4px 8px; }
    .psx-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .psx-header h3 { margin:0; }
  </style>
  <div class="psx-header">
    <h3>{translate key="plugins.paymethod.paystack.transactions.tab"}</h3>
  </div>
  {if $transactions && count($transactions)}
    <table class="psx-table">
      <thead>
        <tr>
          <th>{translate key="plugins.paymethod.paystack.transactions.id"}</th>
          <th>{translate key="plugins.paymethod.paystack.transactions.type"}</th>
          <th>{translate key="plugins.paymethod.paystack.transactions.assocId"}</th>
          <th>{translate key="plugins.paymethod.paystack.transactions.amount"}</th>
          <th>{translate key="plugins.paymethod.paystack.transactions.reference"}</th>
          <th></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$transactions item=row}
          <tr>
            <td>{$row.id}</td>
            <td>{$row.type}</td>
            <td>{$row.assocId}</td>
            <td>{$row.amountFormatted}</td>
            <td>{$row.reference|escape}</td>
            <td>{if $row.viewUrl}<a class="pkpButton" href="{$row.viewUrl}" target="_blank" rel="noopener">{translate key="plugins.paymethod.paystack.transactions.viewOnPaystack"}</a>{/if}</td>
            <td>
              {if $row.detailsUrl}<a class="pkpButton" href="{$row.detailsUrl}" data-modal="ajax">Details</a>{/if}
              {if $row.refundUrl}<a class="pkpButton" href="{$row.refundUrl}" data-modal="ajax">Refund</a>{/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p>{translate key="grid.noItems"}</p>
  {/if}
</div>
