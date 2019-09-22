<style type="text/css">
.organizer {
  border: 1px solid gray;
}
#iface_list td {
  padding: 5px;
}
</style>

<script type="text/javascript">
var node_types = <?php echo json_encode($node_types); ?>;
</script>

<div dojoType="dijit.layout.BorderContainer" design="headline" style="width:100%;height:100%;margin:0;padding:0">
  <div region="top" dojoType="dijit.MenuBar" splitter="false">
    <div id="itm_node_new" dojoType="dijit.MenuBarItem"><span>New</span></div>
    <div id="itm_node_delete" dojoType="dijit.MenuBarItem" disabled="true"><span>Delete</span></div>
    <script type="text/javascript">
      dojo.connect(dijit.byId('config_pane'), 'onLoad', function(){
        dojo.connect(dijit.byId('itm_node_new'), 'onClick', node_controller, 'notifyNew');
        dojo.connect(dijit.byId('itm_node_delete'), 'onClick', node_controller, 'notifyDeleteCurrent');
      });
      dojo.connect(node_controller, 'onSelect', function(){
        dijit.byId('itm_node_delete').attr('disabled', false);
      });
      dojo.connect(node_controller, 'onUnselect', function(){
        dijit.byId('itm_node_delete').attr('disabled', true);
      });
    </script>
  </div>
  <div region="leading" dojoType="dijit.layout.ContentPane" style="width:200px" splitter="true">

<div style="font-size:smaller;color:gray;text-align:center;margin:2px 0">&lt;- Drag to groups</div>

<div id="node_tree_div" style="position:absolute;left:0;right:0;top:23px;bottom:0;overflow:auto"></div>
<script type="text/javascript">
function LoadNodeTree() {
  var node_tree_div = dojo.byId('node_tree_div');
  if (node_tree_div.tree)
    node_tree_div.tree.destroy(true);
  if (node_tree_div.model)
    node_tree_div.model.destroy();
  if (!node_tree_div.store) {
    node_tree_div.store = new dojo.data.ItemFileWriteStore({
      url: '/group/ajax/read?return=' + encodeURIComponent(dojo.toJson([{
        model: 'prov_node',
        view: 'tree',
        as: 'root'
      }])),
      urlPreventCache: true,
      clearOnClose: true
    });
  }
  node_tree_div.model = new dijit.tree.ForestStoreModel({
    store: node_tree_div.store,
    rootId: 'node_root'
  });
  node_tree_div.tree = new dijit.Tree({
    id: 'node_tree',
    model: node_tree_div.model,
    showRoot: false,
    dndController: 'dijit.tree.dndSource',
    checkAcceptance: function(){return false;}
  });
  node_tree_div.appendChild(node_tree_div.tree.domNode);
  dojo.connect(node_tree_div.tree, 'onClick', function(item){
    node_controller.notifySelect(item);
  });
}

function RefreshNodeTree() {
  dojo.byId('node_tree_div').store.close();
  LoadNodeTree();
  node_controller.onUnselect();
}

LoadNodeTree();

dojo.connect(node_controller, 'onNew', function(){
  dojo.byId('node_tree_div').store.fetch({
    query: {id: 'newNode'},
    onComplete: function(items, request){
      var SelectNew = function(){
        dojo.byId('node_tree_div').tree.set('path', ['node_root', 'newNode']);
        node_controller.notifySelect({id: 'newNode', name: '[New Node]'});
      };
      if (items.length <= 0) {
        var store = dojo.byId('node_tree_div').store;
        store.newItem({id: 'newNode', name: '[New Node]'});
        store.save({onComplete: SelectNew});
      } else
        SelectNew();
    }
  });
});
dojo.connect(node_controller, 'onSave', RefreshNodeTree);
dojo.connect(node_controller, 'onDelete', RefreshNodeTree);
</script>

  </div>
  <div region="center" dojoType="dijit.layout.ContentPane" style="padding:0">

<div class="organizer" id="node_form" dojoType="dijit.form.Form"
 encType="multipart/form-data" action="" method=""
 style="position:absolute;left:5px;width:300px;top:5px;height:200px">
<input name="action" type="hidden" dojoType="dijit.form.TextBox" />
<input name="node_id" type="hidden" dojoType="dijit.form.TextBox" />
<table border="0" cellpadding="0" cellspacing="3">
<tr>
  <th class="label"><label for="node_name">Name:</label></th>
  <td>
    <input name="node_name" type="text" dojoType="dijit.form.ValidationTextBox"
    required="true" trim="true" maxLength="30" style="width:15em"
    disabled="disabled" />
  </td>
</tr>
<tr>
  <th class="label"><label for="node_type">Type:</label></th>
  <td>
    <select id="node_type" name="node_type" dojoType="dijit.form.Select" disabled="disabled">
<?php foreach ($node_types as $node_type_id => $node_type) { ?>
      <option value="<?php echo $node_type_id; ?>"><?php echo $node_type['name']; ?></option>
<?php } ?>
    </select>
  </td>
</tr>
<tr>
  <th class="label"><label for="node_ip">IP:</label></th>
  <td>
    <input id="node_ip" name="node_ip" type="text"
    dojoType="dijit.form.ValidationTextBox" maxLength="15" style="width:10em"
    validator="IPValidator" disabled="disabled" />
  </td>
</tr>
<tr>
  <th class="label">Credentials:</th>
  <td>
    <div>
      <select id="cred_type" name="credential_type" dojoType="dijit.form.Select" disabled="disabled">
        <option value="rsa">Username + server's RSA key</option>
        <option value="userpass">Username + password</option>
        <script type="dojo/connect" event="onChange" args="newVal">
        if (newVal == 'userpass')
          dojo.byId('changecreds_link').innerHTML = 'Change username/password';
        else
          dojo.byId('changecreds_link').innerHTML = 'Change username';
        </script>
      </select>
    </div>
    <div id="div_cred_change" style="display:none">
      <a id="changecreds_link" href="#" onclick="return node_controller.notifyChangeCredentials();"></a>
    </div>
  </td>
</tr>
<tr>
  <th class="label"><label for="snmp_comm">SNMP Read<br />Community:</label></th>
  <td>
    <input name="snmp_comm" type="text" dojoType="dijit.form.ValidationTextBox"
    trim="true" maxLength="100" style="width:10em"
    disabled="disabled" />
  </td>
</tr>
<tr>
  <td></td>
  <td><button id="node_submit" dojoType="dijit.form.Button" type="submit"
  disabled="disabled">Save</button></td>
</tr>
</table>
<script type="dojo/connect" event="onSubmit" args="e">
e.preventDefault();
if (dijit.byId('node_form').isValid())
  node_controller.notifySaveCurrent();
</script>
</div>
<script type="text/javascript">
function IPValidator(value, constraints) {
  if (value.length == 0)
    return true;
  var subs = value.toString().split('.');
  if (subs.length != 4)
    return false;
  for (var i = 0; i < 4; i++) {
    if (!/^[0-9]{1,3}$/.exec(subs[i]))
      return false;
    if (parseInt(subs[i]) < 0 || parseInt(subs[i]) > 255)
      return false;
  }
  return true;
}

dojo.connect(node_controller, 'onSelect', function(node){
  var node_form = dijit.byId('node_form');
  dojo.forEach(dijit.findWidgets(node_form.domNode), function(widget){
    widget.attr('disabled', false);
  });
  if (node.id == 'newNode') {
    dijit.getEnclosingWidget(dojo.query('[name=action]', 'node_form')[0]).attr('value', 'create');
    dijit.getEnclosingWidget(dojo.query('[name=node_id]', 'node_form')[0]).attr('value', '');
    dijit.getEnclosingWidget(dojo.query('[name=node_name]', 'node_form')[0]).attr('value', '');
    dijit.getEnclosingWidget(dojo.query('[name=node_ip]', 'node_form')[0]).attr('value', '');
    dijit.getEnclosingWidget(dojo.query('[name=snmp_comm]', 'node_form')[0]).attr('value', '');
    dojo.style('div_cred_change', 'display', 'none');
  } else {
    dijit.getEnclosingWidget(dojo.query('[name=action]', 'node_form')[0]).attr('value', 'update');
    dijit.getEnclosingWidget(dojo.query('[name=node_id]', 'node_form')[0]).attr('value', node.id);
    dijit.getEnclosingWidget(dojo.query('[name=node_name]', 'node_form')[0]).attr('value', node.name);
    dijit.getEnclosingWidget(dojo.query('[name=node_ip]', 'node_form')[0]).attr('value', node.ip);
    dijit.getEnclosingWidget(dojo.query('[name=snmp_comm]', 'node_form')[0]).attr('value', node.snmp_comm);
    var node_type = dijit.byId('node_type');
    node_type.attr('value', node.node_type);
    var cred_type = dijit.byId('cred_type');
    cred_type.attr('value', (node.cred_encpass) ? 'userpass' : 'rsa');
    dojo.byId('changecreds_link').innerHTML = (node.cred_encpass) ?
    'Change username/password' : 'Change username';
    dojo.style('div_cred_change', 'display', 'block');
  }
});
dojo.connect(node_controller, 'onUnselect', function(){
  dojo.forEach(dijit.findWidgets(dojo.byId('node_form')), function(widget){
    widget.attr('disabled', true);
  });
  dijit.getEnclosingWidget(dojo.query('[name=action]', 'node_form')[0]).attr('value', '');
  dijit.getEnclosingWidget(dojo.query('[name=node_id]', 'node_form')[0]).attr('value', '');
  dijit.getEnclosingWidget(dojo.query('[name=node_name]', 'node_form')[0]).attr('value', '');
  dijit.getEnclosingWidget(dojo.query('[name=node_ip]', 'node_form')[0]).attr('value', '');
  dijit.getEnclosingWidget(dojo.query('[name=snmp_comm]', 'node_form')[0]).attr('value', '');
  dojo.style('div_cred_change', 'display', 'none');
});
</script>

<div class="organizer" style="position:absolute;left:5px;width:300px;top:210px;bottom:5px;overflow:auto">
<div style="font-weight:bold;margin:5px">Member of these groups:</div>
<div id="node_groups_list"></div>
<script type="text/javascript">
function UpdateNodeGroups(node) {
  dojo.empty('node_groups_list');
  if (node.member_groups) {
    var tb = dojo.create('table', null, 'node_groups_list');
    dojo.forEach(node.member_groups, function(group){
      var tr = dojo.create('tr', null, tb);
      var td = dojo.create('td', null, tr);
      dojo.create('div', {style: 'margin:3px 10px', innerHTML: group.name}, td);
      dojo.create('td', {
        innerHTML: '&middot;<a href="#" onclick="node_controller.WithdrawNodeFromGroup('
        + group.id + ');return false;">Withdraw</a>&middot;'}, tr);
    });
  }
}
dojo.connect(node_controller, 'onSelect', UpdateNodeGroups);
dojo.connect(node_controller, 'onCurrentMemberGroupChange', UpdateNodeGroups);
dojo.connect(node_controller, 'onUnselect', function(){
  dojo.byId('node_groups_list').innerHTML = '';
});
</script>
</div>

<div class="organizer" style="position:absolute;left:310px;right:5px;top:5px;bottom:5px;overflow:auto">
<table border="0" cellpadding="0" cellspacing="0" style="margin:5px">
<tr>
  <td><b>Interfaces</b></td>
  <td align="right"><button id="btn_rescan_ifaces" dojoType="dijit.form.Button"
  type="button" disabled="true">Re-Scan</button></td>
</tr>
</table>
<script type="text/javascript">
dojo.connect(dijit.byId('config_pane'), 'onLoad', function(){
  dojo.connect(dijit.byId('btn_rescan_ifaces'), 'onClick', node_controller, 'notifyRescan');
});
dojo.connect(node_controller, 'onSelect', function(node){
  if (node.node_type == 0 || node.id == 'newNode')
    dijit.byId('btn_rescan_ifaces').attr('disabled', true);
  else
    dijit.byId('btn_rescan_ifaces').attr('disabled', false);
});
dojo.connect(node_controller, 'onUnselect', function(){
  dijit.byId('btn_rescan_ifaces').attr('disabled', true);
});
</script>
<div id="iface_list" style="margin:5px">
</div>
<script type="text/javascript">
function UpdateIfaces(ifaces) {
  dojo.empty('iface_list');
  var tb = dojo.create('table', {border: '0', cellpadding: '0', cellspacing: '0'}, 'iface_list');
  dojo.forEach(ifaces, function(iface){
    var tr = dojo.create('tr', null, tb);
    var td = dojo.create('td', null, tr);
    td.innerHTML = (iface.lag_ct === null) ? iface.tid : 'LAG';
    td = dojo.create('td', null, tr);
    td.innerHTML = (iface.lag_ct === null) ? iface.descr : iface.tid;
    td = dojo.create('td', null, tr);
    if (iface.lag_ct > 0)
      td.innerHTML = '(' + (parseInt(iface.speed) / 1000) + 'G&times;' + iface.lag_ct + ')';
    td = dojo.create('td', null, tr);
    if (iface.unmg_id) {
      td.innerHTML = "&middot;<a href=\"#\" onclick=\"return node_controller.notifyIfaceUnlink('"
      + escape(iface.id) + "', '" + escape(iface.unmg_id)
      + "', true);\">Unlink</a>&middot;";
    } else if (iface.link_tid) {
      td.innerHTML = "&middot;<a href=\"#\" onclick=\"return node_controller.notifyIfaceUnlink('"
      + escape(iface.id) + "', '" + escape(iface.link_id)
      + "', false);\">Unlink</a>&middot;";
    } else {
      td.innerHTML = "&middot;<a href=\"#\" onclick=\"return node_controller.notifyIfaceLink('"
      + escape(iface.id) + "');\">Link</a>&middot;";
    }
    td = dojo.create('td', null, tr);
    if (iface.unmg_name)
      td.innerHTML = '[' + iface.unmg_name + ']';
    else if (iface.link_tid)
      td.innerHTML = '[' + iface.link_node_name + ' ' + iface.link_tid + ']';
  });
}
dojo.connect(node_controller, 'onSelect', function(node){
  if (node.node_type == 0)
    dojo.byId('iface_list').innerHTML = '(Non-Manageable node)';
  else if (node.ifaces)
    UpdateIfaces(node.ifaces);
  else
    dojo.byId('iface_list').innerHTML = '';
});
dojo.connect(node_controller, 'onUnselect', function(){
  dojo.byId('iface_list').innerHTML = '';
});
dojo.connect(node_controller, 'onIfacesChange', function(ifaces){
  UpdateIfaces(ifaces);
});
</script>
</div>

  </div>
</div>
