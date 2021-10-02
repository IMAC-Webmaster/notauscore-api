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

function getDetailsForCompetitions(&$resultObj) {
    global $logger;

    // Now, for the 'competition' array, we should grab the flights.
    $logger->info("There are " . count($resultObj["data"]) . " competitions to populate.");
    foreach ($resultObj["data"] as &$comp) {
        $flightResultsObj = createEmptyResultObject();
        $flightsQry = "select * from ffam_vol where cmpid = :cmpid";
        $flightParamArray = array(
            "cmpid" => $comp["cmpid"]
        );
        $comp["flights"] = getGeneric($flightResultsObj, $flightsQry, $flightParamArray);
        // Convention says to remove the ID column from the JSON data.
        //stripColumnsFromResults($comp["flights"], "cmpid");
        mergeResultMessages($resultObj, $flightResultsObj);
        unset($flightResultsObj);

        // Flat object model (for the most part).
        // Add the 'pilots' who should have flown this comp.
        $pilotResultsObj = createEmptyResultObject();
        $pilotQry = "select * from ffam_pilote where cmpid = :cmpid";
        $pilotParamArray = array(
            "cmpid" => $comp["cmpid"]
        );
        $comp["pilots"] = getGeneric($pilotResultsObj, $pilotQry, $pilotParamArray);
        mergeResultMessages($resultObj, $pilotResultsObj);
        unset ($pilotResultsObj);

        // Add the 'schedules' (programmes) to the array.
        $scheduleResultsObj = createEmptyResultObject();
        //$scheduleQry = "select * from ffam_programme where cmpid = :cmpid";
        $scheduleQry = "select * from ffam_programme where catid = :catid";
        $scheduleParamArray = array(
            "catid" => $comp["catid"]
        );
        $cat = getCategoryById($comp["catid"]);
        $catname = is_null($cat) ? "Undefined Class" : $cat["libelle"];
        $logger->info("Getting schedules for competition: " . $comp["cmplibelle"]);
        $logger->info("\t\tCategory: " . $catname);

        $comp["programmes"] = getGeneric($scheduleResultsObj, $scheduleQry, $scheduleParamArray);
        mergeResultMessages($resultObj, $scheduleResultsObj);
        unset ($scheduleResultsObj);

        foreach ($comp["flights"] as &$flight) {

            // Finally, add the 'notes' (scores) to each flight record.
            $judgeArray = array();  // Assoc. array of judges id<->pos mapping.
            $judgePos = 1;  // This denotes the judges position in the judges panel.   Assigned arbitarially.
            $flight["notes"] = array();

            $noteResultsObj = createEmptyResultObject();
            //$noteQry = "select * from ffam_note where volid = :volid and cmpid = :cmpid and pilid = :pilid";
            $noteQry = "SELECT V.volid, N.pilid, N.jugeid, F.figposition, N.note
                                FROM `ffam_note` N INNER JOIN `ffam_figure` F ON N.figid = F.figid
                                INNER JOIN ffam_vol V ON N.volid = V.volid WHERE V.volid = :volid
                                ORDER BY pilid, jugeid, noteid;";

            $noteParamArray = array(
                "volid" => $flight["volid"],
            );

            getGeneric($noteResultsObj, $noteQry, $noteParamArray);

            foreach($noteResultsObj["data"] as $note) {
                if (!isset($judgeArray[$note["jugeid"]])) {
                    // New judge...    Lets assign a position (does not matter which, so long as it's the same for whole round).
                    $currentJudgePos = $judgePos++;
                    $judgeArray[$note["jugeid"]] = $currentJudgePos;
                    $logger->debug("Assigned pos " . $currentJudgePos . " to judge " . $note["jugeid"]);
                } else {
                    $currentJudgePos =  $judgeArray[$note["jugeid"]];
                    $logger->debug("Retrieved pos " . $currentJudgePos . " for judge " . $note["jugeid"]);
                }
                $note["jugepos"] = $currentJudgePos;
                array_push($flight["notes"], $note);
            }
            mergeResultMessages($resultObj, $noteResultsObj);
            unset ($noteResultsObj);

            // Now add the MPP list for this flight.
            $flight["mpps"] = array();
            $mppResultsObj = createEmptyResultObject();

            $mppQry = "SELECT pilid
                                FROM `ffam_imac_mpp`
                                WHERE volid = :volid AND cmpid = :cmpid
                                ORDER BY pilid;";

            $mppParamArray = array(
                "cmpid" => $flight["cmpid"],
                "volid" => $flight["volid"],
            );

            getGeneric($mppResultsObj, $mppQry, $mppParamArray);
            mergeResultMessages($resultObj, $mppResultsObj);
            foreach($mppResultsObj["data"] as $mpp) {
                array_push($flight["mpps"], $mpp["pilid"]);
            }
            unset($mppResultsObj);
        }
        unset ($flight);

        // Add the 'figures' to each schedule record.
        foreach ($comp["programmes"] as &$prog) {
            $figResultsObj = createEmptyResultObject();
            $figQry = "select * from ffam_figure where prgid = :prgid";
            $figParamArray = array(
                "prgid" => $prog["prgid"]
            );
            $prog["figures"] = getGeneric($figResultsObj, $figQry, $figParamArray);
            //stripColumnsFromResults($prog["figures"] , "prgid");
            mergeResultMessages($resultObj, $figResultsObj);
            unset ($figResultsObj);

        }

    }
    unset($prog);
}

function getCategoryById ($catid) {
    // Get category by id.
    global $logger;
    $catResultsObj = createEmptyResultObject();
    $catQry = "SELECT c.*, t.typecode, t.typelibelle FROM `core_categorie` c inner join `core_type` t WHERE t.typeid = c.typeid AND c.id = :catid";
    $catParamArray = array(
        "catid" => $catid
    );
    $category = getGeneric($catResultsObj, $catQry, $catParamArray);
    $logger->debug("Category: " . print_r($category, true));

    return(empty($category) ? null : $category[0]);

}