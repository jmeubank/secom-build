<?php
error_reporting(E_ALL);
$config = array();
require_once('system/application/config/site.php');
require_once('system/application/libraries/RingAnalyzer.php');
$decrypt_key = "JTdj4W54lp[0-zy5q74UlIBKLnlKui-i";

if ($_SERVER['argc'] < 2)
  die("-Need node ID to load");
$node_id = (integer)$_SERVER['argv'][1];

mysql_connect('127.0.0.1', 'secom_build', 'secom_build');
mysql_select_db('secom_build');

$result = mysql_query("SELECT ip, node_type, cred_username, cred_encpass, "
. "cred_enc_iv, snmp_comm FROM prov_node WHERE id = " . $node_id
. " AND node_type > 0;");
if (mysql_num_rows($result) != 1)
  die("-Couldn't find node with ID " . $node_id);
$node = mysql_fetch_assoc($result);
mysql_free_result($result);

$result = mysql_query("SELECT GET_LOCK('secom_build.node_scan."
. mysql_real_escape_string($node_id) . "', 30);");
if (mysql_num_rows($result) != 1)
  die("-Couldn't acquire lock");
$row = mysql_fetch_array($result);
mysql_free_result($result);
if ($row[0] != '1')
  die("-Lock timed out");
echo "+";

$pipes = array();
$cmd = str_replace('%args%', '', $config['switchtool']);
$p = proc_open($cmd, array(
  0 => array("pipe", "r"),
  1 => array("pipe", "w")
), $pipes);
if (!is_resource($p))
  die("-Failed to run switchtool\n");
$ready1 = trim(@fgets($pipes[1]));
$ready2 = trim(@fgets($pipes[1]));
if ($ready1 !== '{"ready": 1}' || $ready2 !== '}}:}}:')
  die('-' . $ready1 . '. ' . $ready2);

function escapeJsonString($value) {
  # list from www.json.org: (\b backspace, \f formfeed)    
  $escapers = array("\\",     "/",   "\"",  "\n",  "\r",  "\t", "\x08", "\x0c");
  $replacements = array("\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t",  "\\f",  "\\b");
  $result = str_replace($escapers, $replacements, $value);
  return $result;
}

@fwrite($pipes[0], "{\"host\": {\n");
@fwrite($pipes[0], "  \"hostname\": \"" . escapeJsonString($node['ip'])
. "\",\n");
if ($node['node_type'] == '1') { //Calix
  @fwrite($pipes[0], "  \"type\": \"calixeseries\",\n");
  @fwrite($pipes[0], "	\"proto-ssh\": {\n");
} else if ($node['node_type'] == '2') { //JunOS
  @fwrite($pipes[0], "  \"type\": \"junosswitch\",\n");
  @fwrite($pipes[0], "	\"proto-netconfssh\": {\n");
} else if ($node['node_type'] == '3') { //Cisco
  @fwrite($pipes[0], "  \"type\": \"ciscoios\",\n");
  @fwrite($pipes[0], "	\"proto-telnet\": {\n");
} else
  die("-Invalid node type: " . $node['node_type']);

if ($node['cred_encpass']) { //Auth by username and password
  $pass = @mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $decrypt_key,
  @base64_decode($node['cred_encpass']), MCRYPT_MODE_CBC,
  @base64_decode($node['cred_enc_iv']));
  $pass = rtrim($pass, chr(0));
  if ($node['node_type'] == '3') { //Cisco
    @fwrite($pipes[0], "    \"auth\": \"console\",\n");
    @fwrite($pipes[0], "    \"password\": \""
    . escapeJsonString($node['cred_username']) . "\",\n");
    @fwrite($pipes[0], "    \"enable\": \"" . escapeJsonString($pass) . "\"\n");
  } else {
    @fwrite($pipes[0], "    \"auth\": \"userpass\",\n");
    @fwrite($pipes[0], "    \"username\": \""
    . escapeJsonString($node['cred_username']) . "\",\n");
    @fwrite($pipes[0], "    \"password\": \"" . escapeJsonString($pass)
    . "\"\n");
  }
} else { //Auth by RSA key
  @fwrite($pipes[0], "    \"auth\": \"rsa\",\n");
  @fwrite($pipes[0], "    \"username\": \""
  . escapeJsonString($node['cred_username']) . "\",\n");
  @fwrite($pipes[0], "    \"public-key-file\": \""
  . escapeJsonString($config['rsa_public_key_file']) . "\",\n");
  @fwrite($pipes[0], "    \"private-key-file\": \""
  . escapeJsonString($config['rsa_private_key_file']) . "\"\n");
}
@fwrite($pipes[0], "  }\n");
if ($node['snmp_comm']) {
  @fwrite($pipes[0], "	, \"proto-snmp2\": \""
  . escapeJsonString($node['snmp_comm']) . "\"\n");
}
@fwrite($pipes[0], "}}\n");
@fwrite($pipes[0], "}}:}}:\n");

@fwrite($pipes[0], "{\"command\": \"list-ifaces\"}\n}}:}}:\n");
@fflush($pipes[0]);
@fclose($pipes[0]);

$resultstr = '';
while (TRUE) {
  $line = @fgets($pipes[1]);
  if ($line === FALSE)
    break;
  if (trim($line) === '}}:}}:')
    break;
  $resultstr .= $line;
}
if (!$resultstr)
  die("-Unexpected EOF from switchtool");
$jresult = json_decode($resultstr, TRUE);
if (!isset($jresult['interfaces']))
  die("-No interfaces found");
  
$result = mysql_query("SELECT id, tid FROM prov_iface WHERE node_id = "
. mysql_real_escape_string($node_id) . ";");
$former_ifaces = array();
while ($row = mysql_fetch_assoc($result))
  $former_ifaces[$row['tid']] = array('id' => $row['id']);
mysql_free_result($result);

foreach ($jresult['interfaces'] as $iface_name => $iface_vals) {
  $speed = (integer)$iface_vals['speed'];
  $lag_ct = (strlen($iface_vals['members']) > 0)
  ? ((integer)$iface_vals['members']) : null;
  $descr = $iface_vals['description'];
  if (isset($former_ifaces[$iface_name])) {
    mysql_query("UPDATE prov_iface SET speed = " . $speed . ", lag_ct = "
    . (($lag_ct === null) ? 'NULL' : $lag_ct) . ", descr = "
    . (($descr === null) ? 'NULL' : ("'" . mysql_real_escape_string($descr) . "'"))
    . " WHERE id = " . $former_ifaces[$iface_name]['id'] . ";");
    $former_ifaces[$iface_name]['present'] = true;
  } else {
    mysql_query("INSERT INTO prov_iface (node_id, tid, speed, lag_ct, descr) VALUES ("
    . mysql_real_escape_string($node_id) . ", '"
    . mysql_real_escape_string($iface_name) . "', " . $speed . ", "
    . (($lag_ct === null) ? 'NULL' : $lag_ct) . ", "
    . (($descr === null) ? 'NULL' : ("'" . mysql_real_escape_string($descr) . "'"))
    . ");");
  }
}

foreach ($former_ifaces as $former_val) {
  if (!isset($former_val['present'])) {
    mysql_query("DELETE FROM prov_iface_link WHERE iface_id_1 = "
    . $former_val['id'] . " OR iface_id_2 = " . $former_val['id'] . ";");
    mysql_query("DELETE FROM prov_iface WHERE id = " . $former_val['id'] . ";");
  }
}

mysql_free_result(mysql_query("SELECT RELEASE_LOCK('secom_build.node_scan."
. mysql_real_escape_string($node_id) . "');"));

exit(0);
