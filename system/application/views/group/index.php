<script type="text/javascript">
<?php $this->load->file('system/application/controllers/group.js'); ?>
</script>

<div dojoType="dijit.layout.BorderContainer" design="sidebar" style="width:100%;height:100%">
  <div region="leading" dojoType="dijit.layout.AccordionContainer" style="width:200px" splitter="true">
    <div title="Groups" dojoType="dijit.layout.ContentPane" style="padding:0">

<div id="groupmenubar" dojoType="dijit.MenuBar">
<div id="btn_new_group" dojoType="dijit.MenuBarItem"><span>New</span></div>
<div id="btn_del_group" dojoType="dijit.MenuBarItem" disabled="true"><span>Delete</span></div>
<div id="btn_ren_group" dojoType="dijit.MenuBarItem" disabled="true"><span>Rename</span></div>
</div>
<script type="text/javascript">
dojo.connect(group_controller, 'onSelect', function(){
  dijit.byId('btn_del_group').attr('disabled', false);
  dijit.byId('btn_ren_group').attr('disabled', false);
});
dojo.connect(group_controller, 'onUnselect', function(){
  dijit.byId('btn_del_group').attr('disabled', true);
  dijit.byId('btn_ren_group').attr('disabled', true);
});
dojo.addOnLoad(function(){
  dojo.connect(dijit.byId('btn_new_group'), 'onClick', group_controller, 'notifyNew');
  dojo.connect(dijit.byId('btn_del_group'), 'onClick', group_controller, 'notifyDeleteCurrent');
  dojo.connect(dijit.byId('btn_ren_group'), 'onClick', group_controller, 'notifyRenameCurrent');
});
</script>

<div></div><!-- This is here due to some weird rendering bug... -->

<div id="group_tree_div"></div>
<script type="text/javascript">
function LoadGroupTree() {
  var group_tree_div = dojo.byId('group_tree_div');
  if (group_tree_div.tree)
    group_tree_div.tree.destroy(true);
  if (group_tree_div.model)
    group_tree_div.model.destroy();
  if (!group_tree_div.store) {
    group_tree_div.store = new dojo.data.ItemFileReadStore({
      url: '/group/ajax/read?return=' + encodeURIComponent(dojo.toJson([{
        model: 'prov_group',
        view: 'tree',
        as: 'root'
      }])),
      urlPreventCache: true,
      clearOnClose: true
    });
  }
  group_tree_div.model = new dijit.tree.ForestStoreModel({
    store: group_tree_div.store,
    rootId: 'group_root',
    rootLabel: 'SECOM'
  });
  group_tree_div.tree = new dijit.Tree({
    id: 'group_tree',
    model: group_tree_div.model,
    showRoot: true,
    dndController: 'dijit.tree.dndSource',
    checkAcceptance: function(source, nodes){
      return (source.tree
      && (source.tree.id == 'node_tree' || source.tree.id == 'group_tree')
      && source.getItem(nodes[0].id).data.item.id != 'newNode')
      ? true : false;
    },
    checkItemAcceptance: function(target, source, position){
      return (dijit.getEnclosingWidget(target).item.id != 'group_root') ? true : false;
    },
    onDndDrop: function(source, nodes, copy){
      group_controller.notifyDndDrop(source, nodes, copy);
      this.onDndCancel();
    }
  });
  group_tree_div.appendChild(group_tree_div.tree.domNode);
  dojo.connect(group_tree_div.tree, '_onNodeMouseEnter', group_controller, 'notifyTreeNodeMouseEnter');
  dojo.connect(group_tree_div.tree, '_onNodeMouseLeave', group_controller, 'notifyTreeNodeMouseLeave');
  dojo.connect(group_tree_div.tree, 'onClick', group_controller, 'notifySelect');
}

function RefreshGroupTree() {
  dojo.byId('group_tree_div').store.close();
  LoadGroupTree();
  group_controller.notifyUnselect();
}

dojo.connect(group_controller, 'onGroupsChange', RefreshGroupTree);
dojo.addOnLoad(function(){
  LoadGroupTree();
});
</script>

    </div>
  </div>
  <div id="tab_view_main" region="center" dojoType="dijit.layout.TabContainer">
    <div id="provision_pane" title="Provision"
    dojoType="dojox.layout.ContentPane" renderStyles="true" preventCache="true"
    style="padding:0">
      <div style="margin:26px">
      &lt;- Select a group to provision from the Groups menu.
      </div>
    </div>
    <div id="config_pane" title="Setup" dojoType="dojox.layout.ContentPane"
     href="/group/config" renderStyles="true"></div>
  </div>
</div>
<script type="text/javascript">
dojo.connect(group_controller, 'onUnselect', function(){
  dijit.byId('provision_pane').set('content',
  '<div style="margin:26px">&lt;- Select a group to provision from the Groups menu.</div>');
});
</script>

<?php
$this->load->view('group/dlg_del_confirm');
$this->load->view('group/dlg_new_group');
$this->load->view('group/dlg_load_vlans');
$this->load->view('group/dlg_scan_node');
$this->load->view('group/dlg_apply_changes');
$this->load->view('group/dlg_set_creds');
$this->load->view('group/dlg_iface_link');
?>
