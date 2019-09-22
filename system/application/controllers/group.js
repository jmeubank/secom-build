var MAX_IFACES_PER_NODE = 10;
var IFACE_RADIUS = 7;
var NODE_CIRCUM = IFACE_RADIUS * 2 * MAX_IFACES_PER_NODE;
var NODE_RADIUS = NODE_CIRCUM / Math.PI / 2;
var IFACE_RADIANS = IFACE_RADIUS * 2 / NODE_RADIUS;
var NODE_SIDE = (NODE_RADIUS * 2 / (Math.SQRT2 + 1)) * 2;
var PIXEL_RATIO = 1 / (IFACE_RADIUS * 2);


var cred_controller = {
  current: null,

  notifySaveCurrent: function(){
  },
  notifySelect: function(item){
    this.current = item;
    this.onSelect(item);
  },

  onDelete: function(){
  },
  onNew: function(){
  },
  onSave: function(){
  },
  onSelect: function(item){
  },
  onUnselect: function(){
  }
};

var group_controller = {
  hovernode: null,
  current: null,
  dlg_subscription: null,

  LoadProvisioner: function(group_id){
    dojo.forEach(group_node_controller.prov_tab_connects, function(c){
      dojo.disconnect(c);
    });
    group_node_controller.prov_tab_connects = [];
    dijit.byId('tab_view_main').selectChild('provision_pane');
    dijit.byId('provision_pane').set('href', '/group/provision/' + group_id);
    group_node_controller.group_id = group_id;
    group_node_controller.allow_arrange = false;
  },

  notifyDndDrop: function(source, nodes, copy){
    if (!source.tree)
      return;
    if (!this.hovernode)
      return;
    var sourceItem = source.getItem(nodes[0].id);
    if (dojo.indexOf(sourceItem.type, "treeNode") == -1)
      return;
    if (source.tree.id == 'node_tree') {
      XhrJsonPost('/group/ajax/create/prov_group_node', {content: {
        group_id: this.hovernode.item.id,
        node_id: sourceItem.data.item.id,
        'return': dojo.toJson([{
          model: 'prov_group',
          view: 'by-node',
          as: 'groups',
          node_id: parseInt(sourceItem.data.item.id)
        }])
      }}).then(dojo.hitch(this, function(data){
        node_controller.notifyGroupMemberChange({
          id: sourceItem.data.item.id,
          member_groups: data.groups
        });
      }));
    } else if (source.tree.id == 'group_tree') {
      var parent_id = this.hovernode.item.id;
      if (parent_id == 'group_root')
        parent_id = null;
      XhrJsonPost('/group/ajax/update/prov_group', {content: {
        update_parent: true,
        parent: parent_id,
        child: sourceItem.data.item.id
      }}).then(dojo.hitch(this, this.onGroupsChange));
    }
  },

  notifyDeleteCurrent: function(){
    if (!this.current)
      return;
    this.dlg_subscription = dojo.subscribe('sbgroup/dlg_del/close', this, function(confirmed){
      dojo.unsubscribe(this.dlg_subscription);
      if (!confirmed)
        return;
      XhrJsonPost('/group/ajax/delete/prov_group', {content: {group_id: this.current.id}})
      .then(dojo.hitch(this, this.onGroupsChange));
    });
    dojo.byId('del_confirm_text').innerHTML =
      "Are you sure you want to delete group '"
      + this.current.name + "'?";
    dijit.byId('dlg_del_confirm').show();
  },

  notifyNew: function(){
    var dlg_new_group = dijit.byId('dlg_new_group');
    dlg_new_group.set('title', 'New Group');
    dlg_new_group.sb_newfunc = true;
    dlg_new_group.show();
  },
  notifyNewGroupOK: function(group_name){
    if (dijit.byId('dlg_new_group').sb_newfunc) {
      XhrJsonPost('/group/ajax/create/prov_group', {content: {group_name: group_name}})
      .then(dojo.hitch(this, this.onGroupsChange));
    } else {
      XhrJsonPost('/group/ajax/update/prov_group', {content: {
        group_id: this.current.id,
        group_name: group_name
      }}).then(dojo.hitch(this, this.onGroupsChange));
    }
  },

  notifyRenameCurrent: function(){
    var dlg_new_group = dijit.byId('dlg_new_group');
    dlg_new_group.set('title', 'Rename Group');
    dojo.query('#dlg_new_group input').attr('value', '' + this.current.name);
    dlg_new_group.sb_newfunc = false;
    dlg_new_group.show();
  },

  notifySelect: function(item){
    if (item.id == 'group_root')
      this.notifyUnselect();
    else {
      this.current = item;
      this.LoadProvisioner(item.id);
      group_node_controller.req_load_vlans = null;
      this.onSelect(item);
    }
  },
  notifyUnselect: function(){
    this.current = null;
    this.onUnselect();
  },

  notifyTreeNodeMouseEnter: function(widget, event){
    this.hovernode = widget;
  },
  notifyTreeNodeMouseLeave: function(widget, event){
    this.hovernode = null;
  },

  onGroupsChange: function(){
  },
  onSelect: function(group){
  },
  onUnselect: function(){
  }
};

var group_node_controller = {
  allow_arrange: false,
  group_id: 0,
  ifaceset: null,
  nodeset: null,
  prov_tab_connects: [],
  req_load_vlans: null,
  node_changes: {},
  iface_changes: {},

  GetChangeNode: function(vlan_id, node_name, iface_tid){
    var tr_nodes = dojo.query('#changes_table tr');
    var place_before = 0;
    for (place_before = 0; place_before < tr_nodes.length; ++place_before) {
      if (tr_nodes[place_before].sb_node_name == node_name
      && tr_nodes[place_before].sb_tid == iface_tid
      && tr_nodes[place_before].sb_vid == vlan_id)
        return tr_nodes[place_before];
      if (tr_nodes[place_before].sb_node_name > node_name)
        break;
      if (tr_nodes[place_before].sb_node_name != node_name)
        continue;
      if (tr_nodes[place_before].sb_tid > iface_tid)
        break;
      if (tr_nodes[place_before].sb_tid != iface_tid)
        continue;
      if (tr_nodes[place_before].sb_vid > vlan_id)
        break;
    }
    var ret_node = null;
    var ret_node_inner = '<tr>'
    + '<td>' + vlan_id + '</td>'
    + '<td class="action"></td>'
    + '<td>' + node_name + '</td>'
    + '<td>' + iface_tid + '</td>'
    + '</tr>';
    if (place_before < tr_nodes.length)
      ret_node = dojo.place(ret_node_inner, tr_nodes[place_before], 'before');
    else
      ret_node = dojo.place(ret_node_inner, 'changes_table', 'last');
    ret_node.sb_node_name = node_name;
    ret_node.sb_tid = iface_tid;
    ret_node.sb_vid = vlan_id;
    return ret_node;
  },
  DestroyChangeNodes: function(node_name, iface_tid){
    var tr_nodes = dojo.query('#changes_table tr');
    for (var i = 0; i < tr_nodes.length; ++i) {
      if (tr_nodes[i].sb_node_name == node_name
      && tr_nodes[i].sb_tid == iface_tid)
        dojo.destroy(tr_nodes[i]);
    }
  },

  IfaceSetVlan: function(iface, applyvlans, stored){
    iface.apply_vlans = applyvlans;
    if (typeof stored !== 'undefined')
      iface.has_vlans = stored;
    if (applyvlans == 2)
      iface.circle.setFill('green');
    else if (applyvlans == 1)
      iface.circle.setFill('yellow');
    else
      iface.circle.setFill('white');
    this.DestroyChangeNodes(iface.node.name, iface.tid);
    if (applyvlans != iface.has_vlans && iface.vlmembers) {
      for (var reqid in this.req_load_vlans) {
        var match = (iface.vlmembers[this.req_load_vlans[reqid].vlan_id]) ? true : false;
        if (match && applyvlans == 0) {
          var change_node = this.GetChangeNode(this.req_load_vlans[reqid].vlan_id, iface.node.name, iface.tid);
          dojo.query('td.action', change_node).innerHTML('remove member');
        } else if (!match && applyvlans == 2) {
          var change_node = this.GetChangeNode(this.req_load_vlans[reqid].vlan_id, iface.node.name, iface.tid);
          dojo.query('td.action', change_node).innerHTML('add member');
        }
      }
    }
  },
  LinkSetVlan: function(linegroup, applyvlans){
    if (applyvlans == 2)
      linegroup.children[0].setFill('#cfc');
    else if (applyvlans == 1)
      linegroup.children[0].setFill('#ff9');
    else
      linegroup.children[0].setFill('#eee');
  },
  NodeSetVlan: function(node, applyvlans, stored){
    node.apply_vlans = applyvlans;
    if (typeof stored !== 'undefined')
      node.has_vlans = stored;
    if (applyvlans == 2) {
      node.gfxgroup.children[1].children[0].setStroke('green');
      node.gfxgroup.children[1].children[1].setFill('green');
    } else if (applyvlans == 1) {
      node.gfxgroup.children[1].children[0].setStroke('black');
      node.gfxgroup.children[1].children[1].setFill('yellow');
    } else {
      node.gfxgroup.children[1].children[0].setStroke('black');
      node.gfxgroup.children[1].children[1].setFill('white');
    }
    this.DestroyChangeNodes(node.name, '');
    if (node.vlmembers) {
      for (var reqid in this.req_load_vlans) {
        var match = (node.vlmembers[this.req_load_vlans[reqid].vlan_id]) ? true : false;
        if (match) {
          if (applyvlans == 0 && node.has_vlans != 0) {
            var change_node = this.GetChangeNode(this.req_load_vlans[reqid].vlan_id, node.name, '');
            dojo.query('td.action', change_node).innerHTML('delete vlan');
          } else if (this.req_load_vlans[reqid].supplied_vlan_name
          && this.req_load_vlans[reqid].supplied_vlan_name != node.vlmembers[this.req_load_vlans[reqid].vlan_id]) {
            var change_node = this.GetChangeNode(this.req_load_vlans[reqid].vlan_id, node.name, '');
            dojo.query('td.action', change_node).innerHTML('rename vlan');
          }
        } else if (applyvlans == 2 && node.has_vlans != 2) {
          var change_node = this.GetChangeNode(this.req_load_vlans[reqid].vlan_id, node.name, '');
          dojo.query('td.action', change_node).innerHTML('create vlan');
        }
      }
    }
  },

  notifyApplyChanges: function(){
    dijit.byId('dlg_apply_changes').show();
  },

  notifyApplyChangesCancel: function(){
    XhrJsonPost('/group/ajax/cancel/job');
    if (this.faye_sub) {
      this.faye_sub.cancel();
      delete this.faye_sub;
    }
    group_controller.notifySelect(group_controller.current);
  },

  notifyApplyChangesDlgReady: function(){
    var modnodes = [];
    for (var nid in this.nodeset) {
      if (this.nodeset[nid].node_type == 0)
        continue;
      var pushnode = {oper: 'mod-vlan', ifaces: [], self_vlans: [], vlans: []};
      for (var reqid in this.req_load_vlans)
        pushnode.vlans.push(this.req_load_vlans[reqid].vlan_id);
      var p = false;
      if (this.nodeset[nid].apply_vlans != this.nodeset[nid].has_vlans) {
        for (var reqid in this.req_load_vlans) {
          var match = this.nodeset[nid].vlmembers[this.req_load_vlans[reqid].vlan_id] ? true : false;
          if (match) {
            if (this.nodeset[nid].apply_vlans == 0) {
              pushnode.self_vlans.push({
                oper: 'remove',
                vlan_id: this.req_load_vlans[reqid].vlan_id
              });
            } else if (this.req_load_vlans[reqid].supplied_vlan_name
            && this.req_load_vlans[reqid].supplied_vlan_name != this.req_load_vlans[reqid].vlan_name) {
              pushnode.self_vlans.push({
                oper: 'rename',
                vlan_id: this.req_load_vlans[reqid].vlan_id,
                vlan_name: this.req_load_vlans[reqid].supplied_vlan_name
              });
            }
          } else if (this.nodeset[nid].apply_vlans == 2) {
            pushnode.self_vlans.push({
              oper: 'add',
              vlan_id: this.req_load_vlans[reqid].vlan_id,
              vlan_name: this.req_load_vlans[reqid].supplied_vlan_name
              ? this.req_load_vlans[reqid].supplied_vlan_name : this.req_load_vlans[reqid].vlan_name
            });
          }
        }
        if (pushnode.self_vlans.length > 0)
          p = true;
      }
      for (var iidx in this.nodeset[nid].ifaces) {
        if (!this.ifaceset[this.nodeset[nid].ifaces[iidx].id])
          continue;
        var iface = this.ifaceset[this.nodeset[nid].ifaces[iidx].id];
        if (iface.apply_vlans != iface.has_vlans) {
          var pushvlans = [];
          for (var reqid in this.req_load_vlans) {
            var match = iface.vlmembers[this.req_load_vlans[reqid].vlan_id] ? true : false;
            if ((match && iface.apply_vlans == 0)
            || (!match && iface.apply_vlans == 2))
              pushvlans.push({vlan_id: this.req_load_vlans[reqid].vlan_id});
          }
          if (pushvlans.length > 0) {
            pushnode.ifaces.push({
              tid: iface.tid,
              oper: iface.apply_vlans ? 'add' : 'remove',
              vlans: pushvlans
            });
            p = true;
          }
        }
      }
      if (p)
        modnodes.push({node_id: nid, params: pushnode});
    }
    if (modnodes.length <= 0) {
      dijit.byId('dlg_apply_changes').sb_normal_closure = true;
      setTimeout("dijit.byId('dlg_apply_changes').hide()", 100);
      return;
    }
    XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
      'return': dojo.toJson([{model: 'job', action: 'request', as: 'session_id'}])
    }}).then(dojo.hitch(this, function(data){
      this.faye_sub = faye_connection.subscribe('/jobs/' + data.session_id, dojo.hitch(this, function(data){
        if (data.nodes) {
          var tb = dojo.byId('apply_changes_status_tb');
          for (var node in data.nodes) {
            var st = 'Writing changes...';
            if (data.nodes[node].status == 2)
              st = 'Done.';
            else if (data.nodes[node].status == 3) {
              errors = data.nodes[node].error.split("\n");
              st = '';
              for (var i in errors)
                st += 'Error: ' + data.nodes[node].error + "\n";
              st = st.substr(0, st.length - 1);
              this.errored_nodes = true;
            }
            var td = dojo.byId('apply-changes-status-' + node);
            if (td) {
              td.innerHTML = st;
            } else {
              var tr = dojo.create('tr', null, tb);
              td = dojo.create('td', {innerHTML: data.nodes[node].name}, tr);
              td = dojo.create('td', {id: 'apply-changes-status-' + node, innerHTML: st}, tr);
            }
          }
        }
        if (data.general_error) {
          alert('' + data.general_error);
          dijit.byId('dlg_load_vlans').hide();
        }
        if ('end_job' in data) {
          XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
            'return': dojo.toJson([{model: 'job', action: 'retrieve', as: 'load'}])
          }}).then(dojo.hitch(this, function(data){
            if (this.faye_sub) {
              this.faye_sub.cancel();
              delete this.faye_sub;
            }
            if (this.errored_nodes > 0)
              dijit.byId('btn_hide_apply_changes').attr('label', 'OK');
            else {
              dijit.byId('dlg_apply_changes').sb_normal_closure = true;
              dijit.byId('dlg_apply_changes').hide();
            }
            this.onVlansLoaded(this.req_load_vlans, data, false);
          }));
          return;
        }
      }));
      this.errored_nodes = false;
      XhrJsonPost('/group/ajax/run/job', {content: {
        type: 'modvlan',
        modnodes: dojo.toJson(modnodes)
      }}).then(function(){}, function(){
        dijit.byId('dlg_apply_changes').hide();
      });
    }));
  },

  notifyChangeLock: function(){
    this.allow_arrange = dijit.byId('lock_arrange_check').attr('checked') ? false : true;
  },

  notifyClearChanges: function(){
    if (this.nodeset) {
      for (var nid in this.nodeset) {
        if (this.nodeset[nid].node_type != 0)
          this.NodeSetVlan(this.nodeset[nid], this.nodeset[nid].has_vlans);
      }
    }
    if (this.ifaceset) {
      for (var iid in this.ifaceset) {
        this.IfaceSetVlan(this.ifaceset[iid], this.ifaceset[iid].has_vlans);
        if (this.ifaceset[iid].apply_vlans == 2 && (!this.ifaceset[iid].linkto_iface || this.ifaceset[iid].linkto_iface.apply_vlans == 2))
          this.LinkSetVlan(this.ifaceset[iid].line, 2);
        else if (this.ifaceset[iid].apply_vlans == 0 || (this.ifaceset[iid].linkto_iface && this.ifaceset[iid].linkto_iface.apply_vlans == 0))
          this.LinkSetVlan(this.ifaceset[iid].line, 0);
        else
          this.LinkSetVlan(this.ifaceset[iid].line, 1);
      }
    }
  },

  notifyIfaceClicked: function(e){
    if (this.allow_arrange || !this.req_load_vlans)
      return;
    var iface = e.target.sb_iface;
    if (iface.apply_vlans == 0) {
      if (iface.partial) {
        this.IfaceSetVlan(iface, 1);
        if (iface.node.apply_vlans < 1)
          this.NodeSetVlan(iface.node, iface.node.partial ? 1 : 2);
      } else {
        this.IfaceSetVlan(iface, 2);
        this.NodeSetVlan(iface.node, 2);
      }
    } else if (iface.apply_vlans == 1) {
      this.IfaceSetVlan(iface, 2);
      this.NodeSetVlan(iface.node, 2);
    } else
      this.IfaceSetVlan(iface, 0);
    if (iface.apply_vlans == 2 && (!iface.linkto_iface || iface.linkto_iface.apply_vlans == 2))
      this.LinkSetVlan(iface.line, 2);
    else if (iface.apply_vlans == 0 || (iface.linkto_iface && iface.linkto_iface.apply_vlans == 0))
      this.LinkSetVlan(iface.line, 0);
    else
      this.LinkSetVlan(iface.line, 1);
  },

  notifyLinkClicked: function(e){
    if (this.allow_arrange || !this.req_load_vlans)
      return;
    var iface1 = e.target.parentNode.sb_iface1;
    var iface2 = e.target.parentNode.sb_iface2;
    var line = null;
    if (iface1)
      line = iface1.line;
    else if (iface2)
      line = iface2.line;
    if ((!iface1 || iface1.apply_vlans == 2) && (!iface2 || iface2.apply_vlans == 2)) {
      if (iface1) {
        this.IfaceSetVlan(iface1, 0);
        this.LinkSetVlan(line, 0);
      }
      if (iface2) {
        this.IfaceSetVlan(iface2, 0);
        this.LinkSetVlan(line, 0);
      }
    } else if (((iface1 && iface1.apply_vlans == 0) || (iface2 && iface2.apply_vlans == 0))
    && line.partial) {
      if (iface1) {
        this.IfaceSetVlan(iface1, iface1.partial ? 1 : 2);
        if (iface1.node.apply_vlans < 1)
          this.NodeSetVlan(iface1.node, iface1.node.partial ? 1 : 2);
      }
      if (iface2) {
        this.IfaceSetVlan(iface2, iface2.partial ? 1 : 2);
        if (iface2.node.apply_vlans < 1)
          this.NodeSetVlan(iface2.node, iface2.node.partial ? 1 : 2);
      }
      if (line)
        this.LinkSetVlan(line, 1);
    } else {
      if (iface1) {
        this.IfaceSetVlan(iface1, 2);
        this.NodeSetVlan(iface1.node, 2);
      }
      if (iface2) {
        this.IfaceSetVlan(iface2, 2);
        this.NodeSetVlan(iface2.node, 2);
      }
      if (line)
        this.LinkSetVlan(line, 2);
    }
  },

  notifyMoved: function(mover){
    var t1 = mover.shape.getTransform();
    var pos1 = {
      x: mover.shape.sb_node.gfxgroup.sb_adj_x + t1.dx,
      y: mover.shape.sb_node.gfxgroup.sb_adj_y + t1.dy
    };
    if (mover.shape.sb_node.node_type == 0) {
      if (mover.shape.sb_node.unmg_links) {
        for (var i in mover.shape.sb_node.unmg_links) {
          var link_iface = mover.shape.sb_node.unmg_links[i];
          var pos2 = {
            x: link_iface.node.gfxgroup.sb_adj_x,
            y: link_iface.node.gfxgroup.sb_adj_y
          };
          var t2 = link_iface.node.gfxgroup.getTransform();
          if (t2) {
            pos2.x += t2.dx;
            pos2.y += t2.dy;
          }
          link_iface.ideal = Math.atan2(pos1.y - pos2.y, pos1.x - pos2.x);
          FlowInterfaces(link_iface.node);
        }
      }
    } else {
      for (var i in mover.shape.sb_node.ifaces) {
        var iface = mover.shape.sb_node.ifaces[i];
        var target_node = (iface.linkto_iface) ? iface.linkto_iface.node : iface.linkto_node;
        var pos2 = {
          x: target_node.gfxgroup.sb_adj_x,
          y: target_node.gfxgroup.sb_adj_y
        };
        var t2 = target_node.gfxgroup.getTransform();
        if (t2) {
          pos2.x += t2.dx;
          pos2.y += t2.dy;
        }
        iface.ideal = Math.atan2(pos2.y - pos1.y, pos2.x - pos1.x);
        if (iface.linkto_iface)
          iface.linkto_iface.ideal = Math.atan2(pos1.y - pos2.y, pos1.x - pos2.x);
        FlowInterfaces(mover.shape.sb_node);
        if (iface.linkto_iface)
          FlowInterfaces(target_node);
      }
    }
  },

  notifyMoveStart: function(mover){
    if (!this.allow_arrange)
      mover.destroy();
  },

  notifyMoveStop: function(mover){
    if (!this.allow_arrange)
      return;
    live_save.Queue('/group/ajax/update/prov_group_node',
    '' + mover.shape.sb_node.node_id + '/pos', {
      group_id: this.group_id,
      x: parseInt(mover.shape.sb_node.pos_x) + parseInt(mover.shape.getTransform().dx),
      y: parseInt(mover.shape.sb_node.pos_y) + parseInt(mover.shape.getTransform().dy)
    });
  },

  notifyNodeClicked: function(e){
    if (this.allow_arrange || !this.req_load_vlans)
      return;
    var node = e.target.parentNode.sb_node;
    if (node.node_type == 0)
      return;
    if (node.apply_vlans == 0)
      this.NodeSetVlan(node, node.partial ? 1 : 2);
    else if (node.apply_vlans == 1)
      this.NodeSetVlan(node, 2);
    else {
      this.NodeSetVlan(node, 0);
      dojo.forEach(node.ifaces, dojo.hitch(this, function(iface){
        this.IfaceSetVlan(iface, 0);
        this.LinkSetVlan(iface.line, 0);
      }));
    }
  },

  notifyVlansLoaded: function(req_vlans, load_data){
    this.req_load_vlans = req_vlans;
    this.onVlansLoaded(req_vlans, load_data, true);
  },

  onVlansLoaded: function(req_vlans, load_data, full_load){
  }
};

var node_controller = {
  current: null,
  node_scan_cancel: false,
  saved: null,
  dlg_subscription: null,
  iface_id_1: null,
  iface_id_2: null,

  WithdrawNodeFromGroup: function(group_id){
    XhrJsonPost('/group/ajax/delete/prov_group_node', {content: {
      group_id: group_id,
      node_id: this.current.id,
      'return': dojo.toJson([{
        model: 'prov_group',
        view: 'by-node',
        as: 'member_groups',
        node_id: this.current.id
      }])
    }}).then(dojo.hitch(this, function(data){
      data.id = this.current.id;
      this.onCurrentMemberGroupChange(data);
    }));
  },

  notifyChangeCredentials: function(){
    dojo.byId('set_creds_node_name').innerHTML = this.current.name;
    dijit.byId('dlg_set_creds').show();
    return false;
  },

  notifyDeleteCurrent: function(){
    this.dlg_subscription = dojo.subscribe('sbgroup/dlg_del/close', this, function(confirmed){
      dojo.unsubscribe(this.dlg_subscription);
      if (!confirmed)
        return;
      if (this.current.id == 'newNode') {
        dojo.byId('node_tree_div').store.fetch({
          query: {id: 'newNode'},
          onComplete: dojo.hitch(this, function(items, request){
            var store = dojo.byId('node_tree_div').store;
            store.deleteItem(items[0]);
            store.save({onComplete: dojo.hitch(this, this.onDelete)});
          })
        });
      } else {
        dojo.query('[name=action]', 'node_form').attr('value', 'delete');
        XhrJsonPost('/group/ajax/delete/prov_node', {form: 'node_form'})
        .then(dojo.hitch(this, this.onDelete));
      }
    });
    dojo.byId('del_confirm_text').innerHTML =
      "Are you sure you want to delete node '"
      + this.current.name + "'?";
    dijit.byId('dlg_del_confirm').show();
  },

  notifyGroupMemberChange: function(node){
    if (this.current && (node.id == this.current.id))
      this.onCurrentMemberGroupChange(node);
  },

  notifyIfaceLink: function(iface_id){
    this.dlg_subscription = dojo.subscribe('sbgroup/dlg_iface_link/close', this,
    function(confirmed, id1, id2, is_unmgd){
      dojo.unsubscribe(this.dlg_subscription);
      if (!confirmed)
        return;
      XhrJsonPost('/group/ajax/create/prov_iface_link', {content: {
        node_id: this.current.id,
        id1: id1,
        id2: id2,
        unmgd_id2: is_unmgd,
        'return': dojo.toJson([{
          model: 'prov_iface',
          view: 'by-node',
          as: 'ifaces',
          node_id: this.current.id
        }])
      }}).then(dojo.hitch(this, function(data){
        this.onIfacesChange(data.ifaces);
      }));
    });
    var dlg_iface_link = dijit.byId('dlg_iface_link');
    dlg_iface_link.sb_node = this.current;
    dlg_iface_link.sb_iface_src_id = iface_id;
    dlg_iface_link.show();
    return false;
  },
  notifyIfaceUnlink: function(id1, id2, unmg_link){
    this.id1 = id1;
    this.id2 = id2;
    this.dlg_subscription = dojo.subscribe('sbgroup/dlg_del/close', this, function(confirmed){
      dojo.unsubscribe(this.dlg_subscription);
      if (!confirmed)
        return;
      var ct = {
        node_id: this.current.id,
        id1: this.id1,
        id2: this.id2,
        'return': dojo.toJson([{
          model: 'prov_iface',
          view: 'by-node',
          as: 'ifaces',
          node_id: this.current.id
        }])
      };
      if (unmg_link)
        ct.unmgd = unmg_link;
      XhrJsonPost('/group/ajax/delete/prov_iface_link', {content: ct})
      .then(dojo.hitch(this, function(data){
        this.onIfacesChange(data.ifaces);
      }));
    });
    dojo.byId('del_confirm_text').innerHTML =
    "Are you sure you want to unlink this interface?";
    dijit.byId('dlg_del_confirm').show();
    return false;
  },

  notifyNew: function(){
    this.current = {id: 'newNode', name: '[New Node]'};
    this.onNew();
  },

  notifyNodeScanCancel: function(){
    this.node_scan_cancel = true;
  },
  notifyNodeScanCheck: function(data){
    if (this.node_scan_cancel)
      return;
    if (data.lock_result == 'obtained') {
      XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
        'return': dojo.toJson([{
          model: 'prov_iface',
          view: 'by-node',
          as: 'ifaces',
          node_id: this.current.id
        }])
      }}).then(dojo.hitch(this, function(data){
        dijit.byId('dlg_scan_node').sb_normal_closure = true;
        dijit.byId('dlg_scan_node').hide();
        this.onIfacesChange(data.ifaces);
      }));
    } else {
      XhrJsonGet('/group/ajax/block', {preventCache: true, content: {
        lock_base: 'node_scan',
        lock_id: this.current.id
      }}).then(dojo.hitch(this, this.notifyNodeScanCheck));
	}
  },
  notifyNodeScanDlgReady: function(){
    this.node_scan_cancel = false;
    XhrJsonPost('/group/ajax/create/node_scan', {content: {node_id: this.current.id}})
    .then(dojo.hitch(this, function(){
      if (!this.node_scan_cancel)
        this.notifyNodeScanCheck({result: 'start'});
    }));
  },
  notifyRescan: function(){
    dijit.byId('dlg_scan_node').show();
  },

  notifySaveCurrent: function(){
    if (this.current.id == 'newNode' && dijit.byId('node_type').attr('value') != '0') {
      dojo.byId('set_creds_node_name').innerHTML = this.current.name;
      dijit.byId('dlg_set_creds').show();
    } else {
      this.saved = this.current;
      XhrJsonPost('/group/ajax/' + ((this.current.id == 'newNode') ? 'create' : 'update')
      + '/prov_node', {form: 'node_form'})
      .then(dojo.hitch(this, function(){
        this.onSave(this.saved);
      }));
    }
  },

  notifySetCredsOK: function(uname, pass){
    if (this.current.id == 'newNode') {
      this.saved = this.current;
      var sendcontents = {cred_username: uname};
      if (dijit.byId('cred_type').attr('value') == 'userpass')
        sendcontents.cred_plainpass = pass;
      XhrJsonPost('/group/ajax/create/prov_node',
      {form: 'node_form', content: sendcontents})
      .then(dojo.hitch(this, function(){
        this.onSave(this.saved);
      }));
    } else {
      var sendcontents = {
        update_creds: true,
        node_id: this.current.id,
        cred_username: uname
      };
      if (dijit.byId('cred_type').attr('value') == 'userpass')
        sendcontents.cred_plainpass = pass;
      XhrJsonPost('/group/ajax/update/prov_node', {content: sendcontents});
    }
  },

  notifySelect: function(item) {
    if (item.id == 'newNode') {
      this.current = {id: item.id, name: item.name};
      this.onSelect(this.current);
    } else {
      XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
        'return': dojo.toJson([{
          model: 'prov_node',
          view: 'object',
          as: 'root',
          id: parseInt(item.id)
        }, {
          model: 'prov_group',
          view: 'by-node',
          as: 'member_groups',
          node_id: parseInt(item.id)
        }, {
          model: 'prov_iface',
          view: 'by-node',
          as: 'ifaces',
          node_id: parseInt(item.id)
        }])
      }}).then(dojo.hitch(this, function(data){
        this.current = data;
        this.onSelect(this.current);
      }));
    }
  },

  onDelete: function(){
    this.onUnselect();
  },
  onCurrentMemberGroupChange: function(node){
  },
  onIfacesChange: function(ifaces){
  },
  onNew: function(){
  },
  onSave: function(node){
  },
  onSelect: function(node){
  },
  onUnselect: function(){
  }
};

var live_save = {
  payload_buffer: new Object(),
  queue_ct: 0,

  Queue: function(url, key, payload){
    if (!this.payload_buffer[url])
      this.payload_buffer[url] = new Object();
    this.payload_buffer[url][key] = payload;
    ++this.queue_ct;
    setTimeout(dojo.hitch(this, this.notifySaveTimer), 3000);
  },

  notifySaveTimer: function(){
    if (this.queue_ct <= 0)
      return;
    if (this.queue_ct > 1) {
      --this.queue_ct;
      return;
    }
    for (var url in this.payload_buffer) {
      XhrJsonPost(url, {
        content: {method: 'jsonset', set: dojo.toJson(this.payload_buffer[url])}
      }).then(function(){
        var dt = new Date();
        var lst = dojo.byId('last_save_timestamp');
        if (lst) {
          lst.innerHTML = 'As of ' + dojo.date.locale.format(new Date(), {
            selector: 'time',
            timePattern: 'HH:mm:ss'
          });
        }
      });
    }
    this.payload_buffer = new Object();
    this.queue_ct = 0;
  }
};

/* Function: FlowInterfaces
 *   Distribute the interface circles on a node's square, where each circle
 *   points as closely as possible toward the node on the other end of its link,
 *   but without overlapping circles.
 *
 * Implementation notes:
 *   The current approach is somewhat brute-force-ish. Each circle has already
 *   been assigned its "ideal" angle, which points directly toward the linked
 *   node. Initially, place each individual circle in its own group. Then,
 *   iteratively combine and redistribute groups that overlap until no more
 *   overlaps exist.
 *
 *   The circles in each group will be placed side-by-side along an imaginary
 *   circumference (NODE_CIRCUM) that can contain up to MAX_IFACES_PER_NODE
 *   circles, with the angle of each group's center as the mean (average) of all
 *   the group's circles' ideal angles.
 *
 *   Group A and Group B overlap if the distance (around the imaginary
 *   circumference) between their means is less than half of the length of
 *   group A's circles side-by-side plus half of the length of group B's circles
 *   side by side. All distances or lengths are calculated in radians along the
 *   imaginary circumference.
 */
function FlowInterfaces(node) {
  var groups = []; //Contains all circle groups for the node
  /* Initially, place each circle in its own group */
  dojo.forEach(node.ifaces, function(iface){
    var g = {ifaces: [iface], mean: iface.ideal};
    groups.push(g);
  });
  /* Iterate through the groups until no more groups overlap. As soon as two
   * groups are found that overlap, we will combine them and then start over
   * again from the beginning of the group array. */
  while (true) {
    var combined = false; //Flag as true once an overlap is found
    /* Find the first group to check... */
    for (var i = 0; i < groups.length; ++i) {
      /* ...and then the second. */
      for (var j = 0; j < groups.length; ++j) {
        /* --But don't compare a group against itself. */
        if (j == i)
          continue;
        /* Find the difference between the two groups' means */
        var mean_sep = groups[i].mean - groups[j].mean;
        /* Also, find the mean of the two groups' means */
        var mean_mean = (groups[i].mean + groups[j].mean) / 2;
        /* Now we are ready for the absolute value of the mean difference, i.e.
         * the true numerical value of their separation. If the absolute value
         * is greater than PI (i.e. more than half the circumference), they are
         * actually separated by the 0-radian point, and we must subtract our
         * mean separation value from 2*PI and place the mean-mean on the other
         * side of the circumference. */
        mean_sep = Math.abs(mean_sep);
        if (mean_sep > Math.PI) {
          mean_sep = 2 * Math.PI - mean_sep;
          mean_mean += Math.PI;
        }
        /* Calculate the "reach" of the groups: the combined length of the
         * "arms" that extend from the center (mean) of each group, where each
         * arm's length is half of the length of all the group's circles side by
         * side. */
        var reach = groups[i].ifaces.length * IFACE_RADIANS / 2 + groups[j].ifaces.length * IFACE_RADIANS / 2;
        /* If the reach is greater than the mean separation, we have overlap;
         * combine the two groups into one. */
        if (reach > mean_sep) {
          var newgroup = {ifaces: groups[i].ifaces.concat(groups[j].ifaces)};
          var newmean = 0;
          /* Iterate through the new combined group */
          for (var k = 0; k < newgroup.ifaces.length; ++k) {
            /* Re-position all angles so that the mean-mean is at 0, and any
             * given ideal angle either adds to or subtracts from the new mean
             * based on its position relative to the mean-mean.
             */
            var m_ideal = newgroup.ifaces[k].ideal - mean_mean;
            if (m_ideal < -Math.PI)
              m_ideal += 2 * Math.PI;
            else if (m_ideal > Math.PI)
              m_ideal -= 2 * Math.PI;
            newmean += m_ideal;
          }
          /* Finally, divide and subtract the mean-mean to get the true new
           * mean. */
          newgroup.mean = (newmean / newgroup.ifaces.length) + mean_mean;
          /* Add the new group, replacing groups[i], and delete groups[j] */
          groups[i] = newgroup;
          groups.splice(j, 1);
          /* We have combined two groups, so break both inner loops */
          combined = true;
          break;
        }
      }
      if (combined)
        break;
    }
    /* If we didn't find any overlapping groups, we are done */
    if (!combined)
      break;
  }
  var trans = node.gfxgroup.getTransform();
  if (!trans)
    trans = {dx: 0, dy: 0};
  var ifacesort = function(a, b){
    var a_ideal = a.ideal - this.mean;
    if (a_ideal < -Math.PI)
      a_ideal += 2 * Math.PI;
    else if (a_ideal > Math.PI)
      a_ideal -= 2 * Math.PI;
    var b_ideal = b.ideal - this.mean;
    if (b_ideal < -Math.PI)
      b_ideal += 2 * Math.PI;
    else if (a_ideal > Math.PI)
      b_ideal -= 2 * Math.PI;
    if (a.hint)
      a_ideal += a.hint;
    if (b.hint)
      b_ideal += b.hint;
    return (a_ideal > b_ideal) ? 1 : -1;
  };
  for (var i = 0; i < groups.length; ++i) {
    groups[i].ifaces.sort(dojo.hitch(groups[i], ifacesort));
    for (var j = 0; j < groups[i].ifaces.length; ++j) {
      var actual = groups[i].mean - (groups[i].ifaces.length - 1) / 2 * IFACE_RADIANS + j * IFACE_RADIANS;
      var this_iface = groups[i].ifaces[j];
      this_iface.actual.x = node.gfxgroup.sb_adj_x + trans.dx + Math.cos(actual) * NODE_RADIUS,
      this_iface.actual.y = node.gfxgroup.sb_adj_y + trans.dy + Math.sin(actual) * NODE_RADIUS
      this_iface.circle.setShape({
        cx: this_iface.actual.x,
        cy: this_iface.actual.y
      });
      var far_actual = null;
      if (this_iface.linkto_iface) {
        far_actual = {
          x: this_iface.linkto_iface.actual.x,
          y: this_iface.linkto_iface.actual.y
        };
      } else {
        far_actual = {
          x: this_iface.linkto_node.gfxgroup.sb_adj_x,
          y: this_iface.linkto_node.gfxgroup.sb_adj_y
        };
        var far_trans = this_iface.linkto_node.gfxgroup.getTransform();
        if (far_trans) {
          far_actual.x += far_trans.dx;
          far_actual.y += far_trans.dy;
        }
      }
      var xdiff = this_iface.actual.x - far_actual.x;
      var ydiff = this_iface.actual.y - far_actual.y;
      var rectlen = Math.sqrt(Math.pow(xdiff, 2) + Math.pow(ydiff, 2));
      var rectmid_x = (this_iface.actual.x + far_actual.x) / 2;
      var rectmid_y = (this_iface.actual.y + far_actual.y) / 2;
      var angle = Math.atan2(ydiff, xdiff);
      this_iface.line.setTransform(dojox.gfx.matrix.multiply(
      dojox.gfx.matrix.rotateAt(angle, rectmid_x, rectmid_y),
      dojox.gfx.matrix.translate(rectmid_x - rectlen / 2, rectmid_y - IFACE_RADIUS),
      dojox.gfx.matrix.scale(rectlen / (IFACE_RADIUS * 2), 1)
      ));
    }
  }
}
