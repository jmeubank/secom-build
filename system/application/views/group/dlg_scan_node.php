<div id="dlg_scan_node" dojoType="dijit.Dialog" title="Scanning node" style="display:none">
<table>
<tr>
  <td valign="top">Scanning node...</td>
  <td valign="top"></td>
</tr>
<tr>
  <td colspan="2" align="center">
    <button dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_scan_node').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('dlg_scan_node').sb_normal_closure = false;
node_controller.notifyNodeScanDlgReady();
</script>
<script type="dojo/connect" event="onHide">
if (!dijit.byId('dlg_scan_node').sb_normal_closure)
  node_controller.notifyNodeScanCancel();
</script>
</div>
