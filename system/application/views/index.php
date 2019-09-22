<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
 "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">

<head>
<title>SECOM Build Console</title>
<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
<link rel="stylesheet" href="http://ajax.googleapis.com/ajax/libs/dojo/1.6/dijit/themes/claro/document.css" />
<link rel="stylesheet" href="http://ajax.googleapis.com/ajax/libs/dojo/1.6/dijit/themes/claro/claro.css" />
<script src="http://ajax.googleapis.com/ajax/libs/dojo/1.6/dojo/dojo.xd.js" type="text/javascript" djConfig="parseOnLoad:true"></script>
<script src="http://rt.build.secom.net:8001/faye.js" type="text/javascript"></script>
<style type="text/css">
body, html {
  width: 100%;
  height: 100%;
  margin: 0;
  padding: 0;
  overflow: hidden;
}
#topbar {
  position: absolute;
  width: 100%;
  height: 50px;
  padding: 2px 5px;
}
#maincontent {
  position: absolute;
  top: 50px;
  bottom: 0;
  width: 100%;
}
td.label, th.label {
  text-align: right;
}
</style>
<script type="text/javascript">
dojo.require('dojo.data.ItemFileReadStore');
dojo.require('dojo.data.ItemFileWriteStore');
dojo.require('dojo.date.locale');
dojo.require('dojo.NodeList-manipulate');
dojo.require('dijit.Dialog');
dojo.require('dijit.layout.AccordionContainer');
dojo.require('dijit.layout.BorderContainer');
dojo.require('dijit.layout.ContentPane');
dojo.require('dijit.layout.TabContainer');
dojo.require('dijit.Tree');
dojo.require('dijit.tree.dndSource');
dojo.require('dijit.tree.ForestStoreModel');
dojo.require('dijit.MenuBar');
dojo.require('dijit.MenuBarItem');
dojo.require('dijit.form.Button');
dojo.require('dijit.form.CheckBox');
dojo.require('dijit.form.Form');
dojo.require('dijit.form.Select');
dojo.require('dijit.form.TextBox');
dojo.require('dijit.form.ValidationTextBox');
dojo.require('dojox.charting.action2d.Tooltip');
dojo.require('dojox.gfx');
dojo.require('dojox.gfx.move');
dojo.require('dojox.html.metrics');
dojo.require('dojox.layout.ContentPane');

var dlg300;
dojo.addOnLoad(function(){
  dlg300 = new dijit.Dialog({
    style: 'width:300px'
  });
});
function ErrorDlg(msg, title) {
  dlg300.attr('title', title ? title : 'Page Error');
  dlg300.attr('content', msg);
  dlg300.show();
}

function XhrJsonGet(u, options) {
  if (!options)
    options = new Object();
  options.url = u;
  options.handleAs = 'json';
  var d1 = dojo.xhrGet(options);
  var d2 = new dojo.Deferred();
  d1.then(function(data){
    if (data.error) {
      alert('' + data.error);
      d2.errback(data.error);
    } else
      d2.callback(data);
  }, function(error){
    if (typeof(error) == 'string')
      alert(error);
    else {
      var msg = '';
      for (var i in error) {
        if (error[i])
          msg += '' + i + ': ' + error[i] + "\n";
      }
      alert(msg);
    }
    d2.errback(error);
  });
  return d2;
}
function XhrJsonPost(u, options) {
  if (!options)
    options = new Object();
  options.url = u;
  options.handleAs = 'json';
  var d1 = dojo.xhrPost(options);
  var d2 = new dojo.Deferred();
  d1.then(function(data){
    if (data.error) {
      alert('' + data.error);
      d2.errback(data.error);
    } else
      d2.callback(data);
  }, function(error){
    if (typeof(error) == 'string')
      alert(error);
    else {
      var msg = '';
      for (var i in error) {
        if (error[i])
          msg += '' + i + ': ' + error[i] + "\n";
      }
      alert(msg);
    }
    d2.errback(error);
  });
  return d2;
}

var faye_connection = null;
if (typeof Faye == "undefined")
  alert("Warning: Can't connect to server for realtime updates!");
else {
  faye_connection = new Faye.Client('http://rt.build.secom.net:8001/faye');
  faye_connection.errback(function(err){
    alert('Realtime error: ' + err.message);
  });
}
</script>
</head>

<body class="claro">

<div id="topbar">

<div style="float:right"><?php echo $this->session->userdata('session_id'); ?>&nbsp;&nbsp;&nbsp;&nbsp;</div>

<h2 style="margin:0">SECOM Build Console</h2>
<table class="menutable" border="0">
<tr>
<?php
$menuitems = array(
  'group' => 'Group Provisioning'
);
foreach ($menuitems as $seg => $title) {
  if ($this->uri->segment(1) === $seg) {
?>
  <th><?php echo $title; ?></th>
<?php
  } else {
?>
  <td><?php echo anchor($seg, $title); ?></td>
<?php
  }
}
?>
</tr>
</table>
</div>

<div id="maincontent">
<?php echo $t_child; ?>
</div>

</body>

</html>
