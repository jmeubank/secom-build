<style type="text/css">
#changes_table td {
  padding: 0 7px;
}
</style>

<div dojoType="dijit.layout.BorderContainer" gutters="false" style="width:100%;height:100%">
  <div region="center" dojoType="dijit.layout.ContentPane" style="padding:3px">

<div style="float:right;text-align:right">
<input id="lock_arrange_check" type="checkbox" dojoType="dijit.form.CheckBox" checked="checked" />
<script type="text/javascript">
group_node_controller.prov_tab_connects.push(
dojo.connect(dijit.byId('provision_pane'), 'onLoad', function(){
  dojo.connect(dijit.byId('lock_arrange_check'), 'onClick', group_node_controller, 'notifyChangeLock');
})
);
</script>
<label for="lock_arrange_check"><b>Lock node positions</b></label>
<br />
<div id="last_save_timestamp"></div>
</div>

<table border="0" cellpadding="3" cellspacing="0">
<tr>
  <td>
    <button id="btn_load_vlans" dojoType="dijit.form.Button" type="button">
      Load VLAN(s)...
      <script type="dojo/connect" event="onClick" args="e">
      e.preventDefault();
      setTimeout('vlan_load_controller.notifyLoadVlansDlg()', 100);
      </script>
    </button>
  </td>
  <th>Loaded:</th>
  <td id="loaded_vlan_ids">(none)</td>
</tr>
</table>
<script type="text/javascript">
group_node_controller.prov_tab_connects.push(
dojo.connect(group_node_controller, 'onVlansLoaded', function(req_vlans){
  var val = '';
  for (var i in req_vlans)
    val += '' + req_vlans[i].vlan_id + ',';
  if (val.length >= 3)
    dojo.byId('loaded_vlan_ids').innerHTML = val.substring(0, val.length - 1);
})
);
</script>

<div id="surface_outer" style="position:absolute;left:0;top:35px;right:0;bottom:0;border:1px solid gray;overflow:auto">
<div id="ttnode1" style="position:absolute;left:0;top:0;width:16px;height:16px;z-index:-100"></div>
<div id="ttnode2" style="position:absolute;left:0;top:0;width:16px;height:16px;z-index:-100"></div>
<div id="surface_div"></div>
</div>
<script type="text/javascript">
function ShowIfaceTooltip(etarget, ttnode, positioning){
  if (!ttnode.sb_ttip)
    ttnode.sb_ttip = new dijit._MasterTooltip();
  var iface = etarget.sb_iface;
  dojo.style(ttnode, 'left', '' + (iface.circle.shape.cx - IFACE_RADIUS) + 'px');
  dojo.style(ttnode, 'top', '' + (iface.circle.shape.cy - IFACE_RADIUS) + 'px');
  var ih = '' + iface.node.name + '<br />';
  ih += (iface.lag_ct === null) ? ('<b>' + iface.tid + '</b>') : 'LAG';
  ih += ': ';
  ih += (iface.lag_ct === null) ? iface.descr : ('<b>' + iface.tid + '</b>');
  ih += '</b><br />';
  ih += (iface.speed != '0') ? ('' + (parseInt(iface.speed) / 1000) + 'G') : 'no link';
  if (parseInt(iface.lag_ct) > 0)
    ih += '&times;' + iface.lag_ct;
  var val = '';
  for (var i in iface.vlmembers)
    val += '' + i + ',';
  if (val.length >= 1)
    ih += '<br />Loaded: ' + val.substring(0, val.length - 1);
  ttnode.sb_ttip.show(ih, ttnode, positioning);
}
function HideIfaceTooltips(){
  var ttnode = dojo.byId('ttnode1');
  if (ttnode.sb_ttip)
    ttnode.sb_ttip.hide(ttnode);
  ttnode = dojo.byId('ttnode2');
  if (ttnode.sb_ttip)
    ttnode.sb_ttip.hide(ttnode);
}
function onIfaceTooltip(){
  if (!this.sb_over)
    return;
  ShowIfaceTooltip(this.sb_event.target, dojo.byId('ttnode1'), ['above', 'below']);
}
var horz1 = ['before'];
var horz2 = ['after'];
var vert1 = ['above'];
var vert2 = ['below'];
function onLinkTooltip(){
  if (!this.sb_over)
    return;
  var etarget = this.sb_event.target;
  if (!etarget.sb_iface1 && !etarget.sb_iface2)
    etarget = etarget.parentNode;
  var places = null;
  if (etarget.sb_iface1 && etarget.sb_iface2) {
    var xdiff = etarget.sb_iface1.circle.shape.cx - etarget.sb_iface2.circle.shape.cx;
    var ydiff = etarget.sb_iface1.circle.shape.cy - etarget.sb_iface2.circle.shape.cy;
    var places =
    (Math.abs(xdiff) > Math.abs(ydiff))
    ?
    ((xdiff > 0) ? [horz2, horz1] : [horz1, horz2])
    :
    ((ydiff > 0) ? [vert2, vert1] : [vert1, vert2])
    ;
    ShowIfaceTooltip(etarget.sb_iface1.circle.rawNode, dojo.byId('ttnode1'), places[0]);
    ShowIfaceTooltip(etarget.sb_iface2.circle.rawNode, dojo.byId('ttnode2'), places[1]);
  } else {
    if (etarget.sb_iface1)
      ShowIfaceTooltip(etarget.sb_iface1.circle.rawNode, dojo.byId('ttnode1'), ['above', 'below']);
    else
      ShowIfaceTooltip(etarget.sb_iface2.circle.rawNode, dojo.byId('ttnode2'), ['above', 'below']);
  }
}
function onIfaceMouseEnter(e){
  this.sb_over = true;
  this.sb_event = e;
  setTimeout(dojo.hitch(this, onIfaceTooltip), 1000);
}
function onIfaceMouseLeave(){
  if (this.sb_over) {
    this.sb_over = false;
    HideIfaceTooltips();
  }
}
function onLinkMouseEnter(e){
  this.sb_over = true;
  this.sb_event = e;
  setTimeout(dojo.hitch(this, onLinkTooltip), 1000);
}
function onLinkMouseLeave(){
  if (this.sb_over) {
    this.sb_over = false;
    HideIfaceTooltips();
  }
}

var group_data = <?php echo json_encode($group_data); ?>;

(function(){
  var surface_outer = dojo.byId('surface_outer');
  var min_x = 0, min_y = 0, max_x = 0, max_y = 0;
  dojo.forEach(group_data.nodes, function(node){
    if (parseInt(node.pos_x) < min_x)
      min_x = parseInt(node.pos_x);
    if (parseInt(node.pos_y) < min_y)
      min_y = parseInt(node.pos_y);
    if (parseInt(node.pos_x) > max_x)
      max_x = parseInt(node.pos_x);
    if (parseInt(node.pos_y) > max_y)
      max_y = parseInt(node.pos_y);
  });
  var pos = dojo.position(surface_outer);
  var surface_w = pos.w;
  var surface_h = pos.h;
  var rel_origin = {
    x: NODE_SIDE / 2 + 5,
    y: NODE_SIDE / 2 + 5
  };
  if (min_x < rel_origin.x)
    rel_origin.x -= min_x;
  if (min_y < rel_origin.y)
    rel_origin.y -= min_y;
  if (max_x + rel_origin.x > surface_w)
    surface_w = max_x + rel_origin.x + NODE_SIDE / 2 + 20;
  if (max_y + rel_origin.y > surface_h)
    surface_h = max_y + rel_origin.y + NODE_SIDE / 2 + 20;
  surface_outer.surface = dojox.gfx.createSurface('surface_div', surface_w, surface_h);
  surface_outer.dimensions = {width: surface_w, height: surface_h};
  surface_outer.surface.whenLoaded(this, function(){
    group_node_controller.nodeset = new Object();
    group_node_controller.ifaceset = new Object();
    dojo.forEach(group_data.nodes, function(node){
      node.has_vlans = 0;
      node.apply_vlans = 0;
      var node_center = {
        x: parseInt(node.pos_x) + rel_origin.x,
        y: parseInt(node.pos_y) + rel_origin.y
      };
      var group = surface_outer.surface.createGroup();
      group.sb_node = node;
      node.gfxgroup = group;
      var inner_group = group.createGroup();
      inner_group.rawNode.sb_node = node;
      var nrect = inner_group.createRect({
        x: node_center.x - NODE_SIDE / 2,
        y: node_center.y - NODE_SIDE / 2,
        width: NODE_SIDE,
        height: NODE_SIDE
      }).setStroke('black').setFill('white');
      if (node.node_type == 0)
        nrect.setStroke({style: 'Dot'});
      inner_group.createRect({
        x: node_center.x - IFACE_RADIUS,
        y: node_center.y - IFACE_RADIUS,
        width: IFACE_RADIUS * 2,
        height: IFACE_RADIUS * 2
      }).setFill('white');
      inner_group.connect('onclick', group_node_controller, 'notifyNodeClicked');
      group.createText({
        text: node.name,
        x: node_center.x,
        y: node_center.y + NODE_RADIUS + IFACE_RADIUS + 7,
        align: 'middle'
      }).setFont({
        family: '"Lucida Console",Monaco,monospace',
        size: '12px'
      }).setFill('black');
      var txtlen = node.name.length * 7.3 + 4;
      group.createRect({
        x: node_center.x - (txtlen / 2),
        y: node_center.y + NODE_RADIUS + IFACE_RADIUS - 3,
        width: txtlen,
        height: 12
      }).setFill('#eee').moveToBack();
      group.sb_adj_x = node_center.x;
      group.sb_adj_y = node_center.y;
      group.sb_hints = {};
      var m = new dojox.gfx.Moveable(group);
      dojo.connect(m, 'onMoveStart', group_node_controller, 'notifyMoveStart');
      dojo.connect(m, 'onMoveStop', group_node_controller, 'notifyMoveStop');
      dojo.connect(m, 'onMoved', group_node_controller, 'notifyMoved');
      group_node_controller.nodeset[parseInt(node.node_id)] = node;
    });
    var max_lwidth = IFACE_RADIUS - 2;
    dojo.forEach(group_data.links, function(link){
      var n1 = group_node_controller.nodeset[parseInt(link.node_id_1)];
      var n2 = (link.unmg_node_id) ?
        group_node_controller.nodeset[parseInt(link.unmg_node_id)]
      : group_node_controller.nodeset[parseInt(link.node_id_2)];
      if (!n1 || !n2)
        return;
      var iface1 = {
        node: n1,
        id: parseInt(link.iface_id_1),
        tid: link.iface_tid_1,
        descr: link.iface_descr_1,
        speed: link.iface_speed_1,
        lag_ct: link.iface_lag_ct_1,
        has_vlans: 0,
        apply_vlans: 0,
        vlmembers: {}
      };
      var iface2 = null;
      if (!link.unmg_node_id) {
        iface2 = {
          node: n2,
          id: parseInt(link.iface_id_2),
          tid: link.iface_tid_2,
          descr: link.iface_descr_2,
          speed: link.iface_speed_2,
          lag_ct: link.iface_lag_ct_2,
          has_vlans: 0,
          apply_vlasn: 0,
          linkto_iface: iface1,
          vlmembers: {}
        };
        iface1.linkto_iface = iface2;
      } else {
        iface1.linkto_node = n2;
        if (n2.unmg_links)
          n2.unmg_links.push(iface1);
        else
          n2.unmg_links = [iface1];
      }
      var linegroup = surface_outer.surface.createGroup().moveToBack();
      linegroup.rawNode.sb_iface1 = iface1;
      linegroup.rawNode.sb_iface2 = iface2;
      var speed = parseInt(link.iface_speed_1);
      if (speed > 0) {
        var lwidth = 0.5;
        var lstyle = 'ShortDot';
        if (speed >= 100) {
          lwidth = 0.5 + ((Math.log(speed) / Math.LN10) - 2) * ((max_lwidth - 0.5) / 2);
          lstyle = 'Solid';
        }
        var lcount = link.iface_lag_ct_1;
        if (lcount === null)
          lcount = 1;
        else
          lcount = parseInt(lcount);
        for (var i = 0; i < lcount; i++) {
          linegroup.createLine({
            x1: 0,
            y1: IFACE_RADIUS * 2 / lcount * (i + 0.5),
            x2: IFACE_RADIUS * 2,
            y2: IFACE_RADIUS * 2 / lcount * (i + 0.5)
          }).setStroke({color: 'gray', width: lwidth, style: lstyle}).moveToBack();
        }
      }
      linegroup.createRect({width: IFACE_RADIUS * 2, height: IFACE_RADIUS * 2}).setFill('#eee').moveToBack();
      linegroup.connect('onclick', group_node_controller, 'notifyLinkClicked');
      linegroup.connect('onmouseenter', onLinkMouseEnter);
      linegroup.connect('onmouseleave', onLinkMouseLeave);
      iface1.line = linegroup;
      if (iface2)
        iface2.line = linegroup;
      iface1.ideal = Math.atan2(n2.gfxgroup.sb_adj_y - n1.gfxgroup.sb_adj_y,
      n2.gfxgroup.sb_adj_x - n1.gfxgroup.sb_adj_x);
      iface1.circle = surface_outer.surface.createCircle({
        cx: n1.gfxgroup.sb_adj_x,
        cy: n1.gfxgroup.sb_adj_y,
        r: IFACE_RADIUS
      }).setStroke('black').setFill('white');
      iface1.circle.connect('onmouseover', onIfaceMouseEnter);
      iface1.circle.connect('onmouseout', onIfaceMouseLeave);
      iface1.circle.connect('onclick', group_node_controller, 'notifyIfaceClicked');
      iface1.circle.rawNode.sb_iface = iface1;
      iface1.actual = {x: n1.gfxgroup.sb_adj_x, y: n1.gfxgroup.sb_adj_y};
      if (n1.gfxgroup.sb_hints[parseInt(link.node_id_2)]) {
        iface1.hint = n1.gfxgroup.sb_hints[parseInt(link.node_id_2)];
        n1.gfxgroup.sb_hints[parseInt(link.node_id_2)] -= 0.001;
      } else
          n1.gfxgroup.sb_hints[parseInt(link.node_id_2)] = -0.001;
      if (!n1.ifaces)
        n1.ifaces = [];
      n1.ifaces.push(iface1);
      group_node_controller.ifaceset[iface1.id] = iface1;
      if (iface2) {
        iface2.ideal = Math.atan2(n1.gfxgroup.sb_adj_y - n2.gfxgroup.sb_adj_y,
        n1.gfxgroup.sb_adj_x - n2.gfxgroup.sb_adj_x);
        iface2.circle = surface_outer.surface.createCircle({
          cx: n2.gfxgroup.sb_adj_x,
          cy: n2.gfxgroup.sb_adj_y,
          r: IFACE_RADIUS
        }).setStroke('black').setFill('white');
        iface2.circle.connect('onmouseover', onIfaceMouseEnter);
        iface2.circle.connect('onmouseout', onIfaceMouseLeave);
        iface2.circle.connect('onclick', group_node_controller, 'notifyIfaceClicked');
        iface2.circle.rawNode.sb_iface = iface2;
        iface2.actual = {x: n2.gfxgroup.sb_adj_x, y: n2.gfxgroup.sb_adj_y};
        if (n2.gfxgroup.sb_hints[parseInt(link.node_id_1)]) {
          iface2.hint = n2.gfxgroup.sb_hints[parseInt(link.node_id_1)];
          n2.gfxgroup.sb_hints[parseInt(link.node_id_1)] += 0.001;
        } else
          n2.gfxgroup.sb_hints[parseInt(link.node_id_1)] = 0.001;
        if (!n2.ifaces)
          n2.ifaces = [];
        n2.ifaces.push(iface2);
        group_node_controller.ifaceset[iface2.id] = iface2;
      }
    });
    for (var i in group_node_controller.nodeset) {
      if (group_node_controller.nodeset[i].node_type != 0)
        FlowInterfaces(group_node_controller.nodeset[i]);
    }
    var dt = new Date();
    dojo.byId('last_save_timestamp').innerHTML = 'As of '
    + dojo.date.locale.format(new Date(), {
      selector: 'time',
      timePattern: 'HH:mm:ss'
    });
    group_node_controller.prov_tab_connects.push(
    dojo.connect(group_node_controller, 'onVlansLoaded', function(req_vlans, data, full_load){
      for (var nid in data.load) {
        var n = group_node_controller.nodeset[parseInt(nid)];
        if (!n)
          continue;
        n.vlmembers = {};
        group_node_controller.NodeSetVlan(n, 0, 0);
        dojo.forEach(n.ifaces, function(iface){
          iface.vlmembers = {};
          group_node_controller.IfaceSetVlan(iface, 0, 0);
          group_node_controller.LinkSetVlan(iface.line, 0, 0);
        });
        if (!data.load[nid].self)
          continue;
        n.vlmembers = data.load[nid].self;
        var have_count = 0;
        for (var reqid in req_vlans) {
          if (data.load[nid].self[req_vlans[reqid].vlan_id])
            have_count++;
        }
        n.partial = false;
        if (have_count == req_vlans.length)
          group_node_controller.NodeSetVlan(n, 2, 2);
        else if (have_count > 0) {
          group_node_controller.NodeSetVlan(n, 1, 1);
          n.partial = true;
        } else
          group_node_controller.NodeSetVlan(n, 0, 0);
        if (!data.load[nid].ifaces)
          continue;
        for (var iid in data.load[nid].ifaces) {
          var i = group_node_controller.ifaceset[parseInt(iid)];
          if (!i)
            continue;
          i.vlmembers = data.load[nid].ifaces[iid];
          have_count = 0;
          for (var reqid in req_vlans) {
            if (data.load[nid].ifaces[iid][req_vlans[reqid].vlan_id])
              have_count++;
          }
          i.partial = false;
          if (have_count == req_vlans.length)
            group_node_controller.IfaceSetVlan(i, 2, 2);
          else if (have_count > 0) {
            group_node_controller.IfaceSetVlan(i, 1, 1);
            i.partial = true;
          } else
            group_node_controller.IfaceSetVlan(i, 0, 0);
          if (i.linkto_iface && i.linkto_iface.vlmembers) {
            var overlap = 0;
            for (var vid in i.vlmembers) {
              if (i.linkto_iface.vlmembers[vid])
                overlap++;
            }
            i.line.partial = false;
            if (overlap == req_vlans.length)
              group_node_controller.LinkSetVlan(i.line, 2);
            else if (overlap > 0) {
              group_node_controller.LinkSetVlan(i.line, 1);
              i.line.partial = true;
            } else
              group_node_controller.LinkSetVlan(i.line, 0);
          } else if (i.linkto_node)
            group_node_controller.LinkSetVlan(i.line, 2);
        }
      }
      var dt = new Date();
      dojo.byId('last_save_timestamp').innerHTML = 'As of '
      + dojo.date.locale.format(new Date(), {
        selector: 'time',
        timePattern: 'HH:mm:ss'
      });
    })
    );
  });
})();
</script>

  </div>
  <div region="bottom" dojoType="dijit.layout.ContentPane" splitter="true" style="height:20%;padding:0;overflow:auto">

<div style="text-align:center">
  <button id="btn_apply_changes" type="button" dojoType="dijit.form.Button"
  disabled="true">Apply Changes</button>
  <button id="btn_clear_changes" type="button" dojoType="dijit.form.Button"
  disabled="true">Clear Changes</button>
</div>
<script type="text/javascript">
group_node_controller.prov_tab_connects.push(
dojo.connect(dijit.byId('provision_pane'), 'onLoad', function(){
  dojo.connect(dijit.byId('btn_apply_changes'), 'onClick', function(e){
    e.preventDefault();
    setTimeout('group_node_controller.notifyApplyChanges()', 100);
  });
  dojo.connect(dijit.byId('btn_clear_changes'), 'onClick', function(e){
    e.preventDefault();
    setTimeout('group_node_controller.notifyClearChanges()', 100);
  });
})
);
group_node_controller.prov_tab_connects.push(
dojo.connect(group_node_controller, 'onVlansLoaded', function(){
  dijit.byId('btn_apply_changes').attr('disabled', false);
  dijit.byId('btn_clear_changes').attr('disabled', false);
})
);
</script>
<table id="changes_table"></table>

  </div>
</div>
