<script type="text/javascript">
function DlgIfaceLinkClickUnmgdNode(node_id) {
  dijit.byId('dlg_iface_link').sb_confirm_link = true;
  dojo.publish('sbgroup/dlg_iface_link/close',
  [true, dijit.byId('dlg_iface_link').sb_iface_src_id, node_id, true]);
  dijit.byId('dlg_iface_link').hide();
  return false;
}
function DlgIfaceLinkClickMgdNode(node_id, node_name) {
  XhrJsonGet('/group/ajax/read', {preventCache: true, content: {'return': dojo.toJson([{
    model: 'prov_iface',
    view: 'linklist',
    as: 'ifaces',
    node_id: node_id
  }])}}).then(dojo.hitch(dijit.byId('dlg_iface_link'), function(data){
    dojo.empty('dst_iface_table');
    var tb = dojo.create('table', null, 'dst_iface_table');
    dojo.forEach(data.ifaces, function(iface){
      var tr = dojo.create('tr', null, tb);
      var ih = "<a href=\"#\" onclick=\"return DlgIfaceLinkClickIface('"
      + escape(iface.id) + "');\">";
      if (iface.lag_ct !== null)
        ih += 'LAG: ' + iface.tid;
      else
        ih += '' + iface.tid + ': ' + iface.descr;
      ih += "</a>";
      dojo.create('td', {innerHTML: ih}, tr);
    });
  }));
  dojo.byId('iface_hdr').innerHTML = unescape(node_name);
  return false;
}
function DlgIfaceLinkClickIface(iface_id) {
  dijit.byId('dlg_iface_link').sb_confirm_link = true;
  dojo.publish('sbgroup/dlg_iface_link/close',
  [true, dijit.byId('dlg_iface_link').sb_iface_src_id, iface_id, false]);
  dijit.byId('dlg_iface_link').hide();
  return false;
}
</script>

<div id="dlg_iface_link" dojoType="dijit.Dialog" title="Link Interfaces" style="display:none">
<table>
<tr>
  <th colspan="2" align="left" id="dlg_iface_src"></th>
</tr>
<tr>
  <td valign="top"><b>Link to:</b><br /><div id="dst_node_table"></div></td>
  <td valign="top"><b id="iface_hdr"></b><br /><div id="dst_iface_table"></div></td>
</tr>
<tr>
  <td colspan="2" align="center">
    <button dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_iface_link').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('dlg_iface_link').sb_confirm_link = false;
for (var i in this.sb_node.ifaces) {
  if (this.sb_node.ifaces[i].id == this.sb_iface_src_id)
    dojo.byId('dlg_iface_src').innerHTML = 'Link from: ' + this.sb_node.name + ' ' + this.sb_node.ifaces[i].tid;
}
XhrJsonGet('/group/ajax/read', {preventCache: true, content: {'return': dojo.toJson([{
  model: 'prov_node',
  view: 'linklist',
  as: 'nodes',
  node_id: this.sb_node.id
}])}}).then(function(data){
  dojo.empty('dst_node_table');
  var tb = dojo.create('table', null, 'dst_node_table');
  dojo.forEach(data.nodes, function(node){
    var tr = dojo.create('tr', null, tb);
    var ih = "<tr><td><a href=\"#\" onclick=\"return ";
    ih += (node.node_type == 0) ? "DlgIfaceLinkClickUnmgdNode" : "DlgIfaceLinkClickMgdNode";
    ih += "('" + escape(node.id) + "', '" + escape(node.name) + "');\">"
    + node.name + "</a></td></tr>";
    dojo.create('td', {innerHTML: ih}, tr);
  });
});
dojo.empty('dst_iface_table');
dojo.byId('iface_hdr').innerHTML = '';
</script>
<script type="dojo/connect" event="onHide">
if (!dijit.byId('dlg_iface_link').sb_confirm_link)
  dojo.publish('sbgroup/dlg_iface_link/close', [false]);
</script>
</div>
