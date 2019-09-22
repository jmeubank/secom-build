<?php

function jsonize_success($output_data) {
  $output_data['success'] = true;
  echo json_encode($output_data);
}

function jsonize_error($error_msg) {
  echo json_encode(array('success' => false, 'error' => $error_msg));
}
