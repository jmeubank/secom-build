<script type="text/javascript">
var vlan_load_controller = {
  next_load_vlan_id: null,
  req_vlans: null,

  CreateAnotherVlanRow: function(){
    var tr = dojo.create('tr', {'class': 'load-vlan-row'}, 'load_another_vlan_tr', 'before');
    var td = dojo.create('td', {valign: 'bottom'}, tr);
    var dv = dojo.create('div', null, td);
    new dijit.form.ValidationTextBox({
      'class': 'vlan-id-tbox widget-needs-destroy',
      trim: true,
      maxLength: 4,
      style: 'width:4em',
      regExp: '[0-9]+'
    }, dv).focus();
    td = dojo.create('td', {valign: 'bottom'}, tr);
    dv = dojo.create('div', null, td);
    var tbox = new dijit.form.CheckBox({
      id: 'auto-name-check-' + this.next_load_vlan_id,
      checked: 'checked',
      'class': 'widget-needs-destroy'
    }, dv);
    dojo.connect(tbox, 'onClick', OnCheckUseExisting);
    dojo.create('label', {
      'for': 'auto-name-check-' + this.next_load_vlan_id,
      style: 'font-size:smaller',
      innerHTML: 'Use existing name'
    }, td);
    dojo.create('br', null, td);
    dv = dojo.create('div', null, td);
    new dijit.form.ValidationTextBox({
      type: 'text',
      'class': 'vlan-name-tbox widget-needs-destroy',
      trim: true,
      maxLength: 20,
      style: 'width:15em',
      regExp: '[a-zA-Z0-9_\\- ]+',
      disabled: true
    }, dv);
    this.next_load_vlan_id++;
  },

  notifyLoadBtnClick: function(){
    this.req_vlans = [];
    var load_vlans = [];
    dojo.query('#select_load_vlans_table .vlan-id-tbox').forEach(dojo.hitch(this, function(tbox){
      var val = parseInt(dijit.getEnclosingWidget(tbox).attr('value'));
      if (val > 0) {
        load_vlans.push(val);
        var req_push = {vlan_id: val};
        dojo.query('.vlan-name-tbox', tbox.parentNode.parentNode).forEach(dojo.hitch(this, function(node){
          var nval = dijit.getEnclosingWidget(node).attr('value');
          if (/^[a-zA-Z0-9_\-][a-zA-Z0-9_\- ]{0,19}$/.test(nval)) {
            req_push.vlan_name = nval;
            req_push.supplied_vlan_name = nval;
          }
        }));
        this.req_vlans.push(req_push);
      }
    }));
    if (load_vlans.length <= 0) {
      dijit.byId('dlg_load_vlans').hide();
      return;
    }
    XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
      'return': dojo.toJson([{model: 'job', action: 'request', as: 'session_id'}])
    }}).then(dojo.hitch(this, function(data){
      this.faye_sub = faye_connection.subscribe('/jobs/' + data.session_id, dojo.hitch(this, function(data){
        if (data.nodes) {
          var tb = dojo.byId('node_load_status_tb');
          for (var node in data.nodes) {
            var st = 'Loading...';
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
            var td = dojo.byId('node-load-status-' + node);
            if (td) {
              td.innerHTML = st;
            } else {
              var tr = dojo.create('tr', null, tb);
              td = dojo.create('td', {innerHTML: data.nodes[node].name}, tr);
              td = dojo.create('td', {id: 'node-load-status-' + node, innerHTML: st}, tr);
            }
          }
        }
        if (data.general_error) {
          alert('' + data.general_error);
          dijit.byId('dlg_load_vlans').hide();
        }
        if ('end_job' in data) {
          if (this.errored_nodes) {
            dijit.byId('btn_vlan_load_ok_cancel').attr('label', 'OK');
            return;
          }
          XhrJsonGet('/group/ajax/read', {preventCache: true, content: {
            'return': dojo.toJson([{model: 'job', action: 'retrieve', as: 'load'}])
          }}).then(dojo.hitch(this, function(data){
            for (var reqid in this.req_vlans) {
              for (var nid in data.load) {
                if (!data.load[nid].self)
                  continue;
                if (data.load[nid].self[this.req_vlans[reqid].vlan_id]) {
                  this.req_vlans[reqid].vlan_name = data.load[nid].self[this.req_vlans[reqid].vlan_id];
                  break;
                }
              }
            }
            dojo.empty('vlan_create_names');
            dojo.byId('vlan_create_names').innerHTML = 'The following VLANs are not present on any nodes.<br />'
            + 'Please supply names:';
            var tb = dojo.create('table', {cellpadding: '0', cellspacing: '5'}, 'vlan_create_names');
            var tr = dojo.create('tr', null, tb);
            dojo.create('th', {innerHTML: 'ID'}, tr);
            dojo.create('th', {innerHTML: 'Name'}, tr);
            var need_name_ct = 0;
            for (var reqid in this.req_vlans) {
              if (this.req_vlans[reqid].vlan_name)
                continue;
              need_name_ct++;
              tr = dojo.create('tr', null, tb);
              dojo.create('td', {innerHTML: '' + this.req_vlans[reqid].vlan_id}, tr);
              var td = dojo.create('td', null, tr);
              var dv = dojo.create('div', null, td);
              new dijit.form.ValidationTextBox({
                id: 'vname-set-tbox-' + this.req_vlans[reqid].vlan_id,
                'class': 'widget-needs-destroy',
                trim: true,
                maxLength: 20,
                required: true,
                style: 'width:15em',
                regExp: '[a-zA-Z0-9_\\- ]+'
              }, dv);
            }
            if (need_name_ct > 0) {
              this.loaded_data = data;
              dojo.style(dijit.byId('btn_vlan_load_ok').domNode, 'display', 'inline');
              dojo.style('node_load_status', 'display', 'none');
              dojo.style('vlan_create_names', 'display', 'block');
            } else {
              dijit.byId('dlg_load_vlans').hide();
              group_node_controller.notifyVlansLoaded(this.req_vlans, data);
            }
          }));
          return;
        }
      }));
      this.errored_nodes = false;
      XhrJsonPost('/group/ajax/run/job', {content: {
        type: 'readvlan',
        group_id: group_node_controller.group_id,
        vlans: dojo.toJson(load_vlans)
      }}).then(function(){}, function(error){
        dijit.byId('dlg_load_vlans').hide();
      });
    }), dojo.hitch(this, function(){
      dijit.byId('dlg_load_vlans').hide();
    }));
  },
  notifyLoadVlansDlg: function(){
    dijit.byId('dlg_load_vlans').show();
  },

  notifyNameBtnClick: function(){
    for (var reqid in this.req_vlans) {
      if (this.req_vlans[reqid].vlan_name)
        continue;
      var found = false;
      dojo.query('#vname-set-tbox-' + this.req_vlans[reqid].vlan_id).forEach(dojo.hitch(this, function(node){
        var val = dijit.getEnclosingWidget(node).attr('value');
        if (val) {
          this.req_vlans[reqid].vlan_name = val;
          found = true;
        }
      }));
      if (!found)
        return;
    }
    group_node_controller.notifyVlansLoaded(this.req_vlans, this.loaded_data);
    this.loaded_data = null;
    dijit.byId('dlg_load_vlans').hide();
  },

  notifyVlanLoadCancel: function(){
    XhrJsonPost('/group/ajax/cancel/job');
    this.loaded_data = null;
  }
};

function OnCheckUseExisting(e) {
  e.preventDefault();
  var ch = dojo.attr(e.target, 'checked') ? true : false;
  dojo.query('.vlan-name-tbox', e.target.parentNode.parentNode).forEach(function(node){
    var w = dijit.getEnclosingWidget(node);
    w.attr('disabled', ch);
    w.attr('value', '');
    if (!ch)
      w.focus();
  });
}
</script>

<div id="dlg_load_vlans" dojoType="dijit.Dialog" title="Load VLAN data" style="display:none">
<table>
<tr>
  <td valign="top">
  <div id="node_load_status" style="display:none">
  </div>
  </td>
</tr>
<tr>
  <td valign="top">
  <div id="vlan_create_names" style="display:none">
  </div>
  </td>
</tr>
<tr>
  <td valign="top">
    <div id="select_load_vlans">
    VLAN(s) to load or create:<br />
    <table id="select_load_vlans_table">
    <tr>
      <th>ID</th>
      <th>Name</th>
    </tr>
    <tr id="load_another_vlan_tr">
      <td colspan="2" align="right" style="font-size:smaller">
        <button dojoType="dijit.form.Button" type="button">
          &crarr;Another
          <script type="dojo/connect" event="onClick" args="e">
          e.preventDefault();
          vlan_load_controller.CreateAnotherVlanRow();
          </script>
        </button>
      </td>
    </tr>
    </table>
    </div>
  </td>
</tr>
<tr>
  <td align="center">
    <button id="btn_vlan_load_ok" dojoType="dijit.form.Button" type="button">
      OK
      <script type="dojo/connect" event="onClick" args="e">
      e.preventDefault();
      if (vlan_load_controller.loaded_data) {
        if (!dijit.byId('dlg_load_vlans').isValid())
          return;
        vlan_load_controller.notifyNameBtnClick();
      } else {
        if (!dijit.byId('dlg_load_vlans').isValid())
          return;
        dojo.style('select_load_vlans', 'display', 'none');
        dojo.style('vlan_create_names', 'display', 'none');
        dojo.empty('node_load_status');
        dojo.create('table', {id: 'node_load_status_tb'}, 'node_load_status');
        dojo.style('node_load_status', 'display', 'block');
        dojo.style(dijit.byId('btn_vlan_load_ok').domNode, 'display', 'none');
        vlan_load_controller.notifyLoadBtnClick();
      }
      </script>
    </button>
    <button id="btn_vlan_load_ok_cancel" dojoType="dijit.form.Button" type="button">
      Cancel
      <script type="dojo/connect" event="onClick">
        dijit.byId('dlg_load_vlans').hide();
        vlan_load_controller.notifyVlanLoadCancel();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('btn_vlan_load_ok_cancel').attr('label', 'Cancel');
dijit.byId('dlg_load_vlans').sb_normal_closure = false;
dojo.style(dijit.byId('btn_vlan_load_ok').domNode, 'display', 'inline');
dojo.style('node_load_status', 'display', 'none');
dojo.style('vlan_create_names', 'display', 'none');
dojo.empty('node_load_status');
dojo.query('#vlan_create_names .widget-needs-destroy').forEach(function(node){
  dijit.getEnclosingWidget(node).destroy();
});
dojo.empty('vlan_create_names');
dojo.style('select_load_vlans', 'display', 'block');
dojo.query('#select_load_vlans_table .load-vlan-row .widget-needs-destroy').forEach(function(node){
  dijit.getEnclosingWidget(node).destroy();
});
dojo.query('#select_load_vlans_table .load-vlan-row').forEach(dojo.destroy);
vlan_load_controller.next_load_vlan_id = 1;
vlan_load_controller.loaded_data = null;
vlan_load_controller.CreateAnotherVlanRow();
</script>
<script type="dojo/connect" event="onHide">
  if (vlan_load_controller.faye_sub) {
    vlan_load_controller.faye_sub.cancel();
    delete vlan_load_controller.faye_sub;
  }
</script>
</div>
