<?php

require_once  __DIR__ . '/functions.php';

/**
 * @param $resultObj
 * @param $creds
 *
 * Using JWT.   Checks supplied credentials against known users and then returns a token (or reason for unauth).
 *
 */
function authLogon(&$resultObj, $credentials) {

    global $jwtkey;

    $resultObj["result"]  = 'unauthorised';
    $resultObj["message"] = 'auth failure';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();

    if (!isset($credentials["username"]))
        $credentials["username"] = "";
    if (!isset($credentials["password"]))
        $credentials["password"] = "";


    $usersResultObj = createEmptyResultObject();
    $users = getUsers($usersResultObj);
    mergeResultMessages($resultObj, $usersResultObj);

    $token = null;

    foreach ($users as $user) {
        if ( ($user["username"] === $credentials["username"]) && ($user["password"] === $credentials["password"])) {
            // Create the token!
            require_once('jwt.php');

            /**
             * Uncomment the following line and add an appropriate date to enable the
             * "not before" feature.
             */
            // $nbf = strtotime('2021-01-01 00:00:01');

            /**
             * Uncomment the following line and add an appropriate date and time to enable the
             * "expire" feature.
             */
            //$exp = strtotime('2021-05-05 00:00:01');
            $exp = time()+60*60*24*30;

            // create a token
            $payloadArray = array();
            $payloadArray['username'] = $user['username'];
            $payloadArray['name'] = $user['fullName'];
            if (isset($nbf)) {$payloadArray['nbf'] = $nbf;}
            if (isset($exp)) {$payloadArray['exp'] = $exp;}
            $payloadArray['roles'] = explode(",", $user['roles']);
            $token = JWT::encode($payloadArray, $jwtkey);
            $resultObj["result"]  = 'success';
            $resultObj["message"] = 'auth success';
        }
    }
    // Now, set the cookie!   A failed login will log out anyone already logged in!
    setCookie("NSAuthToken", $token, 0, "/", "", false, true);

    $resultObj["data"] = $token;
}

/**
 * @param $resultObj
 *
 * Using JWT.   Logs the user off and destroys the token.
 *
 */
function authLogoff(&$resultObj) {
    $resultObj["result"]  = 'success';
    $resultObj["message"] = 'de-auth success';
    $resultObj["data"] = null;
    setCookie("NSAuthToken", null, 0, "/", "", false, true);
}

/**
 * @param $resultObj
 *
 * Using JWT.   Checks the token is OK and has not been modified.   Makes sure the principal has access to the requested role.
 *
 */
function authHasRole(&$resultObj, $roles) {

    global $jwtkey;
    $blClearCookie = true;
    $blAuthorised = false;
    $token = (isset($_COOKIE['FlightlineAuthToken']) ? $_COOKIE['FlightlineAuthToken'] : null);
    $resultObj["result"]  = 'unauthorised';
    $resultObj["message"] = 'auth failure';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();

    switch (getType($roles)) {
        case "string":
            $rolesArray = explode(',', $roles);
            break;
        case "array":
            $rolesArray = $roles;
            break;
        default:
            $rolesArray = array();
    }

    if (!is_null($token)) {
        require_once('jwt.php');
        try {
            $payload = JWT::decode($token, $jwtkey, array('HS256'));
            $resultObj['data']['username'] = $payload->username;
            if (isset($payload->exp)) {
                $resultObj['data']['exp'] = $payload->exp;
                $resultObj['data']['expires'] = date(DateTime::ISO8601, $payload->exp);
            }
            if (isset($payload->name)) {
                $resultObj['data']['name'] = $payload->name;
            }
            if (isset($payload->roles)) {
                $resultObj['data']['roles'] = $payload->roles;
                foreach ($payload->roles as $tokenRole) {
                    foreach ($rolesArray as $role) {
                        if ($role === $tokenRole) {
                            $blAuthorised = true;
                        }
                    }
                }
            }
            if (isset($payload->exp) && time() <= $payload->exp) {
                $blClearCookie = false;
            }

        }
        catch(Exception $e) {
            $resultObj["verboseMsgs"][] = 'There was an error decoding the token: ' . $e->getMessage();
        }
    } else {
        $resultObj["verboseMsgs"][] = 'Invalid token: ' . $token;
    }

    // return to caller
    if ($blClearCookie)
        setCookie("NSAuthToken", "", 0, "/", "", false, true);

    if ($blAuthorised) {
        $resultObj["result"] = "success";
        $resultObj["message"] = "user is authorised for role " . $role;
    }
    return $blAuthorised;
}

/**
 * @param $resultObj
 *
 * Using JWT.   Just return the payload.  Not the whole .
 *
 */
function authGetPayload(&$resultObj) {

    global $jwtkey;
    $blClearCookie = true;
    $blAuthorised = false;
    $token = (isset($_COOKIE['FlightlineAuthToken']) ? $_COOKIE['FlightlineAuthToken'] : null);
    $resultObj["result"]  = 'unauthorised';
    $resultObj["message"] = 'auth failure';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();


    if (!is_null($token)) {
        require_once('jwt.php');
        try {
            $payload = JWT::decode($token, $jwtkey, array('HS256'));
            $resultObj['data']['username'] = $payload->username;
            if (isset($payload->exp)) {
                $resultObj['data']['exp'] = $payload->exp;
                $resultObj['data']['expires'] = date(DateTime::ISO8601, $payload->exp);
            }
            if (isset($payload->name)) {
                $resultObj['data']['name'] = $payload->name;
            }
            if (isset($payload->roles)) {
                $resultObj['data']['roles'] = $payload->roles;
            }
            if (isset($payload->exp) && time() <= $payload->exp) {
                $blClearCookie = false;
            }
            $resultObj["result"] = "success";
            $resultObj["message"] = "User has a valid token.";

        }
        catch(Exception $e) {
            $resultObj["message"] = 'There was an error decoding the token: ' . $e->getMessage();
        }
    } else {
        $resultObj["message"] = 'Not logged in.';
    }

    // return to caller
    if ($blClearCookie)
        setCookie("NSAuthToken", "", 0, "/", "", false, true);

    return $resultObj["data"];
}

function getUsers(&$resultObj) {

    $resultObj["result"]  = 'error';
    $resultObj["message"] = 'query error';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();

    $query = "select * from user;";
    $res = doSQL($resultObj, $query);
    if ($res === false)
        goto db_rollback;

    $resultObj["data"] = array();

    while ($user = $res->fetchArray()){
        $thisUser = array(
            "username"          => $user["username"],
            "fullName"        => $user["fullName"],
            "password"        => $user["password"],
            "address"         => $user["address"],
            "roles"           => $user["roles"]
        );
        array_push($resultObj["data"], $thisUser);
    }

    $resultObj["result"]  = 'success';
    $resultObj["message"] = 'query success';
    $res->finalize();
    db_rollback:
    return $resultObj["data"];
}

function getGeneric(&$resultObj, $query, $paramArr = null) {
    global $logger;

    $resultObj["result"]  = 'error';
    $resultObj["message"] = 'query error';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();

    $res = doSQL($resultObj, $query, $paramArr);
    if ($res === false)
        goto db_rollback;

    $resultObj["data"] = array();

    while ($rowdata = $res->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT)) {
        array_push($resultObj["data"], $rowdata);
    }

    $resultObj["result"]  = 'success';
    $resultObj["message"] = null;
    $res = null;
    db_rollback:
    return $resultObj["data"];
}