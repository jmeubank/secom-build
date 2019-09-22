<?php
class RingAnalyzer {
  function calculate_rings(&$CI = NULL) {
    $nodes = array();
    if ($CI === NULL) {
      mysql_connect('127.0.0.1', 'secom_build', 'secom_build');
      mysql_select_db('secom_build');
      $result = mysql_query("SELECT id, ip, name, ring_checking "
      . "FROM prov_node WHERE node_type > 0 AND (ring_checking + 0) > 0;");
      if (mysql_num_rows($result) <= 0)
        return;
      while ($node = mysql_fetch_assoc($result)) {
        $nodes[$node['id']] = $node;
        $nodes[$node['id']]['links'] = array();
        $nodes[$node['id']]['visited'] = FALSE;
      }
      mysql_free_result($result);
    } else {
      $q = $CI->db
      ->select('id, ip, name, ring_checking')
      ->from('prov_node')
      ->where('node_type >', 0)
      ->where('ring_checking !=', '0')
      ->get();
      if ($q->num_rows() <= 0)
        return;
      foreach ($q->result_array() as $node) {
        $nodes[$node['id']] = $node;
        $nodes[$node['id']]['links'] = array();
        $nodes[$node['id']]['visited'] = FALSE;
      }
      $q->free_result();
    }

    $ifaces = array();
    if ($CI === NULL) {
      $result = mysql_query("SELECT id, node_id, tid FROM prov_iface;");
      if (mysql_num_rows($result) <= 0)
        return;
      while ($iface = mysql_fetch_assoc($result))
        $ifaces[$iface['id']] = $iface;
      mysql_free_result($result);
    } else {
      $q = $CI->db
      ->select('id, node_id, tid')
      ->from('prov_iface')
      ->get();
      if ($q->num_rows() <= 0)
        return;
      foreach ($q->result_array() as $iface)
        $ifaces[$iface['id']] = $iface;
      $q->free_result();
    }

    if ($CI === NULL) {
      $result = mysql_query("SELECT * FROM prov_iface_link;");
      if (mysql_num_rows($result) <= 0)
        return;
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
    } else {
      $q = $CI->db->get('prov_iface_link');
      if ($q->num_rows() <= 0)
        return;
      foreach ($q->result_array() as $link) {
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
      $q->free_result();
    }

    if ($CI === NULL) {
      mysql_query("DELETE FROM ring_iface;");
      mysql_query("DELETE FROM ring;");
    } else {
      $CI->db->delete('ring_iface', array('ring_id >' => 0));
      $CI->db->delete('ring', array('id >' => 0));
    }

    // Loop until all nodes are visited at least once
    $ring_id = 1;
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
            $differ = FALSE;
            foreach ($cycle as $nid => $nvals) {
              if (!isset($cmp_cycle[$nid])) {
                $differ = TRUE;
                break;
              }
            }
            foreach ($cmp_cycle as $nid => $nvals) {
              if (!isset($cycle[$nid])) {
                $differ = TRUE;
                break;
              }
            }
            if (!$differ) {
              $matched = TRUE;
              break;
            }
          }
          if (!$matched) {
            $cycles[] = $cycle;
            for ($i = $in_stack_idx; $i < $stack_height; $i++) {
              if ($i > $in_stack_idx)
                $cycle[$stack[$i]['node_id']]['iface1'] = $nodes[$stack[$i]['node_id']]['links'][$stack[$i]['from_idx']]['iface'];
              $cycle[$stack[$i]['node_id']]['iface2'] = $nodes[$stack[$i]['node_id']]['links'][$stack[$i]['visit_idx']]['iface'];
            }
            $cycle[$stack[$in_stack_idx]['node_id']]['iface1'] = $nodes[$stack[$stack_height - 1]['node_id']]['links'][$stack[$stack_height - 1]['visit_idx']]['op_iface'];
            $all_mstp = TRUE;
            foreach ($cycle as $nid => $nvals) {
              if ($nodes[$nid]['ring_checking'] != '2')
                $all_mstp = FALSE;
              if ($CI === NULL) {
                mysql_query("INSERT INTO ring_iface (ring_id, iface_id) VALUES ("
                . $ring_id . ", " . $nvals['iface1'] . ");");
                mysql_query("INSERT INTO ring_iface (ring_id, iface_id) VALUES ("
                . $ring_id . ", " . $nvals['iface2'] . ");");
              } else {
                $CI->db->insert('ring_iface', array(
                  'ring_id' => $ring_id,
                  'iface_id' => $nvals['iface1']
                ));
                $CI->db->insert('ring_iface', array(
                  'ring_id' => $ring_id,
                  'iface_id' => $nvals['iface2']
                ));
              }
            }
            if ($CI === NULL) {
              mysql_query("INSERT INTO ring (id, mstp) VALUES (" . $ring_id
              . ", '" . (($all_mstp) ? '1' : '0') . "');");
            } else {
              $CI->db->insert('ring', array(
                'id' => $ring_id,
                'mstp' => ($all_mstp) ? '1' : '0'
              ));
            }
            $ring_id++;
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
  }
}
