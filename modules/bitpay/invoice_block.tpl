<fieldset style="width: 140px; margin-right: 300px;">

<legend>

     {l s='BitPay Information' mod='bitpay'}

</legend>

<div id="info">
	<table>
	<tr>
		<td align="left" valign="top">{l s='Invoice:' mod='bitpay'}</td>
		<td><a href="https://bitpay.com/invoice?id={$invoice_id}" title="" target="_blank">Open</a></td>
	</tr>
	<tr>
		<td align="left" valign="top">{l s='Status:' mod='bitpay'}</td>
		<td>{$status}</td>
	</tr>
	</table>
</div>

</fieldset>