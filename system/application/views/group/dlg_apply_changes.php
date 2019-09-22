<div id="dlg_apply_changes" dojoType="dijit.Dialog" title="Writing changes" style="display:none">
<table>
<tr>
  <td valign="top" id="apply_changes_status">
  </td>
</tr>
<tr>
  <td align="center">
    <button id="btn_hide_apply_changes" dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_apply_changes').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('btn_hide_apply_changes').attr('label', 'Cancel');
dojo.byId('apply_changes_status').innerHTML = '';
dijit.byId('dlg_apply_changes').sb_normal_closure = false;
dojo.empty('apply_changes_status');
dojo.create('table', {id: 'apply_changes_status_tb'}, 'apply_changes_status');
group_node_controller.notifyApplyChangesDlgReady();
</script>
<script type="dojo/connect" event="onHide">
if (!dijit.byId('dlg_apply_changes').sb_normal_closure)
  group_node_controller.notifyApplyChangesCancel();
</script>
</div>
