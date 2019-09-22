<?php
require_once('system/application/sbcontroller.php');
class Group extends SBController {
  function t_index($int_view = '') {
    $vdata = $this->load->view('group/index', array('t_child' => $int_view),
    TRUE);
    parent::t_index($vdata);
  }

  function index() {
    $this->t_index();
  }

  function config() {
    $this->load->view('group/config', array(
      'node_types' => array(
        '0' => array('name' => "Non-Manageable"),
        '1' => array('name' => "Calix E-Series"),
        '2' => array('name' => "JUNOS Switch"),
        '3' => array('name' => "Cisco IOS Switch")
      )
    ));
  }

  function provision($group_id) {
    $group_data = $this->ajaxread(array(array(
      'model' => 'prov_group_node',
      'view' => 'by-group',
      'as' => 'nodes',
      'group_id' => (integer)$group_id
    ), array(
      'model' => 'prov_iface_link',
      'view' => 'by-group',
      'as' => 'links',
      'group_id' => (integer)$group_id
    )), '');
    if (isset($read['error'])) {
      echo $read['error'];
      return;
    }
    $this->load->view('group/provision', array(
      'group_id' => $group_id,
      'group_data' => $group_data['read']
    ));
  }

  function group_children($parent_id) {
    $q = $this->db
    ->from('prov_group')
    ->where(array('parent_id' => $parent_id))
    ->order_by('name', 'asc')
    ->get();
    $items = array();
    foreach ($q->result_array() as $root_group) {
      $newitem = array('id' => $root_group['id'], 'name' => $root_group['name']);
      $children = $this->group_children($root_group['id']);
      if (count($children) > 0)
        $newitem['children'] = $children;
      $items[] = $newitem;
    }
    $q->free_result();
    return $items;
  }

  function ajax($action = '', $model = '') {
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    $session_id = $this->session->userdata('sb_session_id');
    if ($session_id === FALSE) {
      $session_id = sha1($this->session->userdata('session_id'));
      $this->session->set_userdata('sb_session_id', $session_id);
    }

    $ret = array();
    if ($action == 'block') {
      $lock_base = $this->input->get('lock_base');
      if ($lock_base === FALSE)
        return jsonize_error("No lock base string provided");
      $lock_base = str_replace('%sid%', $session_id, $lock_base);
      $lock_id = $this->input->get('lock_id');
      if ($lock_id === FALSE)
        return jsonize_error("No lock ID provided");
      $lock_id = (integer)$lock_id;
      $q = $this->db->query("SELECT GET_LOCK('secom_build."
      . mysql_real_escape_string($lock_base) . '.' . $lock_id
      . "', 10) as result;");
      $row = $q->row_array();
      $q->free_result();
      if ($row['result'] == '1') {
        $this->db->query("SELECT RELEASE_LOCK('secom_build."
        . mysql_real_escape_string($lock_base) . '.' . $lock_id
        . "');")->free_result();
        $ret['lock_result'] = 'obtained';
      } else
        $ret['lock_result'] = 'timeout';
    } else if ($action != 'read') {
      $p = $this->ajaxpost($action, $model, $session_id);
      if ($p !== TRUE)
        return jsonize_error($p);
    }

    $return_params = ($this->input->server('REQUEST_METHOD') == 'GET')
    ? $this->input->get('return') : $this->input->post('return');
    if ($return_params !== FALSE) {
      $read = $this->ajaxread(json_decode($return_params, TRUE), $session_id);
      if (isset($read['error']))
        return jsonize_error($read['error']);
      $ret = array_merge($ret, $read['read']);
    }

    return jsonize_success($ret);
  }

  function ajaxread($params, $session_id) {
    $return = array();
    foreach ($params as $param) {
      if (!isset($param['model']))
        return array('error' => "No model specified for ajaxread");
      $model = $param['model'];
      if (!isset($param['as']))
        return array('error' => "No alias specified for ajaxread");
      $as = $param['as'];
      switch ($model) {
        case 'job':
          if (isset($param['action']) && $param['action'] == 'request') {
            $this->db->query('LOCK TABLES `job` WRITE;');
            $this->db->query('DELETE FROM `job` WHERE DATE_ADD(`valid`, INTERVAL 1 HOUR) < NOW();');
            if ($this->db->count_all_results('job') >= 10) {
              $this->db->query('UNLOCK TABLES;');
              return array('error' => "Too many jobs are already running");
            }
            $q = $this->db->select('status')->from('job')
             ->where('session_id', $session_id)->get();
            if ($q->num_rows() > 0) {
              $row = $q->row_array();
              if ($row['status'] != 0) {
                $this->db->update('job', array('status' => 3),
                 array('session_id' => $session_id)); // cancel
                $this->db->query('UNLOCK TABLES;');
                return array('error' => "Your session already has a running job, please try again in a few seconds");
              }
            } else {
              $this->db->insert('job', array(
                'session_id' => $session_id,
                'status' => 0
              ));
            }
            $q->free_result();
            $this->db->query('UNLOCK TABLES;');
            $return[$as] = $session_id;
          } else if (isset($param['action']) && $param['action'] == 'retrieve') {
            $this->db->query('LOCK TABLES job WRITE;');
            $q = $this->db
            ->select('status, payload')
            ->from('job')
            ->where('session_id', $session_id)
            ->get();
            if ($q->num_rows() <= 0) {
              $q->free_result();
              $this->db->query('UNLOCK TABLES;');
              return array('error' => "No job exists for your session");
            }
            $row = $q->row_array();
            $q->free_result();
            if ($row['status'] != 2) {
              $this->db->query('UNLOCK TABLES;');
              return array('error' => "Your session still has a running job, please try again in a few seconds");
            }
            $this->db->delete('job', array('session_id' => $session_id));
            $this->db->query('UNLOCK TABLES;');
            $payload = json_decode($row['payload'], TRUE);
            $return[$as] = $payload['nodes'];
          } else
            return array('error' => "Invalid or unspecified job action");
          break;
        case 'prov_group':
          if (!isset($param['view']) || $param['view'] == 'object') {
            $q = $this->db
            ->from('prov_group')
            ->where(array('id' => $this->input->get('id')))
            ->get();
            $return[$as] = ($q->num_rows() > 0) ? $q->row_array() : array();
            $q->free_result();
          } else if ($param['view'] == 'tree') {
            $return[$as] = array(
              'identifier' => 'id',
              'label' => 'name',
              'items' => $this->group_children(null)
            );
          } else if ($param['view'] == 'by-node') {
            if (!isset($param['node_id']))
              return array('error' => "No node ID provided");
            $q = $this->db
            ->select('prov_group.id, prov_group.name')
            ->from('prov_group')
            ->join('prov_group_node', 'prov_group.id = prov_group_node.group_id')
            ->where(array('prov_group_node.node_id' => $param['node_id']))
            ->order_by('prov_group.name', 'asc')
            ->get();
            $return[$as] = $q->result_array();
            $q->free_result();
          }
          break;
        case 'prov_group_node':
          if (!isset($param['view']) || $param['view'] == 'object') {
          } else if ($param['view'] == 'by-group') {
            if (!isset($param['group_id']))
              return array('error' => "No group ID provided");
            $q = $this->db
            ->select('prov_group_node.node_id, prov_node.name, prov_node.node_type, prov_group_node.pos_x, prov_group_node.pos_y')
            ->from('prov_node')
            ->join('prov_group_node', 'prov_node.id = prov_group_node.node_id')
            ->where(array('prov_group_node.group_id' => $param['group_id']))
            ->get();
            $return[$as] = $q->result_array();
            $q->free_result();
          }
          break;
        case 'prov_iface':
          if (!isset($param['node_id']))
            return array('error' => "No node ID provided");
          $q = $this->db
          ->select('node_type')
          ->from('prov_node')
          ->where(array('id' => $param['node_id']))
          ->get();
          if ($q->num_rows() <= 0)
            return array('error' => "Invalid node ID: " . $param['node_id']);
          $n = $q->row_array();
          $q->free_result();
          $qstring = "SELECT `prov_iface`.`id`, `prov_iface`.`tid`, "
          . "`prov_iface`.`speed`, `prov_iface`.`lag_ct`, `prov_iface`.`descr`, "
          . "`prov_node`.`name` AS link_node_name, `prov_iface_2`.`id` AS link_id, "
          . "`prov_iface_2`.`tid` AS link_tid, `prov_node_unmg`.`id` as unmg_id, "
          . "`prov_node_unmg`.`name` as unmg_name "
          . "FROM (((`prov_iface` LEFT JOIN `prov_iface_link` ON `prov_iface`.`id` = `prov_iface_link`.`iface_id_1` "
          . "OR `prov_iface`.`id` = `prov_iface_link`.`iface_id_2`) "
          . "LEFT JOIN `prov_iface` AS prov_iface_2 "
          . "ON (`prov_iface_link`.`iface_id_1` = `prov_iface_2`.`id` OR `prov_iface_link`.`iface_id_2` = `prov_iface_2`.`id`) "
          . "AND `prov_iface_2`.`id` <> `prov_iface`.`id`) "
          . "LEFT JOIN `prov_node` ON `prov_iface_2`.`node_id` = `prov_node`.`id`) "
          . "LEFT JOIN `prov_node` AS prov_node_unmg ON `prov_iface_link`.`node_id_2` = `prov_node_unmg`.`id` "
          . "WHERE `prov_iface`.`node_id` = ? ";
          if (isset($param['view']) && $param['view'] == 'linklist')
            $qstring .= "AND `prov_iface_link`.`iface_id_1` IS NULL ";
          if ($n['node_type'] == '1') { //Calix
            $qstring .= "ORDER BY `prov_iface`.`lag_ct` IS NULL, "
            . "IF(`prov_iface`.`lag_ct` IS NOT NULL, `prov_iface`.`tid`, 0), "
            . "IF(`prov_iface`.`lag_ct` IS NULL AND SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1) != `prov_iface`.`tid`, 0 + SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1), 0), "
            . "IF(`prov_iface`.`lag_ct` IS NULL AND SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1) != `prov_iface`.`tid`, LEFT(SUBSTRING_INDEX(`prov_iface`.`tid`, '/', -1), 1), 0), "
            . "IF(`prov_iface`.`lag_ct` IS NULL AND SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1) != `prov_iface`.`tid`, 0 + SUBSTRING(SUBSTRING_INDEX(`prov_iface`.`tid`, '/', -1), 2), 0), "
            . "IF(`prov_iface`.`lag_ct` IS NULL AND SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1) = `prov_iface`.`tid`, LEFT(`prov_iface`.`tid`, 1), 0), "
            . "IF(`prov_iface`.`lag_ct` IS NULL AND SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1) = `prov_iface`.`tid`, 0 + SUBSTRING(`prov_iface`.`tid`, 2), 0)";
          } else if ($n['node_type'] == '2') { //JunOS
            $qstring .= "ORDER BY LEFT(`prov_iface`.`tid`, 2), "
            . "0 + SUBSTRING_INDEX(SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1), '-', -1), "
            . "0 + SUBSTRING_INDEX(SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 2), '/', -1), "
            . "0 + SUBSTRING_INDEX(`prov_iface`.`tid`, '/', -1)";
          } else if ($n['node_type'] == '3') { //Cisco
            $qstring .= "ORDER BY LEFT(`prov_iface`.`tid`, 1), "
            . "0 + SUBSTRING_INDEX(SUBSTRING_INDEX(`prov_iface`.`tid`, '/', 1), LEFT(`prov_iface`.`tid`, 2), -1), "
            . "0 + SUBSTRING_INDEX(`prov_iface`.`tid`, '/', -1)";
          }
          $qstring .= ";";
          $q = $this->db->query($qstring, array($param['node_id']));
          $return[$as] = $q->result_array();
          $q->free_result();
          break;
        case 'prov_iface_link':
          if (!isset($param['view']) || $param['view'] == 'object') {
          } else if ($param['view'] == 'by-group') {
            if (!isset($param['group_id']))
              return array('error' => "No group ID provided");
            $q = $this->db
            ->select('prov_iface_1.node_id as node_id_1, '
            . 'prov_iface_1.id as iface_id_1, prov_iface_1.tid as iface_tid_1, '
            . 'prov_iface_1.descr as iface_descr_1, '
            . 'prov_iface_1.speed as iface_speed_1, '
            . 'prov_iface_1.lag_ct as iface_lag_ct_1, '
            . 'prov_iface_2.node_id as node_id_2, prov_iface_2.id as iface_id_2, '
            . 'prov_iface_2.tid as iface_tid_2, '
            . 'prov_iface_2.descr as iface_descr_2, '
            . 'prov_iface_2.speed as iface_speed_2, '
            . 'prov_iface_2.lag_ct as iface_lag_ct_2, '
            . 'prov_iface_link.node_id_2 as unmg_node_id')
            ->from('((prov_iface_link LEFT JOIN prov_iface AS prov_iface_1 ON prov_iface_link.iface_id_1 = prov_iface_1.id) '
            . 'LEFT JOIN prov_iface AS prov_iface_2 on prov_iface_link.iface_id_2 = prov_iface_2.id) '
            . 'LEFT JOIN prov_group_node ON prov_iface_1.node_id = prov_group_node.node_id')
            ->where(array('prov_group_node.group_id' => $param['group_id']))
            ->get();
            $return[$as] = $q->result_array();
            $q->free_result();
          }
          break;
        case 'prov_node':
          if (!isset($param['view']) || $param['view'] == 'object') {
            if (!isset($param['id']))
              return array('error' => "No node ID provided");
            $q = $this->db->get_where('prov_node', array('id' => $param['id']));
            $return[$as] = ($q->num_rows() > 0) ? $q->row_array() : array();
            $q->free_result();
          } else if ($param['view'] == 'tree') {
            $q = $this->db
            ->from('prov_node')
            ->order_by('name', 'asc')
            ->get();
            $return[$as] = array(
              'identifier' => 'id',
              'label' => 'name',
              'items' => $q->result_array()
            );
          } else if ($param['view'] == 'linklist') {
            $q = $this->db
            ->select('id, name, node_type')
            ->from('prov_node')
            ->where('id !=', $this->input->get('node_id'))
            ->order_by('name', 'asc')
            ->get();
            $return[$as] = $q->result_array();
          }
          break;
        case 'session_id':
          $return[$as] = $session_id;
          break;
      }
    }
    if (isset($return['root'])) {
      $root = $return['root'];
      unset($return['root']);
      $return = array_merge($return, $root);
    }
    return array('read' => $return);
  }

  function ajaxpost($action, $model, $session_id) {
    switch ($model) {
      case 'prov_group':
        if ($action == 'create') {
          $this->db->insert('prov_group', array('name' => $this->input->post('group_name')));
          return TRUE;
        } else if ($action == 'update') {
          if ($this->input->post('update_parent')) {
            $p = $this->input->post('parent');
            if ((integer)$p < 1)
              $p = null;
            $this->db->update('prov_group',
            array('parent_id' => $p),
            array('id' => $this->input->post('child')));
          } else {
            $this->db->update('prov_group',
            array('name' => $this->input->post('group_name')),
            array('id' => $this->input->post('group_id')));
          }
          return TRUE;
        } else if ($action == 'delete') {
          $this->db->delete('prov_group_node', array('group_id' => $this->input->post('group_id')));
          $this->db->delete('prov_group', array('id' => $this->input->post('group_id')));
          return TRUE;
        }
        break;
      case 'prov_node':
        switch ($action) {
          case 'create':
            $newdata = array(
              'ip' => $this->input->post('node_ip'),
              'node_type' => $this->input->post('node_type'),
              'name' => $this->input->post('node_name'),
              'cred_username' => $this->input->post('cred_username'),
              'snmp_comm' => $this->input->post('snmp_comm')
            );
            if ($this->input->post('cred_plainpass')) {
              $encpass_and_iv = $this->encodepass($this->input->post('cred_plainpass'));
              $newdata['cred_encpass'] = base64_encode($encpass_and_iv['encpass']);
              $newdata['cred_enc_iv'] = base64_encode($encpass_and_iv['iv']);
            } else {
              $newdata['cred_encpass'] = NULL;
              $newdata['cred_enc_iv'] = NULL;
            }
            $this->db->insert('prov_node', $newdata);
            return TRUE;
          case 'update':
            $updata = null;
            if ($this->input->post('update_creds')) {
              $updata = array('cred_username' => $this->input->post('cred_username'));
              if ($this->input->post('cred_plainpass')) {
                $encpass_and_iv = $this->encodepass($this->input->post('cred_plainpass'));
                $updata['cred_encpass'] = base64_encode($encpass_and_iv['encpass']);
                $updata['cred_enc_iv'] = base64_encode($encpass_and_iv['iv']);
              } else {
                $updata['cred_encpass'] = NULL;
                $updata['cred_enc_iv'] = NULL;
              }
            } else {
              $updata = array(
                'ip' => $this->input->post('node_ip'),
                'node_type' => $this->input->post('node_type'),
                'name' => $this->input->post('node_name'),
                'snmp_comm' => $this->input->post('snmp_comm')
              );
            }
            $this->db->update('prov_node', $updata, array('id' => $this->input->post('node_id')));
            return TRUE;
          case 'delete':
            $q = $this->db
            ->select('id')
            ->from('prov_iface')
            ->where(array('node_id' => $this->input->post('node_id')))
            ->get();
            foreach ($q->result_array() as $iface) {
              $this->db
              ->from('prov_iface_link')
              ->where(array('iface_id_1' => $iface['id']))
              ->or_where(array('iface_id_2' => $iface['id']))
              ->delete();
            }
            $this->db->delete('prov_iface', array('node_id' => $this->input->post('node_id')));
            $this->db->delete('prov_group_node', array('node_id' => $this->input->post('node_id')));
            $this->db->delete('prov_node', array('id' => $this->input->post('node_id')));
            return TRUE;
        }
        break;
      case 'prov_group_node':
        if ($action == 'create') {
          $q = $this->db
          ->from('prov_group_node')
          ->where(array(
            'group_id' => $this->input->post('group_id'),
            'node_id' => $this->input->post('node_id')
          ))
          ->get();
          if ($q->num_rows() > 0)
            return "This node is already a member of that group";
          $this->db->insert('prov_group_node', array(
            'group_id' => $this->input->post('group_id'),
            'node_id' => $this->input->post('node_id')
          ));
          return TRUE;
        } else if ($action == 'update') {
          $method = $this->input->post('method');
          if ($method == 'jsonset') {
            $set = json_decode($this->input->post('set'), TRUE);
            foreach ($set as $setkey => $setval) {
              $setkeyarr = explode('/', $setkey);
              $node_id = $setkeyarr[0];
              $prop = $setkeyarr[1];
              if ($prop == 'pos') {
                $updata = array(
                  'pos_x' => $setval['x'],
                  'pos_y' => $setval['y']
                );
                $this->db
                ->where('group_id', $setval['group_id'][0])
                ->where('node_id', $node_id)
                ->update('prov_group_node', $updata);
              }
            }
            return TRUE;
          }
        } else if ($action == 'delete') {
          $this->db->delete('prov_group_node', array(
            'group_id' => $this->input->post('group_id'),
            'node_id' => $this->input->post('node_id')
          ));
          return TRUE;
        }
        break;
      case 'prov_iface_link':
        if ($action == 'create') {
          $q = $this->db->from('prov_iface_link');
          if ($this->input->post('unmgd_id2') == 'true') {
            $q->where(array(
              'iface_id_1' => $this->input->post('id1'),
              'node_id_2' => $this->input->post('id2')));
          } else {
            $q->where(array(
              'iface_id_1' => $this->input->post('id1'),
              'iface_id_2' => $this->input->post('id2')))
            ->or_where(array(
              'iface_id_1' => $this->input->post('id2'),
              'iface_id_2' => $this->input->post('id1')));
          }
          $q = $q->get();
          if ($q->num_rows() > 0)
            return "These interfaces are already linked";
          if ($this->input->post('unmgd_id2') == 'true') {
            $this->db->insert('prov_iface_link', array(
              'iface_id_1' => $this->input->post('id1'),
              'node_id_2' => $this->input->post('id2')
            ));
          } else {
            $this->db->insert('prov_iface_link', array(
              'iface_id_1' => $this->input->post('id1'),
              'iface_id_2' => $this->input->post('id2')
            ));
          }
          //$this->load->library('ringanalyzer');
          //$this->ringanalyzer->calculate_rings($this);
          return TRUE;
        } else if ($action == 'delete') {
          $q = $this->db
          ->from('prov_iface_link');
          if ($this->input->post('unmgd')) {
            $q->where(array(
              'iface_id_1' => $this->input->post('id1'),
              'node_id_2' => $this->input->post('id2')));
          } else {
            $q->where(array(
              'iface_id_1' => $this->input->post('id1'),
              'iface_id_2' => $this->input->post('id2')))
            ->or_where(array(
              'iface_id_1' => $this->input->post('id2'),
              'iface_id_2' => $this->input->post('id1')));
          }
          $q->delete();
          //$this->load->library('ringanalyzer');
          //$this->ringanalyzer->calculate_rings($this);
          return TRUE;
        }
        break;
      case 'node_scan':
        if ($action == 'create') {
          $node_id = $this->input->post('node_id');
          if ($node_id === FALSE)
            return "Need node ID to load";
          $cmd = str_replace('%args%', $node_id, $this->config->item('sb_node_scanner'));
          $child = popen($cmd, 'r');
          if ($child === FALSE)
            return "Couldn't create node scanner process";
          $resultchar = fgetc($child);
          $ret = TRUE;
          if ($resultchar != '+')
            $ret = stream_get_contents($child);
          pclose($child);
          return $ret;
        }
        break;
      case 'job':
        if ($action == 'run') {
          $error_msg = 'Unspecified error';
          do {
            $job_type = $this->input->post('type');
            if ($job_type === FALSE) {
              $error_msg = "No job type specified";
              break;
            }
            if ($job_type == 'readvlan') {
              $group_id = (integer)$this->input->post('group_id');
              $vlans = json_decode($this->input->post('vlans'), TRUE);
              $q = $this->db
              ->select('prov_group_node.node_id')
              ->from('prov_group_node')
              ->join('prov_node', 'prov_group_node.node_id = prov_node.id', 'left')
              ->where('prov_group_node.group_id', $group_id)
              ->where('prov_node.node_type >', 0)
              ->get();
              $params = json_encode(array('oper' => 'read-vlan',
               'group_id' => $group_id, 'nodes' => $q->result_array(),
               'vlans' => $vlans));
              $q->free_result();
            } else if ($job_type == 'modvlan') {
              $modnodes = json_decode($this->input->post('modnodes'), TRUE);
              if (count($modnodes) <= 0) {
                $error_msg = "No nodes supplied for modification";
                break;
              }
              $params = json_encode(array('oper' => 'mod-vlan',
               'nodes' => $modnodes));
            } else {
              $error_msg = "Invalid job type: " . $job_type;
              break;
            }
            $this->db->query('LOCK TABLES `job` WRITE;');
            if ($this->db->from('job')->where('session_id', $session_id)
             ->where('status', 0)->count_all_results() < 1) {
              $error_msg = "No job requested yet for this session";
              break;
            }
            $this->db->set('params', $params)
             ->set('attached_processes', '`attached_processes` + 1', FALSE)
             ->where(array('session_id' => $session_id))->update('job');
            $cmd = str_replace('%args%', $session_id,
             $this->config->item('sb_job_starter'));
            $child = popen($cmd, 'r');
            if ($child === FALSE) {
              $error_msg = "Couldn't run job starter";
              break;
            }
            $resultchar = fgetc($child);
            if ($resultchar != '+') {
              $error_msg = stream_get_contents($child);
              pclose($child);
              break;
            }
            pclose($child);
            $this->db->query('UNLOCK TABLES;');
            return TRUE;
          } while (FALSE);
          $this->db->delete('job', array('session_id' => $session_id));
          $this->db->query('UNLOCK TABLES;');
          return $error_msg;
        } else if ($action == 'cancel') {
          $this->db->delete('job', array('session_id' => $session_id));
          return TRUE;
        }
        break;
    }
    return jsonize_error("Invalid AJAX URI: " . $this->uri->uri_string());
  }

  function encodepass($plaintext_pass) {
    $iv = mcrypt_create_iv(32, MCRYPT_DEV_URANDOM);
    $key = "JTdj4W54lp[0-zy5q74UlIBKLnlKui-i";
    $encpass = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $plaintext_pass,
    MCRYPT_MODE_CBC, $iv);
    return array('encpass' => $encpass, 'iv' => $iv);
  }
}
