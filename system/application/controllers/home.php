<?php
require_once('system/application/sbcontroller.php');
class Home extends SBController {
  function index() {
    redirect('/group');
  }
}
