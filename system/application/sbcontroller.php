<?php
class SBController extends Controller {
  function t_index($int_view) {
    $this->load->view('index', array('t_child' => $int_view));
  }
}
