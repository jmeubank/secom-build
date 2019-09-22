<?php
error_reporting(E_ALL);
$config = array();
require_once(dirname(__FILE__) . '/config/site.php');
$decrypt_key = "JTdj4W54lp[0-zy5q74UlIBKLnlKui-i";

mysql_connect('127.0.0.1', 'secom_build', 'secom_build');
mysql_select_db('secom_build');

echo "Loading nodes...\n";
$result = mysql_query("SELECT id, ip, name, node_type, cred_username, cred_encpass, "
. "cred_enc_iv, snmp_comm, ring_checking FROM prov_node "
. "WHERE node_type > 0 AND (ring_checking + 0) > 0;");
if (mysql_num_rows($result) <= 0)
  die("No nodes to check");
$nodes = array();
while ($node = mysql_fetch_assoc($result)) {
  $nodes[$node['id']] = $node;
  $nodes[$node['id']]['links'] = array();
  $nodes[$node['id']]['visited'] = FALSE;
  $nodes[$node['id']]['check_ifaces'] = array();
}
mysql_free_result($result);

echo "Loading interfaces...\n";
$result = mysql_query("SELECT id, node_id, tid FROM prov_iface;");
if (mysql_num_rows($result) <= 0)
  die("No ifaces to check");
$ifaces = array();
while ($iface = mysql_fetch_assoc($result))
  $ifaces[$iface['id']] = $iface;
mysql_free_result($result);

echo "Loading links...\n";
$result = mysql_query("SELECT * FROM prov_iface_link;");
if (mysql_num_rows($result) <= 0)
  die("No links to check");
while ($link = mysql_fetch_assoc($result)) {
  if (!isset($nodes[$ifaces[$link['iface_id_1']]['node_id']]))
    continue;
  if ($link['iface_id_2']) {
    if (!isset($nodes[$ifaces[$link['iface_id_2']]['node_id']]))
      continue;
    $nodes[$ifaces[$link['iface_id_1']]['node_id']]['links'][] = array(
      'iface' => $link['iface_id_1'],
      'op_node' => $ifaces[$link['iface_id_2']]['node_id'],
      'op_iface' => $link['iface_id_2']
    );
    $nodes[$ifaces[$link['iface_id_2']]['node_id']]['links'][] = array(
      'iface' => $link['iface_id_2'],
      'op_node' => $ifaces[$link['iface_id_1']]['node_id'],
      'op_iface' => $link['iface_id_1']
    );
  } else {
    if (!isset($nodes[$link['node_id_2']]))
      continue;
    $nodes[$ifaces[$link['iface_id_1']]['node_id']]['links'][] = array(
      'iface' => $link['iface_id_1'],
      'op_node' => $link['node_id_2'],
      'op_iface' => NULL
    );
    $nodes[$link['node_id_2']]['links'][] = array(
      'iface' => NULL,
      'op_node' => $ifaces[$link['iface_id_1']]['node_id'],
      'op_iface' => $link['iface_id_1']
    );
  }
}
mysql_free_result($result);

echo "Searching for cycles...\n";
// Loop until all nodes are visited at least once
$cycles = array();
while (TRUE) {
  $root_id = NULL;
  foreach ($nodes as $node_id => $node) {
    if (!$node['visited']) {
      $root_id = $node_id;
      break;
    }
  }
  if ($root_id === NULL)
    break;
  $nodes[$root_id]['visited'] = TRUE;
  $stack = array(array('node_id' => $root_id, 'visit_idx' => 0));
  $stack_height = 1;
  // Loop until stack is empty
  while ($stack_height > 0) {
    $this_node_id = $stack[$stack_height - 1]['node_id'];
    if ($stack[$stack_height - 1]['visit_idx'] >= count($nodes[$this_node_id]['links'])) {
      array_pop($stack);
      $stack_height--;
      if ($stack_height > 0)
        $stack[$stack_height - 1]['visit_idx']++;
      continue;
    }
    if (isset($stack[$stack_height - 1]['from_idx'])
    && $stack[$stack_height - 1]['from_idx'] == $stack[$stack_height - 1]['visit_idx']) {
      $stack[$stack_height - 1]['visit_idx']++;
      continue;
    }
    $op_node_id = $nodes[$this_node_id]['links'][$stack[$stack_height - 1]['visit_idx']]['op_node'];
    $in_stack_idx = NULL;
    for ($i = 0; $i < $stack_height; $i++) {
      if ($stack[$i]['node_id'] == $op_node_id) {
        $in_stack_idx = $i;
        break;
      }
    }
    if ($in_stack_idx !== NULL) {
      //We have a cycle
      $cycle = array();
      for ($i = $in_stack_idx; $i < $stack_height; $i++) {
        $cycle[$stack[$i]['node_id']] = array();
      }
      $matched = FALSE;
      foreach ($cycles as $cmp_cycle) {
        $this_matched = TRUE;
        foreach ($cycle as $nid => $nvals) {
          if (!isset($cmp_cycle[$nid])) {
            $this_matched = FALSE;
            break;
          }
        }
        foreach ($cmp_cycle as $nid => $nvals) {
          if (!isset($cycle[$nid])) {
            $this_matched = FALSE;
            break;
          }
        }
        if ($this_matched) {
          $matched = TRUE;
          break;
        }
      }
      if (!$matched) {
        echo "Found a cycle:\n";
        for ($i = $in_stack_idx; $i < $stack_height; $i++) {
          if ($i > $in_stack_idx) {
            $cycle[$stack[$i]['node_id']]['iface1'] = array('id' => $nodes[$stack[$i]['node_id']]['links'][$stack[$i]['from_idx']]['iface']);
            $cycle[$stack[$i]['node_id']]['op_node1'] = $stack[$i - 1]['node_id'];
            $nodes[$stack[$i]['node_id']]['check_ifaces'][$nodes[$stack[$i]['node_id']]['links'][$stack[$i]['from_idx']]['iface']] = TRUE;
          }
          $cycle[$stack[$i]['node_id']]['iface2'] = array('id' => $nodes[$stack[$i]['node_id']]['links'][$stack[$i]['visit_idx']]['iface']);
          $cycle[$stack[$i]['node_id']]['op_node2'] = $nodes[$stack[$i]['node_id']]['links'][$stack[$i]['visit_idx']]['op_node'];
          $nodes[$stack[$i]['node_id']]['check_ifaces'][$nodes[$stack[$i]['node_id']]['links'][$stack[$i]['visit_idx']]['iface']] = TRUE;
          echo "  " . $nodes[$stack[$i]['node_id']]['name'] . "\n";
        }
        $cycle[$stack[$in_stack_idx]['node_id']]['iface1'] = array('id' => $nodes[$stack[$stack_height - 1]['node_id']]['links'][$stack[$stack_height - 1]['visit_idx']]['op_iface']);
        $cycle[$stack[$in_stack_idx]['node_id']]['op_node1'] = $stack[$stack_height - 1]['node_id'];
        $nodes[$stack[$in_stack_idx]['node_id']]['check_ifaces'][$nodes[$stack[$stack_height - 1]['node_id']]['links'][$stack[$stack_height - 1]['visit_idx']]['op_iface']] = TRUE;
        $cycles[] = $cycle;
      }
      $stack[$stack_height - 1]['visit_idx']++;
      continue;
    }
    $op_link_idx = NULL;
    for ($i = 0; $i < count($nodes[$op_node_id]['links']); $i++) {
      if ($nodes[$op_node_id]['links'][$i]['op_node'] == $this_node_id
      && $nodes[$op_node_id]['links'][$i]['op_iface'] == $nodes[$this_node_id]['links'][$stack[$stack_height - 1]['visit_idx']]['iface']) {
        $op_link_idx = $i;
        break;
      }
    }
    array_push($stack, array(
      'node_id' => $op_node_id,
      'visit_idx' => 0,
      'from_idx' => $op_link_idx
    ));
    $stack_height++;
    $nodes[$op_node_id]['visited'] = TRUE;
  }
}

echo "Running VLAN loader job...\n";
mysql_query('LOCK TABLES `active_job` WRITE;');
mysql_query('DELETE FROM `active_job` WHERE DATE_ADD(`valid`, INTERVAL 1 HOUR) < NOW();');
$result = mysql_query("SELECT session_id FROM active_job WHERE status = 0 OR status = 1;");
if (mysql_num_rows($result) >= 10) {
  mysql_query('UNLOCK TABLES;');
  die("Too many jobs are already running");
}
$session_id = '1000000000000000000000000000000000000000';
while ($row = mysql_fetch_assoc($result)) {
  if ($row['session_id'] != $session_id)
    continue;
  mysql_query("UPDATE active_job SET status = 3 WHERE session_id = '" . $session_id . "';");
  mysql_query('UNLOCK TABLES;');
  die("The ring analyzer already has a running job, please try again in a few seconds");
}
mysql_free_result($result);
mysql_query("INSERT INTO active_job (session_id, status) VALUES ('"
. $session_id . "', 0);");
mysql_query('UNLOCK TABLES;');
mysql_query("DELETE FROM node_job WHERE session_id = '" . $session_id . "';");

foreach ($nodes as $node_id => $node) {
  if (count($node['check_ifaces']) <= 0)
    continue;
  mysql_query("INSERT INTO node_job (session_id, status, node_id, params) VALUES ("
  . "'" . $session_id . "', "
  . "0, "
  . $node_id . ", "
  . "'" . mysql_real_escape_string(json_encode(array(
    'oper' => 'read-ifaces',
    'ifaces' => array_keys($node['check_ifaces'])
  ))) . "'"
  . ");");
}

$cmd = str_replace('%args%', $session_id, $config['sb_node_job_controller_block']);
$child = popen($cmd, 'r');
if ($child === FALSE) {
  mysql_query("DELETE FROM node_job WHERE session_id = '" . $session_id . "';");
  mysql_query("DELETE FROM active_job WHERE session_id = '" . $session_id . "';");
  die("Couldn't create controller process");
}
$resultchar = fgetc($child);
if ($resultchar != '+') {
  $error = stream_get_contents($child);
  pclose($child);
  mysql_query("DELETE FROM node_job WHERE session_id = '" . $session_id . "';");
  mysql_query("DELETE FROM active_job WHERE session_id = '" . $session_id . "';");
  die($error);
}
pclose($child);

$result = mysql_query("SELECT error FROM active_job WHERE session_id = '"
. $session_id . "';");
$err = NULL;
if (mysql_num_rows($result) > 0) {
  $row = mysql_fetch_assoc($result);
  $err = $row['error'];
}
mysql_free_result($result);
if ($err !== NULL)
  die($err);

echo "Retrieving job results...\n";
$result = mysql_query("SELECT * FROM node_job WHERE session_id = '"
. $session_id . "';");
while ($row = mysql_fetch_assoc($result)) {
  if ($row['error'])
    die("Node " . $nodes[$row['node_id']]['ip'] . ": " . $row['error']);
  for ($i = 0; $i < count($cycles); $i++) {
    foreach ($cycles[$i] as $nid => &$nvals) {
      if ($row['node_id'] == $nid) {
        $payload = json_decode($row['payload'], TRUE);
        if (isset($payload['ifaces']) && isset($payload['ifaces'][$nvals['iface1']['id']])) {
          $nvals['iface1']['vlans'] = array();
          foreach ($payload['ifaces'][$nvals['iface1']['id']] as $vlan)
            $nvals['iface1']['vlans'][$vlan] = TRUE;
        }
        if (isset($payload['ifaces']) && isset($payload['ifaces'][$nvals['iface2']['id']])) {
          $nvals['iface2']['vlans'] = array();
          foreach ($payload['ifaces'][$nvals['iface2']['id']] as $vlan)
            $nvals['iface2']['vlans'][$vlan] = TRUE;
        }
      }
    }
  }
}
mysql_free_result($result);
mysql_query("DELETE FROM node_job WHERE session_id = '" . $session_id . "';");

echo "Looking for VLAN discrepancies...\n";
$incomplete_vlans = array();
foreach ($cycles as $cycle) {
  $all_mstp = TRUE;
  $vlan_set = array();
  foreach ($cycle as $nid => $nvals) {
    if ($nodes[$nid]['ring_checking'] != '2' || $nodes[$nvals['op_node1']]['ring_checking'] != '2') {
      if ($all_mstp)
        $vlan_set = array();
      $all_mstp = FALSE;
    }
    if ($nodes[$nid]['ring_checking'] != '2' || $nodes[$nvals['op_node1']]['ring_checking'] != '2'
    || $all_mstp) {
      foreach ($nvals['iface1']['vlans'] as $vlan_id => $tval)
        $vlan_set[$vlan_id] = TRUE;
    }
    if ($nodes[$nid]['ring_checking'] != '2' || $nodes[$nvals['op_node2']]['ring_checking'] != '2') {
      if ($all_mstp)
        $vlan_set = array();
      $all_mstp = FALSE;
    }
    if ($nodes[$nid]['ring_checking'] != '2' || $nodes[$nvals['op_node2']]['ring_checking'] != '2'
    || $all_mstp) {
      foreach ($nvals['iface2']['vlans'] as $vlan_id => $tval)
        $vlan_set[$vlan_id] = TRUE;
    }
  }
  foreach ($cycle as $nid => $nvals) {
    foreach ($vlan_set as $vlan_id => $tval) {
      if (!isset($nvals['iface1']['vlans'][$vlan_id])) {
        if (!isset($incomplete_vlans[$vlan_id]))
          $incomplete_vlans[$vlan_id] = array();
        $incomplete_vlans[$vlan_id][] = $nodes[$nid]['name'] . ' - ' . $ifaces[$nvals['iface1']['id']]['tid'];
      }
      if (!isset($nvals['iface2']['vlans'][$vlan_id])) {
        if (!isset($incomplete_vlans[$vlan_id]))
          $incomplete_vlans[$vlan_id] = array();
        $incomplete_vlans[$vlan_id][] = $nodes[$nid]['name'] . ' - ' . $ifaces[$nvals['iface2']['id']]['tid'];
      }
    }
  }
}

echo "Done.\n\n";
if (count($incomplete_vlans) <= 0) {
  echo "No problems found.\n";
  exit(0);
}

require_once(dirname(__FILE__) . '/class.phpmailer.php');
$body = "<p>The SECOM-Build ring analyzer found one or more VLANs that are on "
. "some, but not all, switch ports in a ring.</p>\n\n";
foreach ($incomplete_vlans as $vlan_id => $ifaces) {
  $body .= "<p><b>VLAN " . $vlan_id . "</b> is missing from:<br />\n";
  foreach ($ifaces as $iface)
    $body .= $iface . "<br />\n";
  $body .= "</p>\n\n";
}
echo $body;
if (isset($config['ring_analyzer_mailto'])) {
  $mail = new PHPMailer();
  $mail->IsSMTP();
  $mail->Host = 'mail.secom.net';
  $mail->From = 'build@secom.net';
  $mail->FromName = 'SECOM-Build Consistency Check';
  $mail->AddAddress($config['ring_analyzer_mailto']);
  $mail->IsHTML(TRUE);
  $mail->Subject = "Inconsistent ring VLAN(s) found";
  $mail->Body = $body;
  if(!$mail->Send())
    die("Mailer error: " . $mail->ErrorInfo);
}

exit(0);
