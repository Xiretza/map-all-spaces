<?php
/*
** Script to check if the spaceapi json file is valid. Check for the most common
** errors and send email to listed emailaddress.
** 
** Checks on : CORS, HTTP(S), valid json, valid SSL, last api version, long/lat geo location, 
** if not updated longer then 6 months, 
** 
** Options to improve : 
** - use 'curl https://api.spaceapi.community/cache'
** - link to https://validator.spaceapi.io/ui/?url=http://api.service.z-labor.space/spaceapi.json
** 
** Created by : Dave Borghuis
*/

require 'init.php';

validateSpaceApi();

message('End '.date("h:i:sa"),5);

function validateSpaceApi() {
    echo PHP_EOL . "## Validate Space api json file ". date('Y-m-d H:i').PHP_EOL;

    $dateToOld = strtotime("-3 months");
    echo 'Date to old : ' . date('Y-m-d H:i', $dateToOld).PHP_EOL;

    $adminMessages = '';
    $apiStatus= [];

    //Live
    $getApiDirResult = getJSON('https://raw.githubusercontent.com/SpaceApi/directory/master/directory.json');
    $hs_array = $getApiDirResult['json'];

    //Local test
    // $getApiDirResult['error'] =0;
    // $hs_array = json_decode(file_get_contents('spaceapilocal.json'),true) ;

    if ($getApiDirResult['error'] != 0) {
        echo 'Space api dir not found, curl error  ', $getApiDirResult['error'];
    } else {

        //loop all hackerspaces
        foreach ($hs_array as $space => $url) {

            // echo "-------------------------" . PHP_EOL;
            // echo 'Space ' . $space.' url: '.$url.PHP_EOL;

            $emailMessage = '';
            $email = '';

            if (parse_url($url, PHP_URL_SCHEME) == 'http') {
                $httpsurl = preg_replace("/^http:/i", "https:", $url);
                $getApiResult = getJSON($httpsurl);

                if (isset($getApiHTTPResult['json']) and $getApiResult['error'] == 0) {
                    $emailMessage .= "- Spaceapi via https works, update this in spaceapi directory." . PHP_EOL;
                    $getApiResult = $getApiResult;
                } else {
                    $emailMessage .= "- Spaceapi via https failed, consider enable https." . PHP_EOL;
                    //fallback to normal json
                    $getApiResult = getJSON($url, null, 20);
                };

            } else {
                $getApiResult = getJSON($url, null, 20);
            };
            // Error 0-99 Curl
            // Error 100-999 http
            // Error 1000 no valid json
            // Error 1001 dupe
            // ssl >2000

            // Explain the error classes
            if  ($getApiResult['error'] >= 2000) {
                $emailMessage .= '- SSL error ' . $getApiResult['error'] - 2000 . PHP_EOL;
            } elseif ($getApiResult['error'] > 1 and $getApiResult['error'] < 100) {
                $emailMessage .= '- Curl error ' . $getApiResult['error'] . PHP_EOL;
            } elseif ($getApiResult['error'] >= 100 and $getApiResult['error'] <= 999) {
                $emailMessage .= '- HTTP error ' . $getApiResult['error'] . PHP_EOL;
            } elseif ($getApiResult['error'] >= 1000 and $getApiResult['error'] < 2000) {
                $emailMessage .= '- JSON decode error ' . PHP_EOL;
            };
            
            if (isset($getApiResult['json']) && $getApiResult['error'] == 0) {

                if($getApiResult['cors'] == false) {
                    $emailMessage .= "- CORS not enabled" . PHP_EOL;
                };

                $apiJson = $getApiResult['json'];

                if (isset($apiJson['api']) ) {
                    $api = $apiJson['api'];
                } elseif ($apiJson['api_compatibility']) {
                    $api = $apiJson['api_compatibility'][0];
                } else {
                    $emailMessage .= '- no api version found'.PHP_EOL;
                };

                if ($api < 0.13) {
                    $emailMessage .= '- Please upgrade spaceapi to latest version.' . PHP_EOL;
                };

                if (isset($apiJson['location']['lon']) && isset($apiJson['location']['lat'])) {
                    $lon = $apiJson['location']['lon'];
                    $lat = $apiJson['location']['lat'];
                } elseif (isset($apiJson['lon']) && isset($apiJson['lat'])) {
                    //<v12 api
                    $lon = $apiJson['lon'];
                    $lat = $apiJson['lat'];
                };

                if (
                    $lon < -180 or $lon > 180 or $lat < -90 or $lat > 90
                ) {
                    $emailMessage .= '- Wrong lat\lon is : [ lat ' . number_format($lat,4) . '/ lon ' . number_format($lon,4).PHP_EOL;
                }

                $lastchange = $apiJson['state']['lastchange'] ?? null; //date in epoch

                if (isset($lastchange)) {
                    if ($lastchange - $dateToOld < 0) {
                        $emailMessage .= "- Date lastchange longer then 6 months ago. (". date('Y-m-d H:i', $lastchange) .")". PHP_EOL;
                    };
                };

                $email = $apiJson['contact']['email'] ?? '';

                //echo 'email : ' . $email. PHP_EOL;

                if (isset($apiJson['issue_report_channels'][0])){
                    switch ($apiJson['issue_report_channels'][0]) {
                        case 'issue_mail':
                            $email = $apiJson['contact']['issue_mail'];
                            //if not a valid email assume its base64 encoded
                            if (filter_var($email, FILTER_VALIDATE_EMAIL) == false) {
                                $email = base64_decode($email);
                            };
                            break;
                        case 'ml':
                            $email = $apiJson['contact']['ml']; 
                            break;
                        case 'email':
                            $email = $apiJson['contact']['email'];
                            break;
                        case 'twitter':
                            echo "Issue via Twitter! $space".PHP_EOL;
                            $email = $apiJson['contact']['twitter'];
                            break;                            
                        default: //email
                            //$email = $apiJson['contact']['email']; 
                            echo 'Case not found???'.PHP_EOL;
                            break;
                    };
                } elseif(isset($apiJson['contact']['issue_mail'])) {
                    $email = $apiJson['contact']['issue_mail'];
                };
                //echo 'issue email :' . $email . PHP_EOL;
            };
            // else {
            //     $emailMessage .= '- No valid spaceapi json file found.';
            // };

            if ($emailMessage) {
                //echo "Send email to : " . $email . PHP_EOL;
                //echo "Message :" . PHP_EOL . $emailMessage . PHP_EOL;

                echo "-------------------------" . PHP_EOL;
                echo 'Space ' . $space . ' url: ' . $url . PHP_EOL;

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    //$email = 'spaceapi@mapall.space';
                    $headers = 'From: spaceapi@mapall.space' . "\r\n" .
                        'Reply-To: spaceapi@mapall.space' . "\r\n";
                    $emailMessage =
                        "Dear Maker/Hacker,\r\n\r\nWe (volunteers of spaceapi.io) found some issues with your spaceapi url/json on $url. " .
                    "\r\n\r\nWe found the following issues : " . PHP_EOL .
                        $emailMessage . PHP_EOL .
                    "Please fix this issues so that other sites can enjoy your live data. To check your spaceapi manual you can use the online validator ( https://spaceapi.io/validator/ ).\r\n\r\nRegards,\r\n\r\nDave";
                    if (mail($email, "Your $space spaceapi", $emailMessage,$headers)) {
                        $apiStatus['send']++;
                    } else {
                        $apiStatus['fail']++;
                        echo "Sending mail to $space failed!".PHP_EOL;
                        echo "Found errors : $emailMessage" . PHP_EOL;
                    }
                } else {
                    $adminMessages .= $space. " Email: " . $email . PHP_EOL . $emailMessage . PHP_EOL . '******' . PHP_EOL;
                    echo "ERROR Sendmail : Email $email not valid for $space".PHP_EOL;
                    echo "Found errors : $emailMessage".PHP_EOL;
                    $apiStatus['fail']++;
                };
            } else {
                $apiStatus['ok']++;
            };
        };
        echo '****************' . PHP_EOL . $adminMessages;
    };
    echo "Checked ".$apiStatus['ok']." Failed ". $apiStatus['fail']." Send : ". $apiStatus['send'].PHP_EOL;
};