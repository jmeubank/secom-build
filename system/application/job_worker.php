<?php
require_once(dirname(__FILE__) . '/job_funcs.php');

TaskSetup();

if ($_SERVER['argc'] < 3)
  die("-Need session ID and node ID");
$session_id = $_SERVER['argv'][1];
$node_id = (integer)$_SERVER['argv'][2];

echo "+";

$node = NULL;

function Fail($messages) {
  global $session_id;
  global $node_id;
  global $node;
  $postarray = array(
    'status' => 3,
    'error' => implode("\n", $messages)
  );
  $postarray['name'] = ($node ? $node['name'] : '');
  PostUpdate($session_id, array('nodes' => array($node_id => $postarray)));
  TaskCleanup($session_id);
  exit(1);
}

$result = mysql_query("SELECT params FROM job WHERE session_id = '"
. mysql_real_escape_string($session_id) . "';");
if (mysql_num_rows($result) <= 0)
  Fail(array("No job info for session ID " . $session_id));
$job = mysql_fetch_assoc($result);
mysql_free_result($result);
$params = json_decode($job['params'], TRUE);

$result = mysql_query(
"SELECT prov_node.ip, prov_node.node_type, prov_node.cred_username, "
. "prov_node.cred_encpass, prov_node.cred_enc_iv, prov_node.name "
. "FROM prov_node WHERE prov_node.id = " . $node_id
. " AND prov_node.node_type > 0;"
);
if ($result === FALSE || mysql_num_rows($result) != 1)
  Fail(array("No node with ID " . $node_id));
$node = mysql_fetch_assoc($result);
mysql_free_result($result);

$pipes = array();
$cmd = str_replace('%args%', '', $config['switchtool']);
$p = proc_open($cmd, array(
  0 => array('pipe', 'r'),
  1 => array('pipe', 'w')
), $pipes);
if (!is_resource($p))
  Fail(array("Failed to run switchtool"));
$ready1 = trim(@fgets($pipes[1]));
$ready2 = trim(@fgets($pipes[1]));
if ($ready1 !== '{"ready": 1}' || $ready2 !== '}}:}}:')
  Fail(array($ready1 . '. ' . $ready2));

PostUpdate($session_id, array('nodes' => array($node_id => array(
  'name' => $node['name'],
  'status' => 1
))));

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
  Fail(array("Invalid node type: " . $node['node_type']));
if ($node['cred_encpass']) { //Auth by username/password or console/enable
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
@fwrite($pipes[0], "}}\n");
@fwrite($pipes[0], "}}:}}:\n");
@fflush($pipes[0]);

function GetJsonResult($instream) {
  $resultstr = '';
  while (TRUE) {
    $line = @fgets($instream);
    if ($line === FALSE)
      break;
    if (trim($line) === '}}:}}:')
      break;
    $resultstr .= $line;
  }
  if (!$resultstr)
    return FALSE;
  return json_decode($resultstr, TRUE);
}

function GetVlanData($vlan_id, $instream, $outstream, &$payload) {
  global $node_id;
  global $node;
  @fwrite($outstream, "{\"command\": \"get-vlan-info\", \"args\": \"{$vlan_id}\"}\n}}:}}:\n");
  @fflush($outstream);
  $jresult = GetJsonResult($instream);
  if ($jresult === FALSE)
    throw new Exception("Unexpected EOF from switchtool");
  if (isset($jresult['error']))
    throw new Exception($jresult['error']);
  if (isset($jresult['vlan']['interfaces'])) {
    foreach ($jresult['vlan']['interfaces'] as $iface) {
      $result = mysql_query("SELECT id FROM prov_iface WHERE node_id = "
      . $node_id . " AND tid = '" . mysql_real_escape_string($iface) . "';");
      if (mysql_num_rows($result) == 1) {
        $row = mysql_fetch_assoc($result);
        if (!isset($payload['ifaces']))
          $payload['ifaces'] = array();
        if (!isset($payload['ifaces'][$row['id']]))
          $payload['ifaces'][$row['id']] = array();
        $payload['ifaces'][$row['id']][(string)$vlan_id] = TRUE;
      }
      mysql_free_result($result);
    }
  }
  if (isset($jresult['vlan']['name'])) {
    if (!isset($payload['self']))
      $payload['self'] = array();
    $payload['self'][(string)$vlan_id] = $jresult['vlan']['name'];
  }
}

function SanitizeVlanName($vlan_name) {
  return str_replace(' ', '_', preg_replace("/[^a-zA-Z0-9_ -]/", '', $vlan_name));
}

$nodeparams = NULL;
foreach ($params['nodes'] as $node1) {
  if ($node1['node_id'] == $node_id && isset($node1['params'])) {
    $nodeparams = $node1['params'];
    break;
  }
}
$cmds = array();
$errored = array();
$payload = array();
try {
///// READ-VLAN
  if ($params['oper'] == 'read-vlan') {
    foreach ($params['vlans'] as $vid)
      GetVlanData($vid, $pipes[1], $pipes[0], $payload);
///// MOD-VLAN
  } else if ($params['oper'] == 'mod-vlan') {
    $mod_args = '';
    if (isset($nodeparams['self_vlans'])) {
      foreach ($nodeparams['self_vlans'] as $vlan) {
        if ($vlan['oper'] == 'add') {
          $mod_args .= " create " . $vlan['vlan_id'] . " \""
          . SanitizeVlanName($vlan['vlan_name']) . "\"";
        } else if ($vlan['oper'] == 'rename') {
          $mod_args .= " rename " . $vlan['vlan_id'] . " \""
          . SanitizeVlanName($vlan['vlan_name']) . "\"";
        }
      }
    }
    if (isset($nodeparams['ifaces'])) {
      foreach ($nodeparams['ifaces'] as $iface) {
        foreach ($iface['vlans'] as $vlan) {
          if ($iface['oper'] == 'add')
            $mod_args .= " add-members " . $vlan['vlan_id'] . " iface:\"" . $iface['tid'] . "\"";
          else if ($iface['oper'] == 'remove')
            $mod_args .= " remove-members " . $vlan['vlan_id'] . " iface:\"" . $iface['tid'] . "\"";
        }
      }
    }
    if (isset($nodeparams['self_vlans'])) {
      foreach ($nodeparams['self_vlans'] as $vlan) {
        if ($vlan['oper'] == 'remove')
          $mod_args .= " delete " . $vlan['vlan_id'];
      }
    }
    if (strlen($mod_args) > 0) {
      $cmdstr = "{\"command\": \"mod-vlans\", \"args\": \""
      . escapeJsonString(trim($mod_args)) . "\"}\n}}:}}:\n";
      @fwrite($pipes[0], $cmdstr);
      @fflush($pipes[0]);
      $jresult = GetJsonResult($pipes[1]);
      if ($jresult === FALSE)
        throw new Exception("Unexpected EOF from switchtool");
      if (isset($jresult['result']['errors'])) {
        foreach ($jresult['result']['errors'] as $er)
          $errored[] = $er;
      }
    }
    if (isset($nodeparams['vlans'])) {
      foreach ($nodeparams['vlans'] as $vid)
        GetVlanData($vid, $pipes[1], $pipes[0], $payload);
    }
  }
} catch (Exception $e) {
  $errored[] = $e->getMessage();
}

@fwrite($pipes[0], "{\"end\": \"true\"}\n}}:}}:\n");
@fclose($pipes[0]);
$final = @stream_get_contents($pipes[1]);
@fclose($pipes[1]);
$retval = proc_close($p);

if (count($errored) > 0)
  Fail($errored);
else if ($retval != 0)
  Fail(array("Switchtool returned " . $retval));

mysql_query("LOCK TABLES job;");
$result = mysql_query("SELECT payload FROM job WHERE session_id = '"
. mysql_real_escape_string($session_id) . "';");
$job = mysql_fetch_assoc($result);
mysql_free_result($result);
$new_payload = array();
if ($job && $job['payload'])
  $new_payload = json_decode($job['payload'], TRUE);
if (!isset($new_payload['nodes']))
  $new_payload['nodes'] = array();
$new_payload['nodes'][(string)$node_id] = $payload;
mysql_query("UPDATE job SET payload = '"
. mysql_real_escape_string(json_encode($new_payload)) . "' WHERE session_id = '"
. mysql_real_escape_string($session_id) . "';");
mysql_query("UNLOCK TABLES;");

PostUpdate($session_id, array('nodes' => array($node_id => array(
  'name' => $node['name'],
  'status' => 2
))));
TaskCleanup($session_id);
exit(0);
