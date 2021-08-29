<?php
ini_set("display_errors", 0);
global $logger, $jsondebug, $resultObj;

$apiArray = array (
    "name"          => $_SERVER['HTTP_HOST'],
    "apiVersion"    => 1,
    "apiType"       => "NOTAUSCORE",
    "availableAPIs" => array (1)  //ToDo: Actually iterate through the available directories...
);

$resultObj =  array(
    "result"        => "success",
    "message"       => "",
    "requestId"     => null,
    "requestTime"   => time(),
    "verboseMsgs"   => array(),
    "source"        => null,
    "data"          => $apiArray
);

// Convert PHP array to JSON array
if ($jsondebug === false || $jsondebug === "false") {
    $json_data = json_encode($resultObj, null);
} else {
    $json_data = json_encode($resultObj, JSON_PRETTY_PRINT);
}
header('Content-Type: application/json');
print $json_data;
