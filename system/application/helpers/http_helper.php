<?php
require_once('HTTP/Request2.php');

function http2_post_fields($url, $fields) {
  $request = new HTTP_Request2($url, HTTP_Request2::METHOD_POST);
  foreach ($fields as $fname => $fvalue)
    $request->addPostParameter($fname, $fvalue);
  try {
    $response = $request->send();
    if (200 == $response->getStatus())
      return $response->getBody();
    else
      return FALSE;
  } catch (HTTP_Request2_Exception $e) {
    return FALSE;
  }
}
