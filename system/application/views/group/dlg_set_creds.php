<div id="dlg_set_creds" dojoType="dijit.Dialog" title="Set Management Credentials" style="display:none">
<table>
<tr>
  <th align="right">Node:</th>
  <td id="set_creds_node_name"></td>
</tr>
<tr>
  <th align="right">Username:</th>
  <td><input id="set_creds_username" type="text" dojoType="dijit.form.ValidationTextBox" trim="true"
  style="width:15em" maxLength="30" required="true" /></td>
</tr>
<tr id="tr_set_creds_pass">
  <th align="right">Password:</th>
  <td><input id="set_creds_pass" type="password" dojoType="dijit.form.ValidationTextBox" trim="true"
  style="width:15em" maxLength="30" /></td>
</tr>
<tr>
  <td colspan="2" align="center">
    <button dojoType="dijit.form.Button" type="button">
      OK
      <script type="dojo/connect" event="onClick" args="e">
      e.preventDefault();
      if (dijit.byId('dlg_set_creds').isValid()) {
        node_controller.notifySetCredsOK(
        dijit.byId('set_creds_username').attr('value'),
        dijit.byId('set_creds_pass').attr('value'));
        dijit.byId('dlg_set_creds').hide();
      }
      </script>
    </button>
    <button dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_set_creds').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
if (dijit.byId('cred_type').attr('value') == 'userpass')
  dojo.style('tr_set_creds_pass', 'display', 'table-row');
else
  dojo.style('tr_set_creds_pass', 'display', 'none');
dijit.byId('set_creds_username').attr('value', '');
dijit.byId('set_creds_username').reset();
dijit.byId('set_creds_pass').attr('value', '');
dijit.byId('set_creds_pass').reset();
</script>
</div>
