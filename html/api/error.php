<?php
global $logger;

/*


*/

/**
 * Error handler, passes flow over the exception logger with new ErrorException.
 */
function log_error($num, $str, $file, $line)
{
    log_exception(new ErrorException($str, 0, $num, $file, $line));
}

/**
 * Uncaught exception handler.
 */
function log_exception(Throwable $e)
{
    global $logger;

    $err = createEmptyResultObject();
    $err["result"]  = 'error';
    $err["message"] = $e->getMessage();
    $err["requestTime"] = time();
    $err["verboseMsgs"] = array(get_class($e) . " at: " . $e->getFile() . ": " . $e->getLine(), $e->getTraceAsString());
    //$err["stackTrace"] = $e->getTrace();
    $err["data"] = array();
    $json_data = json_encode($err, JSON_PRETTY_PRINT);

    header('Content-Type: application/json');
    print $json_data;

    $logger->error("Error - Uncaught exception:" . $e->getMessage());
    $logger->error("\tClass: " . get_class($e));
    $logger->error("\tFile: " . $e->getFile());
    $logger->error("\tLine: " . $e->getLine());

    //exit();
}

/**
 * Checks for a fatal error, work around for set_error_handler not working on fatal errors.
 */
function check_for_fatal()
{
    global $logger;

    $e = error_get_last();
    if ( $e["type"] == E_ERROR ) {

        $err = createEmptyResultObject();
        $err["result"] = 'error';
        $err["message"] = strstr($e["message"],"\n",true);
        $err["requestTime"] = time();
        $err["verboseMsgs"] = array("At: " . $e["file"] . ":" . $e["line"]);
        $err["data"] = array();
        $json_data = json_encode($err, JSON_PRETTY_PRINT);

        header('Content-Type: application/json');
        print $json_data;

        $logger->error("Err: " . print_r($e, true));
    }
}

$logger->debug("Error handling engaged...");

register_shutdown_function("check_for_fatal");
set_error_handler("log_exception");
set_exception_handler("log_exception");
ini_set("display_errors", "off");
error_reporting(E_ALL);