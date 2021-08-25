<?php
require_once __DIR__.'/../include/Psr/Log/LoggerInterface.php';
require_once __DIR__.'/../include/Psr/Log/InvalidArgumentException.php';
require_once __DIR__.'/../include/Psr/Log/LoggerAwareInterface.php';
require_once __DIR__.'/../include/Psr/Log/LoggerAwareTrait.php';
require_once __DIR__.'/../include/Psr/Log/LoggerTrait.php';
require_once __DIR__.'/../include/Psr/Log/LogLevel.php';
require_once __DIR__.'/../include/Psr/Log/AbstractLogger.php';
require_once __DIR__.'/../include/Psr/Log/NullLogger.php';
require_once __DIR__.'/../include/KLogger/Logger.php';

# Let's not use the standard session stuff.   We're relying on JWT anyway...
#require_once __DIR__.'/../../session.php';

#Set some definitions:
define('API_FILTER_MATCH',          1);
define('API_FILTER_LIKE',           2);
define('API_FILTER_NUMEERIC_RANGE', 3);


global $db;


// Include the sconfig file, or create it if it does not exist...
// onfig file contains, amongst other things, the log level..
if (!file_exists('../config.php')) {
    $fh = fopen('../config.php', 'w');
    $secret = random_str(32);
    fwrite($fh, "<?php\n\$log_level = Psr\\Log\\LogLevel::DEBUG;\n");
    fclose($fh);
}
include_once '../config.php';
global $log_level;

# Set up logging object.
$logger = new Katzgrau\KLogger\Logger(__DIR__.'/../logs', $log_level, array (
    'extension' => 'log', // changes the log file extension
    'prefix' => 'ns_',
));

require_once __DIR__.'/../../conf/constant.php';  // Import the DB connection stuff from NotauScore itself.
require_once __DIR__.'/../../api/error.php';


function random_str(
    $length,
    $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
) {
    $str = '';
    $max = mb_strlen($keyspace, '8bit') - 1;
    if ($max < 1) {
        throw new Exception('$keyspace must be at least two characters long');
    }
    for ($i = 0; $i < $length; ++$i) {
        $str .= $keyspace[random_int(0, $max)];
    }
    return $str;
}

function createEmptyResultObject() {
    return array(
        "result"          => null,
        "message"         => null,
        //"requestId"       => null,
        "requestTime"     => null,
        "verboseMsgs"     => array(),
        //"source"          => null,
        "data"            => null
    );
}

function mergeResultMessages(&$resultObj, $resultObjToAppend) {
    if (!empty($resultObjToAppend["verboseMsgs"])) {
        $resultObj["verboseMsgs"] = array_merge ($resultObj["verboseMsgs"], $resultObjToAppend["verboseMsgs"]);
    }
    if ($resultObjToAppend["message"] != null) {
        $resultObj["verboseMsgs"][] = $resultObjToAppend["message"];
    }
    switch ($resultObjToAppend["result"]) {
        case "error":
            $resultObj["result"] = "error";
            $resultObj["message"] = $resultObjToAppend["message"];
            break;

        case "warn":
        case "warning":
            if ($resultObj["result"] === "success") {
                $resultObj["result"] = "warn";
                $resultObj["message"] = $resultObjToAppend["message"];
            }
            break;
    }
}

function dbConnect() {
    global $db, $logger;
    try {
        $db = new PDO("mysql:host=" . PROD_HOST . ";dbname=" . PROD_DBNAME, PROD_USER, PROD_PASSWORD);
        // set the PDO error mode to exception
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $logger->debug("Connected successfully to DB " . PROD_DBNAME . " on host " . PROD_HOST);

    } catch(PDOException $e) {
        $logger->error("Connection to DB " . PROD_DBNAME . " on host " . PROD_HOST . " failed: " . $e->getMessage());
        return false;
    }

    try {
        $attributes = array(
            "AUTOCOMMIT", "ERRMODE", "CASE", "CLIENT_VERSION", "CONNECTION_STATUS",
            "ORACLE_NULLS", "PERSISTENT", "SERVER_INFO", "SERVER_VERSION"
        );

        foreach ($attributes as $val) {
            $logger->debug ("PDO::ATTR_$val: " . $db->getAttribute(constant("PDO::ATTR_$val")) );
        }
    } catch (PDOException $e) {
        $logger->debug ("PDO::ATTR_$val: Attribute Unsupported");
    }

    return true;
}

function dbConnectND() {
    global $db;
// Create connection
    $db = new mysqli(PROD_HOST, PROD_USER, PROD_PASSWORD, PROD_DBNAME);

// Check connection
    if ($db->connect_error) {
        die("Connection failed: " . $db->connect_error);
    }
    echo "Connected successfully";

    return true;
}

function dbDisconnectND() {
    global $db;
    $db->close;
}

function dbDisconnect() {
    global $db;
    $db = null;
}

function getIfSet(&$value, $default = null) {
    return isset($value) ? $value : $default;
}

function addFilterParamIfExists (&$paramArray, &$query, $param, $param_tablekey = null, $mode = API_FILTER_MATCH) {
    global $logger;
    // If the param is included in the querystring or postvars then add it to the SQL query param array and add a AND clause to the SQL.
    if (isset($param_tablekey)) {
        $param_name = "$param_tablekey.$param";
    } else {
        $param_name = $param;
    }
    if (isset($_REQUEST[$param])) {
        switch ($mode) {
            case API_FILTER_MATCH:
                $paramArray[$param] = getIfSet($_REQUEST[$param]);
                $query .= " AND $param_name = :$param";
                break;
            case API_FILTER_LIKE:
                $logger->warning("API_FILTER_LIKE is not yet implemented.");
                break;
            case API_FILTER_NUMEERIC_RANGE:
                $logger->warning("API_FILTER_NUMEERIC_RANGE is not yet implemented.");
                break;
            default:
                $logger->error("Unknown filter.");
                break;
        }
    }
}

function getSQLCommand($statement) {
    if ($statement === null) {
        return null;
    }
    ob_start();
    $statement->debugDumpParams();
    $r = ob_get_contents();
    ob_end_clean();
    return $r;
}

function convertResultsToIDArray (&$resultsArray, $idField) {
    global $logger;

    if (is_array($resultsArray)) {
        $newArray = array();
        /*  Convert an array that looks like this:

            "figures": [
                        {
                            "figid": "2369",
                            "figdescription": "Lay Down Humpty Bump"
                        },
                        {
                            "figid": "2370",
                            "figdescription": "Immelmann"
                        },
                        {
                            "figid": "2371",
                            "figdescription": "1 1/2 PositiveTurn Spin"
                        }
                       ]


             To something that looks like this:
            "figures": [
                        2369: {
                            "figdescription": "Lay Down Humpty Bump"
                        },
                        2370: {
                            "figdescription": "Immelmann",
                        },
                        2371: {
                            "figdescription": "1 1/2 PositiveTurn Spin"
                        }
                       ]

         *
         */
        foreach ($resultsArray as &$result) {
            if (isset($result[$idField])) {
                if (isset($newArray[$result[$idField]])) {
                    $logger->error("The field $idField is not unique for this element of the results array.   Duplicate: " . $result[$idField]);
                    return false;
                }
                $newArray[$result[$idField]] = $result;
            } else {
                $logger->error("The field $idField does not exist for this element of the results array.");
                return false;
            }
        }
        unset ($result);
        $resultsArray = $newArray;
        return true;
    } else {
        return (false);
    }
}

function stripColumnsFromResults (&$resultsArray, $toRemove) {
    if (is_array($toRemove)) {
        foreach ($toRemove as $column) {
            stripColumnFromResults($resultsArray, $column);
        }
    } else {
        stripColumnFromResults($resultsArray, $toRemove);
    }
}

function stripColumnFromResults (&$resultsArray, $column) {
    foreach ($resultsArray as &$record) {
        if (isset($record[$column])) {
            unset($record[$column]);
        }
    }
    unset ($record);
}

function doSQL (&$resultObj, $query, $paramArr = null) {
    global $db, $logger;

    try {
        if ($statement = $db->prepare($query)) {
            if (isset($paramArr) && is_array($paramArr)) {
                foreach ($paramArr as $key => $value) {
                    $statement->bindValue(":$key", $value);
                }
            }
            $logger->debug("Executing SQL command: " . getSQLCommand($statement));
            if (!$statement->execute()) {
                $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
                $resultObj["result"]  = 'error';
                $resultObj["message"] = "There was an error executing the database call in function " . $bt[1]["function"] . ".  See logs for more detailed info.";
                array_push($resultObj["verboseMsgs"], ("In " . $bt[1]["function"] . ": Could not get data. Err: " . $db->lastErrorMsg()));
                $logger->error($resultObj["message"]);
                return false;
            }
        } else {
            $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
            $resultObj["result"]  = 'error';
            $resultObj["message"] = "There was an error executing the database call in function " . $bt[1]["function"] . ".  See logs for more detailed info.";
            array_push($resultObj["verboseMsgs"], ("In " . $bt[1]["function"] . ": Could not get data. Err: " . $db->lastErrorMsg()));
            return false;
        }
    } catch (Exception $e) {
        $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
        $resultObj["result"]  = 'error';
        $resultObj["message"] = "There was an error executing the database call in function " . $bt[1]["function"] . ".  See logs for more detailed info.";
        array_push($resultObj["verboseMsgs"], ("In " . $bt[1]["function"] . ": query error: " . $e->getMessage()));
        $logger->error($resultObj["message"]);
        return false;
    }
    return $statement;
}
