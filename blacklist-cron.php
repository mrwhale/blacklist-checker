<?php
/*
Blacklist.php
This is a php script (and eventually an api) to check mail servers, any server really against a whole bunch of dnsbl's to see if they are blacklisted
Currently there are no inputs required, all settings should be modified in the first few lines of this script.
I plan to run this with cron and it will post to slack when one is spotted on a Blacklist

You will need slack, and an API key generated for it

*/

/*************************
SETTINGS - fill these out as required
There will be more to come
*/
$debug = true;
//Slack incoming webhook for the channel you want
$slackhook = "";
//API token for slack
$slacktoken = "";
//change these to absolute paths for use with cron
$dnsbl_file = "/home/john/blacklist/dnsbls.txt";
$blservers_file = "/home/john/blacklist/blservers.txt";
$servers_file = "/home/john/blacklist/servers.txt";
//*************************
//Start
$blservers = read_in_blservers($blservers_file);

$servers = read_in_servers($servers_file);

$dnsbl_array = read_in_dnsbl_servers($dnsbl_file);


//For each server listed in servers, lets check if they are on a blacklist
foreach($servers as $server){
  //initialise array to put any reports from the dnsbls into it for collation
  $allreports = array();
  //initialise array to put any dnsbl's the server was listed on into
  $listeddnsbl = array();
  //Pre-check to see if server is in the already blacklisted array
  //also get the key for it if it is
  $in = in_array($server,$blservers);
  if($in){
      $key = array_search($server,$blservers);
  }
  logme("Checking $server");
  logme("is the server in bl array? $in");
  //get ip of server if in domain format, use this function to normalise any input
  $ip = normalise($server);
  //get reverse ip, required for check against dnsbl
  $rip = rip($ip);
  $count = 0;
  //Check each dnsbl
  foreach($dnsbl_array as $dnsbl){
    if(is_blacklisted($rip, $dnsbl)){
      //lets get extra info if its listed (ie the txt record)
      array_push($listeddnsbl,$dnsbl);
      $details = extra_info($rip, $dnsbl);
      //add to a big array for later usage when we post to slack, including all
      //things from all blacklists
      foreach($details as $detail){
        array_push($allreports,$detail);
      }
      $count++;
    }else{
      //echo all good
    }
  }

//Check to see if it was listed, if not then we will remove from blserver list
// if it was in it.
// If listed, lets add to blservers and create a report, post to slack
  if($count == 0){
    logme("server not found on any blacklists. Yay!");

    if($in){
      //remove from array
      unset($blservers[$key]);
      logme("Removed from array");
      $slacktext2 = create_report_good($server,$slacktoken);
      toslack($slacktext2,$slackhook);
    }
  }else{
    //lets see how many reports were given about it
    //was on a blacklist. Check to see if in array. add if not. skip if yes
    if($in){
      //exists already in list, do nothing
      logme("Not adding to blarray, already there");
    }else{
      //add to array
      array_push($blservers, $server);
      logme("Added to blarray");
      //Create text to send
      logme("Creating report");
      $slacktext = create_report($ip,$server,$allreports,$listeddnsbl,$slacktoken);

      //Post to slack here
      logme("posting to slack");

      toslack($slacktext,$slackhook);

    }
  }
  echo "\n";
}

cleanup($blservers,$blservers_file);
//read in servers in blacklisted file
//todo: add in some error checking when opening file
// @return Array
function read_in_blservers($blservers_file){
  logme("Reading in blacklisted servers");
  $blservers = file($blservers_file, FILE_IGNORE_NEW_LINES);
  return $blservers;
}

//read in servers in servers file that you want to check
//todo: add in some error checking when opening file
// @return Array
function read_in_servers($servers_file){
  logme("Reading in servers to check");
  $servers = file($servers_file, FILE_IGNORE_NEW_LINES);
  return $servers;
}

//read in dnsbl servers to use in checks
//todo: add in some error checking when opening file
// @return Array
function read_in_dnsbl_servers($dnsbl_file){
  logme("Reading in dnsbl servers to check against \n");
  $dnsbl_array = file($dnsbl_file, FILE_IGNORE_NEW_LINES);
  return $dnsbl_array;
}

//Function to print any debug to screen + log to file
function logme($string){
  global $debug;
  if($debug){
    //print to screen
    echo $string . "\n";
  }
  //log to log file
}
/*
*check if domain or IP is provided
*Converts domain to IP if that is what is provided
* @param String @server
* @return String
*/
function normalise($server){
  if(is_domain($server)){
    return $server;
  }else{
    $temp = get_ip($server);
    logme("$server -> $temp");

    return get_ip($server);
  }
}

/*
checks if given string is a domain, or an IP
@param String @server
@return booleen
todo: Need to add extra check i.e. if domain is not a valid domain or if IP i not valid ip2long can do this i just need to add some checks
*/
function is_domain($server){
  return ip2long($server);
}

/*
Function to get IP of domain
 @param String $domainname
 @return String
 todo: probably should have some error checking here make sure that it works
 */
function get_ip($domainname){
  return gethostbyname($domainname);
}

/*
Function to reverse the ip around
@param String $ip
@return String
*/
function rip($ip){
  $quads=explode( ".", $ip );
  $rip=$quads[3].".".$quads[2].".".$quads[1].".".$quads[0];
  return $rip;
}
/*
function to then check each DNSBL, pass in IP and dnsbl array
@param String $rip
@param String $dnsbl
@return booleen
*/
function is_blacklisted($rip, $dnsbl) {
	if( checkdnsrr( $rip.".".$dnsbl, "A" ) ) {
		return( true ); // return on first match
	}
	return( false );
}


/*
Returns array of txt records for the offending IP address from the rbl it was found on
@param String $rip
@param String $dnsbl
@return Array
*/
function extra_info($rip, $dnsbl){
  $details = array();

  logme("checking against $dnsbl");

  $host = $rip.".".$dnsbl;
	$txtrecords = dns_get_record($host, DNS_TXT);
  logme("Details:");
  foreach($txtrecords as $txtrecord){
      $details[] = $txtrecord['txt'];
  }
	return $details;
}

/*
Function will create a report from all the txt records gathered if the ip was
found on any/all blacklists. This will also encode any http info so its displayed
properly when posted to slack
It will also create the entire body that slack needs when posting

todo: also pass in api token + emoji + maybe color
@param String $ip
@param String $server
@param $allreports

@return Array
*/
function create_report($ip,$server,$allreports,$listeddnsbl,$slacktoken){
  $blcount = count($allreports);
  $text = "$server has $blcount reports about it";
  //create a string with allreports in it and then $urlencode it
  $stringreports = implode("\n", $allreports);
  $stringlisteddnsbl = implode(",", $listeddnsbl);

  $urlencodetxt = rawurlencode($stringreports);
  $postitems =  array(
                  'token' => $slacktoken,
                  'username' => "BlackList Checker",
                  'icon_emoji' => ":trollface:",
                  'text' => "Mail server $server has $blcount reports about it",
                  'attachments' => array(array(
                        "fallback" => "Mail server $server has appeared on a blacklist",
                        "title" => "You were listed on $stringlisteddnsbl",
                        "text" => $urlencodetxt,
                        "color" => "danger"))
                      );

  $jsonencode = json_encode($postitems);
  $payload = "payload=".$jsonencode;
  return $payload;
}

function create_report_good($server,$slacktoken){
  $postitems =  array(
                  'token' => $slacktoken,
                  'username' => "BlackList Checker",
                  'icon_emoji' => ":simple_smile:",
                  'text' => "Mail server $server is clean",
                  'attachments' => array(array(
                        "fallback" => "Mail server $server is cleaned up!",
                        "title" => "No more blacklists for you",
                        "text" => "Yay",
                        "color" => "good"))
                      );

  $jsonencode = json_encode($postitems);
  $payload = "payload=".$jsonencode;
  return $payload;
}


//function to print to slack
/*
@param String $slacktext
*/
function toslack($slacktext,$slackhook){
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, $slackhook);
  curl_setopt($curl, CURLOPT_POSTFIELDS,$slacktext);
  //Execute curl and store in variable
  $data = curl_exec($curl);
  //todo: check the return and print if there is an error
  //echo $data;
}

/*
Cleanup
@param Array $blservers
*/

function cleanup($blservers,$blservers_file){
  //lets write array back to file. Overwrite it
  logme("Cleanup: Writing blservers to file");
  $fh = fopen($blservers_file,"w");
  foreach($blservers as $blserver){
    $blserver .= "\n";
    fwrite($fh,$blserver);
  }
  fclose($fh);

}

?>
