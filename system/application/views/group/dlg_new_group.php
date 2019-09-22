<div id="dlg_new_group" dojoType="dijit.Dialog" title="New Group" style="display:none">
<table>
<tr>
  <th align="right">Name:</th>
  <td><input id="group_new_name_txt" type="text" dojoType="dijit.form.ValidationTextBox" trim="true"
  style="width:15em" maxLength="30" required="true" /></td>
</tr>
<tr>
  <td colspan="2" align="center">
    <button dojoType="dijit.form.Button" type="button">
      OK
      <script type="dojo/connect" event="onClick" args="e">
      e.preventDefault();
      if (dijit.byId('dlg_new_group').isValid()) {
        group_controller.notifyNewGroupOK(dijit.byId('group_new_name_txt').attr('value'));
        dijit.byId('dlg_new_group').hide();
      }
      </script>
    </button>
    <button dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_new_group').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('group_new_name_txt').attr('value', '');
dijit.byId('group_new_name_txt').reset();
</script>
</div>
