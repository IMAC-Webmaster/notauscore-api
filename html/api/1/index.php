<?php
ini_set("display_errors", 0);
require_once '../include/flight/Flight.php';
require_once 'functions.php';
require_once 'api_functions.php';
global $logger, $jsondebug, $resultObj;


// Include the secret file, or create it if it does not exist...
if (!file_exists('secret.php')) {
    $fh = fopen('secret.php', 'w');
    $secret = random_str(32);
    fwrite($fh, "<?php\n\$jwtkey = '$secret';\n");
    fclose($fh);
}
include_once 'secret.php';

Flight::before('start', function(&$params, &$output){
    // Init...
    global $jsondebug, $resultObj, $apiurl, $logger;
    //set_exception_handler("log_exception");
    if (isset($_REQUEST['jsondebug']))  { $jsondebug = $_REQUEST['jsondebug']; }
    $apiurl = "/api/1";
    $resultObj = createEmptyResultObject();
    $resultObj["requestTime"] = time();

    $logger->info("Routing: " . $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"]);

    dbConnect();
});

Flight::after('start', function(&$params, &$output){
    // Init...
    global $logger, $jsondebug, $resultObj;
    $logger->info("Closing connection to db.");
    dbDisconnect();
    unset($db);
});

Flight::map('notFound', function(){
    global $resultObj;
    $resultObj["result"]  = 'error';
    $resultObj["message"] = 'Not Found: The reuqested resource is not found.';
    $resultObj["data"] = array();
    $resultObj["verboseMsgs"] = array();
});

Flight::map('error', function(Exception $ex){
    // Handle error
    log_exception($ex);
});
/**************
 *
 * /auth                        - auth interface.
 * /info                        - Info about the system/api.
 * /competitions                - competitions interface.
 *
 **************/

Flight::route ("GET /info", function() {
    global $resultObj, $logger;
    phpinfo();
    //getNSDetails($resultObj);
});
/*
Flight::route ("DELETE /auth", function() {
    global $resultObj, $logger;

    $logger->info("Routing: DELETE /auth");
    authLogoff($resultObj);
});

Flight::route ("GET /auth/@role", function($role) {
    global $resultObj, $logger;

    $logger->info("Routing: GET /auth/@role" . $role);
    authHasRole($resultObj, $role);
});

Flight::route ("GET /auth", function() {
    global $resultObj, $logger;

    // Just get the current auth object (JS does not have access to the cookies).
    $logger->info("Routing: GET /auth");
    authGetPayload($resultObj);
});

Flight::route ("POST /auth", function() {
    global $resultObj, $logger;

    $logger->info("Routing: POST /auth");
    $authData = @json_decode((($stream = fopen('php://input', 'r')) !== false ? stream_get_contents($stream) : "{}"), true);
    // authData should be an array with keys username and password...

    $logger->info("Authenticating: " . $authData["username"]);
    authLogon($resultObj, $authData);
});
*/

Flight::route ("GET /contesttypes", function() {
    // Contest is the overall event.   Within an event there can be multiple 'competitions'
    global $resultObj, $logger;
    getGeneric($resultObj, "select * from core_typeconcours where 1");
});

Flight::route ("GET /competitiontypes/@typeid", function($typeid) {
    global $resultObj, $logger;
    $paramArray = array(
        "typeid" => $typeid
    );
    getGeneric($resultObj, "select * from core_type where typeid = :typeid", $paramArray);
});

Flight::route ("GET /competitiontypes", function() {
    global $resultObj, $logger;
    getGeneric($resultObj, "select * from core_type where 1");
});

Flight::route ("GET /competitions", function() {
    global $resultObj, $logger;
    $query = "select * from ffam_competition where 1";
    $paramArray = array();
    addFilterParamIfExists($paramArray, $query, "typeid");
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /categories", function() {
    // The categories are categories of competitions.   This is what defines the class in IMAC.
    // So a competition is only one class (freestyle as well).   And it is of a certain type (IMAC, F3A etc)...
    global $resultObj, $logger;
    $query = "SELECT c.*, t.typecode, t.typelibelle FROM `core_categorie` c inner join `core_type` t WHERE t.typeid = c.typeid";

    $paramArray = array();
    addFilterParamIfExists($paramArray, $query, "typeid","t");
    addFilterParamIfExists($paramArray, $query, "typecode");
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /competitions/@cmpid", function($cmpid) {
    // Get competition by id.
    global $resultObj, $logger;
    $paramArray = array(
        "cmpid" => $cmpid
    );
    getGeneric($resultObj, "select * from ffam_competition where cmpid = :cmpid", $paramArray);
});

Flight::route ("GET /competitions/@cmpid/tempflights", function($cmpid) {
    // Get competition by id.
    global $resultObj, $logger;
    $paramArray = array(
        "cmpid" => $cmpid
    );

    $breakOutIDs = getIfSet($_REQUEST["breakoutIDs"], true);

    // Get flights for this competition.
    getGeneric($resultObj, "select * from ffam_competition where cmpid = :cmpid", $paramArray);
    if ($resultObj["result"] === "success") {

        // Now, for the 'competition', we should grab the flights.
        foreach ($resultObj["data"] as &$comp) {
            $flightResultsObj = createEmptyResultObject();
            $flightsQry = "select * from ffam_vol where cmpid = :cmpid";
            $flightParamArray = array(
                "cmpid" => $comp["cmpid"]
            );
            $comp["flights"] = getGeneric($flightResultsObj, $flightsQry, $flightParamArray);
            // Convention says to remove the ID column from the JSON data.
            stripColumnsFromResults($comp["flights"], "cmpid");
            mergeResultMessages($resultObj, $flightResultsObj);
            if ($breakOutIDs) convertResultsToIDArray($comp["flights"], "volid");
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
            if ($breakOutIDs) convertResultsToIDArray($comp["pilots"], "pilid");
            unset ($pilotResultsObj);

            // Add the 'schedules' (programmes) to each flight record.
            foreach ($comp["flights"] as &$flight) {
                if (isset($comp["programmes"][$flight["prgid"]])) {
                    $logger->info("Not adding schedule " . $flight["prgid"] . " again.");
                    continue;
                } else {
                    $logger->info("Adding schedule " . $flight["prgid"] . ".");
                }
                $scheduleResultsObj = createEmptyResultObject();
                $scheduleQry = "select * from ffam_programme where prgid = :prgid";
                $scheduleParamArray = array(
                    "prgid" => $flight["prgid"]
                );
                getGeneric($scheduleResultsObj, $scheduleQry, $scheduleParamArray);
                $comp["programmes"][$flight["prgid"]] = $scheduleResultsObj["data"][0];
                stripColumnsFromResults($comp["programmes"] , "cmpid");
                mergeResultMessages($resultObj, $scheduleResultsObj);
                unset ($scheduleResultsObj);

                // Add the 'figures' to each schedule record.
                foreach ($comp["programmes"] as &$prog) {
                    $figResultsObj = createEmptyResultObject();
                    $figQry = "select * from ffam_figure where prgid = :prgid";
                    $figParamArray = array(
                        "prgid" => $prog["prgid"]
                    );
                    $prog["figures"] = getGeneric($figResultsObj, $figQry, $figParamArray);
                    stripColumnsFromResults($prog["figures"] , "prgid");
                    mergeResultMessages($resultObj, $figResultsObj);
                    if ($breakOutIDs) convertResultsToIDArray($prog["figures"], "figposition");
                    unset ($figResultsObj);
                }

                // Finally, add the 'notes' (scores) to each flight record.
                /*
                 *              This part is all screwed up...    Really need to create a good 'view' of this.   The figure number is tied to the unique key of the figure.
                 *              Not as you would expect it to be (a reference to the flight, schedule, and figPosition!!!
                 *              I can fix it with a join...
                 **/

                // Note: We need to split this up into judges...
                $judgeArray = array();  // Assoc. array of judges id<->pos mapping.
                $judgePos = 1;  // This denotes the judges position in the judges panel.   Assigned arbitarially.
                foreach ($comp["pilots"] as &$pilot) {
                    $noteResultsObj = createEmptyResultObject();
                    //$noteQry = "select * from ffam_note where volid = :volid and cmpid = :cmpid and pilid = :pilid";
                    $noteQry = "SELECT V.volid, N.pilid, N.jugeid, F.figposition, N.note
                                    FROM `ffam_note` N INNER JOIN `ffam_figure` F ON N.figid = F.figid
                                    INNER JOIN ffam_vol V ON N.volid = V.volid WHERE N.`pilid` = :pilid AND V.volid = :volid
                                    ORDER BY pilid, volpos, jugeid, noteid;";
                    $noteParamArray = array(
                        "volid" => $flight["volid"],
                        "pilid" => $pilot["pilid"]
                    );

                    getGeneric($noteResultsObj, $noteQry, $noteParamArray);
                    $currentJudgePos = "";
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

                        if (!isset ($flight["notes"]["pilots"][$pilot["pilid"]]["judges"][$note["jugeid"]]["jugepos"])) // Record it with the scoresheet.
                            $flight["notes"]["pilots"][$pilot["pilid"]]["judges"][$note["jugeid"]]["jugepos"] = $currentJudgePos;

                        $flight["notes"]["pilots"][$pilot["pilid"]]["judges"][$note["jugeid"]]["notes"][$note["figposition"]] = $note;
                    }
                    mergeResultMessages($resultObj, $noteResultsObj);
                    unset ($noteResultsObj);
                }
            }
            unset ($flight);

        }
        unset($prog);
    }

});

Flight::route ("GET /competitions/@cmpid/flights", function($cmpid) {
    // Get competition by id.
    global $resultObj, $logger;
    $paramArray = array(
        "cmpid" => $cmpid
    );

    // Get flights for this competition.
    getGeneric($resultObj, "select * from ffam_competition where cmpid = :cmpid", $paramArray);
    if ($resultObj["result"] === "success") {

        // Now, for the 'competition', we should grab the flights.
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

            // Add the 'schedules' (programmes) to each flight record.
            $comp["programmes"] = array();
            foreach ($comp["flights"] as &$flight) {
                foreach ($comp["programmes"] as $prog) {
                    if (isset($prog["prgid"])) {
                        $logger->info("Not adding schedule " . $flight["prgid"] . " again.");
                        continue;
                    }
                }
                $logger->info("Adding schedule " . $flight["prgid"] . ".");

                $scheduleResultsObj = createEmptyResultObject();
                $scheduleQry = "select * from ffam_programme where prgid = :prgid";
                $scheduleParamArray = array(
                    "prgid" => $flight["prgid"]
                );
                getGeneric($scheduleResultsObj, $scheduleQry, $scheduleParamArray);
                array_push($comp["programmes"], $scheduleResultsObj["data"][0]);
                mergeResultMessages($resultObj, $scheduleResultsObj);
                unset ($scheduleResultsObj);


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

});

Flight::route ("GET /schedules", function() {
    global $resultObj, $logger;
    $query = "select * from ffam_programme where 1";
    $paramArray = array();
    addFilterParamIfExists($paramArray, $query, "figid");
    addFilterParamIfExists($paramArray, $query, "prgid");
    addFilterParamIfExists($paramArray, $query, "figposition");

    getGeneric($resultObj, $query, $paramArray);
    if ($resultObj["result"] === "success") {

        // Now, for each 'programme', we should grab the figures.
        foreach ($resultObj["data"] as &$prog) {
            $figuresResultsObj = createEmptyResultObject();
            $figuresQry = "select * from ffam_figure where prgid = :prgid";
            $prog["figures"] = getGeneric($figuresResultsObj, $figuresQry, array("prgid" => $prog["prgid"]));
            // Convention says to remove the ID column from the JSON data.
            stripColumnsFromResults($prog["figures"], "prgid");
            mergeResultMessages($resultObj, $figuresResultsObj);
        }
        unset($prog);
    }
});

Flight::route ("GET /schedule/@prgid", function($prgid) {
    global $resultObj, $logger;
    $query = "select * from ffam_programme where prgid = :prgid";
    $paramArray = array(
        "prgid" => $prgid
    );
    addFilterParamIfExists($paramArray, $query, "prgid");
    addFilterParamIfExists($paramArray, $query, "figposition");
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /figure", function() {
    global $resultObj, $logger;
    $query = "select * from ffam_figure where 1";
    $paramArray = array();
    addFilterParamIfExists($paramArray, $query, "figid");
    addFilterParamIfExists($paramArray, $query, "prgid");
    addFilterParamIfExists($paramArray, $query, "figposition");
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /figure/@figid", function($figid) {
    global $resultObj, $logger;
    $query = "select * from ffam_figure where figid = :figid";
    $paramArray = array(
        "figid" => $figid
    );
    addFilterParamIfExists($paramArray, $query, "prgid");
    addFilterParamIfExists($paramArray, $query, "figposition");
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /pilots", function() {
    global $resultObj, $logger;
    $query = "select * from ffam_pilote where 1";
    $paramArray = array();
    addFilterParamIfExists($paramArray, $query, "pilid");
    addFilterParamIfExists($paramArray, $query, "pilnom"); // FIlter firstname
    addFilterParamIfExists($paramArray, $query, "pilrenom"); // Filter lastname.
    getGeneric($resultObj, $query, $paramArray);
});

Flight::route ("GET /pilots/@pilid", function($pilid) {
    // Get competition by id.
    global $resultObj, $logger;

    $paramArray = array(
        "pilid" => $pilid
    );
    getGeneric($resultObj, "select * from ffam_pilote where pilid = :pilid", $paramArray);
    $logger->debug(ini_get("display_errors"));
});


#############################################
# Now start!
#################

Flight::start();

// Convert PHP array to JSON array
if ($jsondebug === false || $jsondebug === "false") {
    $json_data = json_encode($resultObj, null);
} else {
    $json_data = json_encode($resultObj, JSON_PRETTY_PRINT);
}
header('Content-Type: application/json');
print $json_data;
