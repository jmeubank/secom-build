<?php
$config = array();
require_once(dirname(__FILE__) . '/config/site.php');
require_once(dirname(__FILE__) . '/helpers/http_helper.php');
$decrypt_key = "JTdj4W54lp[0-zy5q74UlIBKLnlKui-i";

function PostUpdate($session_id, $payload) {
  http2_post_fields('http://build.secom.net:8001/', array(
    'channel_name' => '/jobs/' . $session_id,
    'payload' => json_encode($payload),
  ));
}

function TaskSetup() {
  error_reporting(E_ALL);
  if (!@mysql_connect('127.0.0.1', 'secom_build', 'secom_build'))
    die("-Failed to connect to local MySQL server");
  if (!@mysql_select_db('secom_build'))
    die("-Failed to select secom_build MySQL db");
}

function TaskCleanup($session_id, $force_cancel = FALSE) {
  mysql_query("LOCK TABLES job WRITE;");
  $result = mysql_query("SELECT status, attached_processes FROM job "
  . "WHERE session_id = '" . mysql_real_escape_string($session_id) . "';");
  if (mysql_num_rows($result) > 0) {
    $job = mysql_fetch_assoc($result);
    if ($job['attached_processes'] == 1) {
      mysql_query("UPDATE job SET attached_processes = 0, status = 2 "
      . "WHERE session_id = '" . mysql_real_escape_string($session_id) . "';");
      PostUpdate($session_id, array('end_job' => TRUE));
    } else {
      mysql_query("UPDATE job SET attached_processes = attached_processes - 1 "
      . "WHERE session_id = '" . mysql_real_escape_string($session_id) . "';");
    }
  }
  mysql_free_result($result);
  mysql_query("UNLOCK TABLES;");
}

function IsJobCancelled($session_id) {
  $result = mysql_query("SELECT session_id FROM job WHERE session_id = '"
  . mysql_real_escape_string($session_id) . "';");
  $ret = (mysql_num_rows($result) > 0) ? TRUE : FALSE;
  mysql_free_result($result);
  return $ret;
}
