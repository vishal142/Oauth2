<?php
class appAndroidGCMPushAPI {
    // Database Object
	private $mysqli;

	// Array of GCM Connection Settings
	private $gcmData;
	// Push Mode: Sandbox or Production
	private $pushMode;
	// Whether to trigger errors
	private $showPushErrors;
	// Whether GCM should log errors
	private $logPushErrors;
	// Log path for GCM errors
	private $logPath;

	// Max files size of log before it is truncated. 1048576 = 1MB.  Added incase you do not add to a log
	// rotator so this script will not accidently make gigs of error logs if there are issues with install
	private $logMaxSize; // max log size before it is truncated

	// GCM Gateway
	private $pushURL;
	// Server API Key
	private $serverApiKey;

	// Message to push to user
	private $push_message;

    // Constructor - open DB connection
    function __construct($args=NULL) {
		$db_data = parse_ini_file('/var/www/html/apis/resource/db.ini', true);
		$mode = $db_data['Use_Mode']['Mode'];
		
		$DB_Server = $db_data[$mode]['DB_Server'];
		$DB_Username = $db_data[$mode]['DB_Username'];
		$DB_Password = $db_data[$mode]['DB_Password'];
		$DB_DBName = $db_data[$mode]['DB_DBName'];

		
                                
        $this->mysqli = new mysqli($DB_Server, $DB_Username, $DB_Password, $DB_DBName);
		
		/*if ($this->mysqli->connect_errno) {
            echo "Failed to connect to MySQL: (" . $this->mysqli->connect_errno . ") " . $this->mysqli->connect_error;
        }else{
            echo $this->mysqli->host_info . "\n";
        }
        die();*/
		$this->mysqli->set_charset("utf8");
                $this->mysqli->autocommit(FALSE);
		$this->myCounter = 1;


		// Android Push Initialization
		// -------------------------
		$gcm_push_data = parse_ini_file('/var/www/html/apis/resource/config.ini', true);
		//$phyicalwebroot = realpath($_SERVER['DOCUMENT_ROOT'].$gcm_push_data['Website']['VirtualFolder']);
		// $phyicalwebroot = realpath($_SERVER['DOCUMENT_ROOT'].$gcm_push_data['Website']['VirtualFolder']);
		$phyicalwebroot = realpath($gcm_push_data['Website']['WebRoot'].$gcm_push_data['Website']['VirtualFolder']);
		
		$this->pushMode = $gcm_push_data['PUSH_MODE']['Mode'];

		$this->showPushErrors = ($gcm_push_data['GCM']['showPushErrors']=='1'?true:false);
		$this->logPushErrors = ($gcm_push_data['GCM']['logPushErrors']=='1'?true:false);
		$this->logPath = $phyicalwebroot.$gcm_push_data['GCM']['logPath']."/".$gcm_push_data['GCM']['logFile'];
		$this->logMaxSize = $gcm_push_data['GCM']['logMaxSize'];
		$this->pushURL = $gcm_push_data['GCM']['pushURL'];
		$this->serverApiKey = $gcm_push_data['GCM']['serverApiKey'];
		$this->push_message = '';
		// -------------------------
		
		$this->checkSetup();
		$this->gcmData = array(
			'mode'=>$this->pushMode,
			'P'=>array(
				'serverApiKey'=>$this->serverApiKey,
				'ssl'=>$this->pushURL
			),
			'S'=>array(
				'serverApiKey'=>$this->serverApiKey,
				'ssl'=>$this->pushURL
			)
		);
          
                
		// If Command Line Arguments is Used
		// ---------------------------------
		if(!empty($args)){
			switch($args['task']){
				case "register":
					$this->_registerDevice(
						$args['user_id'],
						$args['appname'],
						$args['appversion'],
						$args['device_uid'],
						$args['device_reg_id'],
						$args['device_name'],
						$args['device_model'],
						$args['device_version'],
						$args['device_os'],
						/*
						$args['pushbadge'],
						$args['pushalert'],
						$args['pushsound'],
						*/
						$args['push_mode']
					);
					break;

				case "fetch";
					$this->_fetchMessages();
					break;

				default:
					echo "No GCM Task Provided...\n";
					break;
			}
		}
		// ---------------------------------
    }
 
    // Destructor - close DB connection
    function __destruct() {
        //$this->mysqli->close();
    }

	// Method: checkSetup() - Check if the certificates are available and also provide a notice if they are not as secure as they could be [Private Method]
	private function checkSetup(){
		clearstatcache();
	}

	// Method: _triggerError() [Private Method]
	// Use PHP error handling to trigger User Errors or Notices.  If logging is enabled, errors will be written to the log as well.
	// Disable on screen errors by setting showPushErrors to false;
	private function _triggerError($error, $type=E_USER_NOTICE){

		file_put_contents('/var/www/html/apis/log/log.txt', $error.'/n', FILE_APPEND | LOCK_EX);

		/*$backtrace = debug_backtrace();
		$backtrace = array_reverse($backtrace);
		$error .= "\n";
		$i=1;
		foreach($backtrace as $errorcode){
                        if(isset($errorcode['file'])){
                            $file = ($errorcode['file']!='') ? "-> File: ".basename($errorcode['file'])." (line ".$errorcode['line'].")":"";
                            $error .= "\n\t".$i.") ".$errorcode['class']."::".$errorcode['function']." {$file}";
                            $i++;
                        }
		}
		$error .= "\n\n";
		if($this->logPushErrors && file_exists($this->logPath)){
			if(filesize($this->logPath) > $this->logMaxSize) $fh = fopen($this->logPath, 'w');
			else $fh = fopen($this->logPath, 'a');
			fwrite($fh, $error);
			fclose($fh);
		}
		if($this->showPushErrors) trigger_error($error, $type);

		*/
	}
	
	// Method: _registerDevice() - Register Android device [Private Method]
	// @param Bigint $user_id User Id
	// @param string $appname Application Name
	// @param string $appversion Application Version
	// @param string $device_uid 40 charater unique user id of Android device
	// @param string $device_reg_id 255 character unique device token tied to device id
	// @param string $device_name User selected device name
	// @param string $device_model Model of device
	// @param string $device_version Current version of device
	// @param string $device_os OS Version of Device
	// @param string $pushbadge Whether Badge Pushing is Enabled or Disabled [0|1]
	// @param string $pushalert Whether Alert Pushing is Enabled or Disabled [0|1]
	// @param string $pushsound Whether Sound Pushing is Enabled or Disabled [0|1]
	// @param string $push_mode Whether Production or Sandbox [P|S]
	private function _registerDevice($user_id, $appname, $appversion, $device_uid, $device_reg_id, $device_name, $device_model, $device_version, $device_os, 
									/* $pushbadge, $pushalert, $pushsound,*/ $push_mode) {

		if(strlen($appname)==0) $this->_triggerError('ERROR: Application Name must not be blank.', E_USER_ERROR);
		else if(strlen($appversion)==0) $this->_triggerError('ERROR: Application Version must not be blank.', E_USER_ERROR);
		//else if(strlen($device_uid)!=40) $this->_triggerError('ERROR: Device ID must be 40 characters in length.', E_USER_ERROR);
		else if(strlen($device_reg_id)<140) $this->_triggerError('ERROR: Device Registration Id must be 140 characters in length.', E_USER_ERROR);
		else if(strlen($device_name)==0) $this->_triggerError('ERROR: Device Name must not be blank.', E_USER_ERROR);
		else if(strlen($device_model)==0) $this->_triggerError('ERROR: Device Model must not be blank.', E_USER_ERROR);
		else if(strlen($device_version)==0) $this->_triggerError('ERROR: Device Version must not be blank.', E_USER_ERROR);
		else if(strlen($device_os)==0) $this->_triggerError('ERROR: Device OS must not be blank.', E_USER_ERROR);
		// else if($pushbadge!='0' && $pushbadge!='1') $this->_triggerError('ERROR: Push Badge must be either Enabled or Disabled.', E_USER_ERROR);
		// else if($pushalert!='0' && $pushalert!='1') $this->_triggerError('ERROR: Push Alert must be either Enabled or Disabled.', E_USER_ERROR);
		// else if($pushsound!='0' && $pushsound!='1') $this->_triggerError('ERROR: Push Sound must be either Enabled or Disabled.', E_USER_ERROR);
		else if($push_mode!='S' && $push_mode!='P') $this->_triggerError('ERROR: Push Mode must be either Production or Sandbox.', E_USER_ERROR);

		/*
		$user_id = $this->mysqli->prepare($user_id);
		$appname = $this->mysqli->prepare($appname);
		$appversion = $this->mysqli->prepare($appversion);
		$device_uid = $this->mysqli->prepare($device_uid);
		$device_reg_id = $this->mysqli->prepare($device_reg_id);
		$device_name = $this->mysqli->prepare($device_name);
		$device_model = $this->mysqli->prepare($device_model);
		$device_version = $this->mysqli->prepare($device_version);
		$device_os = $this->mysqli->prepare($device_os);
		$pushbadge = $this->mysqli->prepare($pushbadge);
		$pushalert = $this->mysqli->prepare($pushalert);
		$pushsound = $this->mysqli->prepare($pushsound);
		$push_mode = $this->mysqli->prepare($push_mode);
		*/

		// store device for push notifications
		//  '{$pushbadge}', '{$pushalert}', '{$pushsound}',
		//		`pushbadge`='{$pushbadge}',
		//		`pushalert`='{$pushalert}',
		//		`pushsound`='{$pushsound}',
		$this->mysqli->query("SET NAMES 'utf8';"); // force utf8 encoding if not your default
		$sql = "INSERT INTO `tbl_member_push_devices` (`fk_member_id`, `appname`, `appversion`, `device_uid`, `device_token`, `device_name`, `device_model`, `device_version`, `device_os`, `badge_count`, `push_mode`, `status`, `created`, `modified`)
				VALUES (
					{$user_id}, '{$appname}', '{$appversion}', '{$device_uid}', '{$device_reg_id}', '".$this->mysqli->real_escape_string($device_name)."', '{$device_model}',	'{$device_version}', 
					'{$device_os}', '0', '{$push_mode}', 'active', NOW(), NOW()
				)
				ON DUPLICATE KEY UPDATE
				`device_token`='{$device_reg_id}',
				`device_name`='".$this->mysqli->real_escape_string($device_name)."',
				`device_model`='{$device_model}',
				`device_version`='{$device_version}',
				`device_os`='{$device_os}',
				`push_mode`='{$push_mode}',
				`status`='active',
				`modified`=NOW();";
		$this->mysqli->query($sql);
		$this->mysqli->commit();
		
		// Uninstalling Other User/Device Using the Same Device From Receiving Push Messages
		$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_uid`='{$device_uid}' AND `fk_member_id`<>{$user_id};";
		$this->mysqli->query($sql);
		$this->mysqli->commit();
	}

	// Method: _unregisterDevice() - Unregister Android device [Private Method]
	// This gets called automatically when Android's Feedback Service responds with an invalid token.
	// @param string $device_reg_id 255 character unique device registration id tied to device id
	private function _unregisterDevice($user_id=NULL, $appname, $device_uid, $device_reg_id){
		if ($user != NULL) {
			$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_token`='{$device_reg_id}' AND `fk_member_id`={$user_id} AND 
					`appname`='{$appname}' AND `device_uid`='{$device_uid}';";
			$this->mysqli->query($sql);
			$this->mysqli->commit();
		}
		 else {
			$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_token`='{$device_reg_id}' AND `appname`='{$appname}' AND 
					`device_uid`='{$device_uid}';";
			$this->mysqli->query($sql);
			$this->mysqli->commit();
		}
	}
	
	// Method: _fetchMessages() - Fetch Messages [Private Method]
	// This gets called by a cron job that runs as often as you want.  You might want to set it for every minute.
	// @param string $device_reg_id 255 character unique device registration id tied to device id
	private function _fetchMessages(){
		// only send one message per user... oldest message first
		$sql = "SELECT
				`tbl_member_push_messages`.`id`, `tbl_member_push_messages`.`message`, `tbl_member_push_devices`.`device_token`, `tbl_member_push_devices`.`push_mode`, 
				`tbl_member_push_devices`.`fk_member_id`, `tbl_member_push_devices`.`device_uid`, `tbl_member_push_devices`.`appname`
				FROM `tbl_member_push_messages` 
				LEFT JOIN `tbl_member_push_devices` ON
				`tbl_member_push_devices`.`fk_member_id` = `tbl_member_push_messages`.`fk_receiver_member_id` AND `tbl_member_push_devices`.`device_uid` = `tbl_member_push_messages`.`device_uid`
				WHERE `tbl_member_push_messages`.`status`='Q'
				AND `tbl_member_push_devices`.`device_os`='iOS'
				AND `tbl_member_push_devices`.`status`='active'
				AND `tbl_member_push_messages`.`fk_receiver_member_id`={$user_id} 
				GROUP BY `tbl_member_push_messages`.`fk_receiver_member_id`
				ORDER BY `tbl_member_push_messages`.`created` ASC
				LIMIT 100;";

		if($result = $this->mysqli->query($sql)) 
		{
			if($result->num_rows) 
			{
				while($row = $result->fetch_array(MYSQLI_ASSOC)) 
				{
					/*
					$push_message_id = $this->mysqli->prepare($row['push_message_id']);
					$message = stripslashes($this->mysqli->prepare($row['message']));
					$device_reg_id = $this->mysqli->prepare($row['device_token']);
					$push_mode = $this->mysqli->prepare($row['push_mode']);
					$user_id = $this->mysqli->prepare($row['user_id']);
					$device_uid = $this->mysqli->prepare($row['device_uid']);
					$appname = $this->mysqli->prepare($row['appname']);
					*/
					
					$push_message_id = $row['id'];
					$message = stripslashes($row['message']);
					$device_reg_id = $row['device_token'];
					$push_mode = $row['push_mode'];
					$user_id = $row['fk_member_id'];
					$device_uid = $row['device_uid'];
					$appname = $row['appname'];
					$this->_pushMessage($push_message_id, $message, $device_reg_id, $push_mode, $user_id, $device_uid, $appname);
				}
			}
		}
	}

	// Method: _pushMessage() - Push GCM Messages [Private Method]
	// This gets called automatically by _fetchMessages.  This is what actually deliveres the message.
	// @param int $push_message_id
	// @param string $message JSON encoded string
	// @param string $device_reg_id 255 character unique device registration id tied to device id
	// @param string $push_mode Which SSL to connect to, Sandbox or Production
	private function _pushMessage($push_message_id, $message, $device_reg_id, $push_mode, $user_id, $device_uid, $appname){
         
		if(strlen($push_message_id)==0) $this->_triggerError('ERROR: Missing message push_message_id.', E_USER_ERROR);
		if(strlen($message)==0) $this->_triggerError('ERROR: Missing message.', E_USER_ERROR);
		if(strlen($device_reg_id)==0) $this->_triggerError('ERROR: Missing device registration id.', E_USER_ERROR);
		if(strlen($push_mode)==0) $this->_triggerError('ERROR: Missing push mode status.', E_USER_ERROR);
		if(strlen($user_id)==0) $this->_triggerError('ERROR: Missing user id.', E_USER_ERROR);
		if(strlen($device_uid)==0) $this->_triggerError('ERROR: Missing device uid.', E_USER_ERROR);
		if(strlen($appname)==0) $this->_triggerError('ERROR: Missing appname.', E_USER_ERROR);
		
		// $message = '{"data":{"message":"test push message"},"registration_ids":["'.$deviceRegId.'"],"time_to_live":0,"collapse_key":"demo"}';
		
		// Decoding existing Message
		$arrDecodeJsonMessage = json_decode($message, true);
                if(isset($arrDecodeJsonMessage['data']['badge'])){
		$arrDecodeJsonMessage['data']['badge'] = '"'.$arrDecodeJsonMessage['data']['badge'].'"';
                }else{
                   $arrDecodeJsonMessage['data']['badge'] ='' ;
                }

		// Add Extra Parameters to the Push Message
		$arr_registration_ids = array("registration_ids" => array($device_reg_id));
		$arr_time_to_live = array("time_to_live" => 0);
		$arr_collapse_key = array("collapse_key" => 'demoapps');
		
		// Formatting New Message
		$newMessageArr = array_merge($arrDecodeJsonMessage, $arr_registration_ids, $arr_time_to_live, $arr_collapse_key);
		$modifiedJsonMessage = json_encode($newMessageArr);
		
		try {
			$headers = array(
						'Content-Type:application/json charset=utf-8',
						'Authorization:key='.$this->gcmData[$push_mode]['serverApiKey']
					);
			$ch = curl_init();
			
			curl_setopt_array($ch, array(
				CURLOPT_URL => $this->gcmData[$push_mode]['ssl'],
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS => $modifiedJsonMessage
			));
			
			$push_response = curl_exec($ch);
			
			curl_close($ch);
			$this->_verifyPushResponse($push_response, $user_id, $push_message_id, $device_uid, $device_reg_id, $appname);
		}
		catch (Exception $e) {
			
			$this->_pushFailed($push_message_id);
			$this->_triggerError("ERROR: Push Failed : ".$e->getMessage(), E_USER_ERROR);
		}
	}
	
	// Method: _verifyPushResponse() - Fetch GCM Push Response [Private Method]
	// This gets called automatically by _pushMessage.  This will check with GCM for any invalid tokens and disable them from receiving further notifications.
	private function _verifyPushResponse($push_response, $user_id, $push_message_id, $device_uid, $device_reg_id, $appname) {
		$arr_response = json_decode($push_response, true);
		
		if (($arr_response['success'] == 0) || ($arr_response['failure'] == 1)) {
			
			$this->_pushFailed($push_message_id);
                        if(isset($arr_response['results']['error'])){
			$this->_triggerError("ERROR: Push Failed - {$arr_response['results']['error']}.", E_USER_ERROR);
                        }
		} else if (($arr_response['success'] == 1) || ($arr_response['failure'] == 0)) {
			$this->_pushSuccess($push_message_id);
		}
	}

	// Method: _checkFeedback() - Fetch GCM Messages [Private Method]
	// This gets called automatically by _pushMessage.  This will check with GCM for any invalid tokens and disable them from receiving further notifications.
	// @param string $push_mode Which SSL to connect to, Sandbox or Production
	private function _checkFeedback($push_mode, $user_id=NULL, $device_uid, $appname){
		$ctx = stream_context_create();
		// stream_context_set_option($ctx, 'ssl', 'local_cert', $this->gcmData[$push_mode]['certificate']);
		// stream_context_set_option($ctx, 'ssl', 'passphrase', '1234'); //$this->gcmData[$push_mode]['certificate_password']
		stream_context_set_option($ctx, 'ssl', 'verify_peer', false);
		stream_context_set_option($ctx, 'ssl', 'serverApiKey', $this->gcmData[$push_mode]['serverApiKey']);
		$fp = stream_socket_client($this->gcmData[$push_mode]['feedback'], $error, $errorString, 60, STREAM_CLIENT_CONNECT, $ctx);

		if(!$fp) $this->_triggerError("NOTICE: Failed to connect to device: {$error} {$errorString}.");
		while ($devcon = fread($fp, 38)){
			$arr = unpack("H*", $devcon);
			$rawhex = trim(implode("", $arr));
			$device_reg_id = substr($rawhex, 12, 64);
			if(!empty($device_reg_id)){
				$this->_unregisterDevice($user_id, $appname, $device_uid, $device_reg_id);
				$this->_triggerError("NOTICE: Unregistering Device Token: {$device_reg_id}.");
			}
		}
		fclose($fp);
	}

	// Method: _pushSuccess() - GCM Push Success [Private Method]
	// This gets called automatically by _pushMessage.  When no errors are present, then the message was delivered.
	// @param int $push_message_id Primary ID of message that was delivered
	private function _pushSuccess($push_message_id){
		//$sql = "DELETE FROM `tbl_user_push_messages` WHERE `id`='{$push_message_id}'";
                $sql = "UPDATE `tbl_user_push_messages` SET `status`='D' WHERE `id`='{$push_message_id}' LIMIT 1;";
		$this->mysqli->query($sql);
                $this->mysqli->commit();
	}

	// Method: _pushFailed() - GCM Push Failed [Private Method]
	// This gets called automatically by _pushMessage.  If an error is present, then the message was NOT delivered.
	// @param int $push_message_id Primary ID of message that was delivered
	private function _pushFailed($push_message_id){
		$sql = "UPDATE `tbl_user_push_messages`
				SET `status`='F'
				WHERE `id`='{$push_message_id}'
				LIMIT 1;";
		$this->mysqli->query($sql);
		$this->mysqli->commit();
	}
	
	// Method: _jsonEncode() - JSON Encode [Private Method]
	// Some servers do not have json_encode, so use this instead.
	// @param array $array Data to convert to JSON string.
	private function _jsonEncode($array=false){
		if(is_null($array)) return 'null';
		if($array === false) return 'false';
		if($array === true) return 'true';
		if(is_scalar($array)){
			if(is_float($array)){
				return floatval(str_replace(",", ".", strval($array)));
			}
			if(is_string($array)){
				static $jsonReplaces = array(array("\\", "/", "\n", "\t", "\r", "\b", "\f", '"'), array('\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"'));
				return '"' . str_replace($jsonReplaces[0], $jsonReplaces[1], $array) . '"';
			}
			else return $array;
		}
		$isList = true;
		for($i=0, reset($array); $i<count($array); $i++, next($array)){
			if(key($array) !== $i){
				$isList = false;
				break;
			}
		}
		$result = array();
		if($isList){
			foreach($array as $v) $result[] = json_encode($v);
			return '[' . join(',', $result) . ']';
		}
		else {
			foreach ($array as $k => $v) $result[] = json_encode($k).':'.json_encode($v);
			return '{' . join(',', $result) . '}';
		}
	}

	// Method: registerUserDevice() - Register User+Device While Logging On
	public function registerUserDevice($user_id, $appname, $appversion, $device_uid, $device_reg_id, $device_name, $device_model, 
												$device_version, $device_os, /* $pushbadge, $pushalert, $pushsound, */ $push_mode) {
		$this->_registerDevice($user_id, $appname, $appversion, $device_uid, $device_reg_id, $device_name, $device_model, $device_version, 
								$device_os, /* $pushbadge, $pushalert, $pushsound, */ $push_mode);
	}
	
	// Method: unregisterUserDevice() - Unregister User+Device While Logging Off
	public function unregisterUserDevice($user_id, $appname, $device_uid, $device_reg_id){
		$this->_unregisterDevice($user_id, $appname, $device_uid, $device_reg_id);
	}
	
	//Method: CronPushMessage() - Instantly Pushes a Message
	public function CronPushMessage( $push_message_id, $user_id, $message, $device_uid, $device_reg_id, $appname, $push_mode){
		$this->_pushMessage($push_message_id, $message, $device_reg_id, $push_mode, $user_id, $device_uid, $appname);
	}
	
	//Method: InstaPushMessages() - Instantly Pushes a Message
	public function instaPushMessages($push_id){
		// only send one message per user... oldest message first
		    $sql = "SELECT
				`tbl_user_push_messages`.`fk_receiver_user_id`, `tbl_user_push_messages`.`message`, `tbl_user_mobile_devices`.`device_token`, `tbl_user_mobile_devices`.`push_mode`, 
				`tbl_user_mobile_devices`.`id`, `tbl_user_mobile_devices`.`device_uid` 
				FROM `tbl_user_push_messages` 
				LEFT JOIN `tbl_user_mobile_devices` ON
				`tbl_user_mobile_devices`.`device_uid` = `tbl_user_push_messages`.`device_uid` 
			
				WHERE `tbl_user_push_messages`.id='".$push_id."' ";
				
		if($result = $this->mysqli->query($sql)) 
		{
                   
			if($result->num_rows) 
			{
				 $row = $result->fetch_array(MYSQLI_ASSOC);
					/*
					$push_message_id = $this->mysqli->prepare($row['push_message_id']);
					$message = stripslashes($this->mysqli->prepare($row['message']));
					$device_reg_id = $this->mysqli->prepare($row['device_token']);
					$push_mode = $this->mysqli->prepare($row['push_mode']);
					$user_id = $this->mysqli->prepare($row['user_id']);
					$device_uid = $this->mysqli->prepare($row['device_uid']);
					$appname = $this->mysqli->prepare($row['appname']);
					*/

					$push_message_id = $push_id;
					
					$message = html_entity_decode($row['message']);
					$device_reg_id = $row['device_token'];
					$push_mode = $row['push_mode'];
					$user_id = $row['fk_receiver_user_id'];
					$device_uid = $row['device_uid'];
					$appname = 'mPokket';
                               	
					$this->_pushMessage($push_message_id, $message, $device_reg_id, $push_mode, $user_id, $device_uid, $appname);
                                     
			}
		}
	}

	// Sample For Using newMessage()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db); // CREATE THE OBJECT
	// $gcm->newMessage(1, '2010-01-01 00:00:00'); // START A MESSAGE... SECOND ARGUMENT ACCEPTS ANY DATETIME STRING
	// $gcm->addMessageAlert('You got your emails.'); // ALERTS ARE TRICKY... SEE EXAMPLES
	// $gcm->addMessageBadge(9); // PASS A NUMBER
	// $gcm->addMessageSound('bingbong.aiff'); // ADD A SOUND
	// $gcm->queueMessage(); // AND SEND IT ON IT'S WAY
	// </code>
	
	// Method: newMessage() - Start a New Message
	// @param int $user_id Foreign Key to the device you want to send a message to.
	// @param string $delivery Possible future date to send the message.
	public function newMessage($user_id,$device_id, $delivery=NULL){
		if(strlen($user_id)==0) $this->_triggerError('ERROR: Missing message user_id.', E_USER_ERROR);
		if(isset($this->push_message) && ($this->push_message != '')){
			unset($this->push_message);
			$this->_triggerError('NOTICE: An existing message already created but not delivered. The previous message has been removed. Use queueMessage() to complete a message.');
		}
		$this->push_message = array();
		$this->push_message['data'] = array();
		$this->push_message['send']['to'] = $user_id;
                $this->push_message['send']['device_id'] = $device_id;
		$this->push_message['send']['when'] = $delivery;
	}

	// Sample For Using queueMessage()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db);
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageAlert('You got your emails.');
	// $gcm->addMessageBadge(9);
	// $gcm->addMessageSound('bingbong.aiff');
	// $gcm->queueMessage(); // ADD THE MESSAGE TO QUEUE
	// </code>

	// Method: queueMessage() - Queue Message for Delivery
	public function queueMessage(){
		// check to make sure a message was created
		if(!isset($this->push_message)) $this->_triggerError('NOTICE: You cannot Queue a message that has not been created. Use newMessage() to create a new message.');

		// fetch the users id and check to make sure they have certain notifications enabled before trying to send anything to them.
		$deliver = true;
		$pushbadge = '1';
		$pushalert = '1';
		$pushsound = '1';
//		$sql = "SELECT `pushbadge`, `pushalert`, `pushsound` FROM `tbl_member_push_devices` WHERE `user_id`='{$this->push_message['send']['to']}' AND `status`='active' LIMIT 1;";
//		if($result = $this->mysqli->query($sql)){
//			if($result->num_rows){
//				while($row = $result->fetch_array(MYSQLI_ASSOC)){
//					$pushbadge = $row['pushbadge'];
//					$pushalert = $row['pushalert'];
//					$pushsound = $row['pushsound'];
//					/*
//					$pushbadge = $this->mysqli->prepare($row['pushbadge']);
//					$pushalert = $this->mysqli->prepare($row['pushalert']);
//					$pushsound = $this->mysqli->prepare($row['pushsound']);
//					*/
//				}
//				$deliver = true;
//			}
//		}
		// has user disabled messages?
		if($pushbadge=='0' && $pushalert=='0' && $pushsound=='0') $deliver = false;
		if(!$deliver) {
			$this->_triggerError('NOTICE: This user has either disabled push notifications, or does not exist in the database.');
			unset($this->push_message);
		}
		else {
			// get sending information
			$to = $this->push_message['send']['to'];
                        $device_id = $this->push_message['send']['device_id'];
			$when = $this->push_message['send']['when'];
			unset($this->push_message['send']);

			// remove notifications that user will not recieve.
			if($pushbadge=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Badge Notifications, Badge will not be delivered.');
				unset($this->push_message['data']['badge']);
			}
			if($pushalert=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Alert Notifications, Alert will not be delivered.');
				unset($this->push_message['data']['alert']);
			}
			if($pushsound=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Sound Notifications, Sound will not be delivered.');
				unset($this->push_message['data']['sound']);
			}

			/*
			$user_id = $this->mysqli->prepare($to);
			$message = $this->_jsonEncode($this->push_message);
			$message = $this->mysqli->prepare($message);
			*/
			$user_id = $to;
			$message = $this->_jsonEncode($this->push_message);
			$message = $message;
			$delivery = (!empty($when)) ? "'{$when}'":'NOW()';

			$this->mysqli->query("SET NAMES 'utf8';"); // force utf8 encoding if not your default
			//$sql = "INSERT INTO `tbl_member_push_messages` VALUES (UUID(), {$user_id}, '".$this->mysqli->real_escape_string($message)."', '{$delivery}', 'Q', NOW(), NOW());";
			$sql="INSERT INTO `tbl_user_push_messages` (`fk_receiver_user_id`, `device_uid`, `message`, `delivery`, `status`, `created`, `send_retry`, `retry_count`, `last_retry`, `modified`,`attend_mode`) VALUES 
                            ($user_id, '".$device_id."', '".$this->mysqli->real_escape_string($message)."', '".$delivery."', 'Q', CURRENT_TIMESTAMP, '0', NULL, NULL, CURRENT_TIMESTAMP,'D');";
                        $this->mysqli->query($sql);
			$this->mysqli->commit();
                       
			unset($this->push_message);
		}
	}
        
        // Method: queueMessage() - Queue Message for Delivery
	public function queueMessageDirect(){
		// check to make sure a message was created
		if(!isset($this->push_message)) $this->_triggerError('NOTICE: You cannot Queue a message that has not been created. Use newMessage() to create a new message.');

		// fetch the users id and check to make sure they have certain notifications enabled before trying to send anything to them.
		$deliver = true;
		$pushbadge = '1';
		$pushalert = '1';
		$pushsound = '1';
//		$sql = "SELECT `pushbadge`, `pushalert`, `pushsound` FROM `tbl_member_push_devices` WHERE `user_id`='{$this->push_message['send']['to']}' AND `status`='active' LIMIT 1;";
//		if($result = $this->mysqli->query($sql)){
//			if($result->num_rows){
//				while($row = $result->fetch_array(MYSQLI_ASSOC)){
//					$pushbadge = $row['pushbadge'];
//					$pushalert = $row['pushalert'];
//					$pushsound = $row['pushsound'];
//					/*
//					$pushbadge = $this->mysqli->prepare($row['pushbadge']);
//					$pushalert = $this->mysqli->prepare($row['pushalert']);
//					$pushsound = $this->mysqli->prepare($row['pushsound']);
//					*/
//				}
//				$deliver = true;
//			}
//		}
		// has user disabled messages?
		if($pushbadge=='0' && $pushalert=='0' && $pushsound=='0') $deliver = false;
		if(!$deliver) {
			$this->_triggerError('NOTICE: This user has either disabled push notifications, or does not exist in the database.');
			unset($this->push_message);
		}
		else {
			// get sending information
			$to = $this->push_message['send']['to'];
                        $device_id = $this->push_message['send']['device_id'];
			$when = $this->push_message['send']['when'];
			unset($this->push_message['send']);

			// remove notifications that user will not recieve.
			if($pushbadge=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Badge Notifications, Badge will not be delivered.');
				unset($this->push_message['data']['badge']);
			}
			if($pushalert=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Alert Notifications, Alert will not be delivered.');
				unset($this->push_message['data']['alert']);
			}
			if($pushsound=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Sound Notifications, Sound will not be delivered.');
				unset($this->push_message['data']['sound']);
			}

			/*
			$user_id = $this->mysqli->prepare($to);
			$message = $this->_jsonEncode($this->push_message);
			$message = $this->mysqli->prepare($message);
			*/
			$user_id = $to;
			$message = $this->_jsonEncode($this->push_message);

			 //$message = $this->push_message;
			
			$message = $message;
			$delivery = (!empty($when)) ? "'{$when}'":'NOW()';

			$this->mysqli->query("SET NAMES 'utf8';"); // force utf8 encoding if not your default
			//$sql = "INSERT INTO `tbl_member_push_messages` VALUES (UUID(), {$user_id}, '".$this->mysqli->real_escape_string($message)."', '{$delivery}', 'Q', NOW(), NOW());";
			 $sql="INSERT INTO `tbl_user_push_messages` (`fk_receiver_user_id`, `device_uid`, `message`, `delivery`, `status`, `created`, `send_retry`, `retry_count`, `last_retry`, `modified`,`attend_mode`) VALUES 
                            ($user_id, '".$device_id."', '".$message."', '".$delivery."', 'Q', CURRENT_TIMESTAMP, '0', NULL, NULL, CURRENT_TIMESTAMP,'D');";
                        $this->mysqli->query($sql);
                        	
                         $insert_id=$this->mysqli->insert_id;

			$this->mysqli->commit();

                 
                        $this->instaPushMessages($insert_id);
                       
			unset($this->push_message);
		}
	}
	// Sample For Using addMessageAlert()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db);
	//
	// // SIMPLE ALERT
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageAlert('Message received from Bob'); // MAKES DEFAULT BUTTON WITH BOTH 'Close' AND 'View' BUTTONS
	// $gcm->queueMessage();
	//
	// // CUSTOM 'View' BUTTON
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageAlert('Bob wants to play poker', 'PLAY'); // MAKES THE 'View' BUTTON READ 'PLAY'
	// $gcm->queueMessage();
	//
	// // NO 'View' BUTTON
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageAlert('Bob wants to play poker', ''); // MAKES AN ALERT WITH JUST AN 'OK' BUTTON
	// $gcm->queueMessage();
	//
	// // CUSTOM LOCALIZATION STRING FOR YOUR APP
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageAlert(NULL, NULL, 'GAME_PLAY_REQUEST_FORMAT', array('Jenna', 'Frank'));
	// $gcm->queueMessage();
	// </code>
	
	// Method: addMessageAlert() - Add Message Alert
	public function addMessageAlert($alert=NULL, $actionlockey=NULL, $lockey=NULL, $locargs=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if(isset($this->push_message['data']['alert'])){
			unset($this->push_message['data']['alert']);
			$this->_triggerError('NOTICE: An existing alert was already created but not delivered. The previous alert has been removed.');
		}
		switch(true){
			case (!empty($alert) && empty($actionlockey) && empty($lockey) && empty($locargs)):
				if(!is_string($alert)) $this->_triggerError('ERROR: Invalid Alert Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['data']['alert'] = (string)$alert;
				break;

			case (!empty($alert) && !empty($actionlockey) && empty($lockey) && empty($locargs)):
				if(!is_string($alert)) $this->_triggerError('ERROR: Invalid Alert Format. See documentation for correct procedure.', E_USER_ERROR);
				else if(!is_string($actionlockey)) $this->_triggerError('ERROR: Invalid Action Loc Key Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['data']['alert']['body'] = (string)$alert;
				$this->push_message['data']['alert']['action-loc-key'] = (string)$actionlockey;
				break;

			case (empty($alert) && empty($actionlockey) && !empty($lockey) && !empty($locargs)):
				if(!is_string($lockey)) $this->_triggerError('ERROR: Invalid Loc Key Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['data']['alert']['loc-key'] = (string)$lockey;
				$this->push_message['data']['alert']['loc-args'] = $locargs;
				break;

			default:
				$this->_triggerError('ERROR: Invalid Alert Format. See documentation for correct procedure.', E_USER_ERROR);
				break;
		}
	}

	// Sample For Using addMessageBadge()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db);
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageBadge(9); // HAS TO BE A NUMBER
	// $gcm->queueMessage();
	// </code>

	// Method: addMessageBadge() - Add Message Badge
	public function addMessageBadge($number=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($number) {
			if(isset($this->push_message['data']['badge'])) $this->_triggerError('NOTICE: Message Badge has already been created. Overwriting with '.$number.'.');
			$this->push_message['data']['badge'] = (int)$number;
		}
	}

	// Sample For Using addMessageCustom()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db);
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageCustom('acme1', 42); // CAN BE NUMBER...
	// $gcm->addMessageCustom('acme2', 'foo'); // ... STRING
	// $gcm->addMessageCustom('acme3', array('bang', 'whiz')); // OR ARRAY
	// $gcm->queueMessage();
	// </code>

	// Method: addMessageCustom() - Add Message Custom
	// @param string $key Name of Custom Object you want to pass back to your iPhone App
	// @param mixed $value Mixed Value you want to pass back.  Can be int, bool, string, or array.
	public function addMessageCustom($key=NULL, $value=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if(!empty($key) && !empty($value)) {
			if(isset($this->push_message[$key])){
				unset($this->push_message[$key]);
				$this->_triggerError('NOTICE: This same Custom Key already exists and has not been delivered. The previous values have been removed.');
			}
			if(!is_string($key)) $this->_triggerError('ERROR: Invalid Key Format. Key must be a string. See documentation for correct procedure.', E_USER_ERROR);
			$this->push_message[$key] = $value;
		}
	}

	// Sample For Using addMessageSound()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $gcm = new GCM($db);
	// $gcm->newMessage(1, '2010-01-01 00:00:00');
	// $gcm->addMessageSound('bingbong.aiff'); // STRING OF FILE NAME
	// $gcm->queueMessage();
	// </code>

	// Method: addMessageSound() - Add Message Sound
	// @param string $sound Name of sound file in your Resources Directory
	// @access public
	public function addMessageSound($sound=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($sound) {
			if(isset($this->push_message['data']['sound'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$sound.'.');
			$this->push_message['data']['sound'] = (string)$sound;
		}
	}
	
	public function addMessageVibrate($vibrate=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($vibrate) {
			if(isset($this->push_message['data']['vibrate'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$vibrate.'.');
			$this->push_message['data']['vibrate'] = (string)$vibrate;
		}
	}

	// Method: addMessageNotificationMessage() - Add notification Message
	// @param string $vibrate status
	// @access public
	public function addMessageNotificationMessage($message=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($message) {
			if(isset($this->push_message['data']['message'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$message.'.');
			$this->push_message['data']['message'] = (string)$message;
		}
	}


	// Method: addMessageNotificationTitle() - Add notification Title
	// @param string $vibrate status
	// @access public
	public function addMessageNotificationTitle($title=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($title) {
			if(isset($this->push_message['data']['title'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$title.'.');
			$this->push_message['data']['title'] = (string)$title;
		}
	}

	// Method: addMessageNotificationImage() - Add notification Image
	// @param string $image status
	// @access public
	public function addMessageNotificationImage($image=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($image) {
			if(isset($this->push_message['data']['image'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$image.'.');
			$this->push_message['data']['image'] = (string)$image;
		}
	}



	// Method: _testInitializationValues()
	private function testInitializationValues() {
		echo "<hr/>";
		echo "showPushErrors: ".$this->showPushErrors."<br/>";
		echo "logPushErrors: ".$this->logPushErrors."<br/>";
		echo "pushMode: ".$this->pushMode."<br/>";
		echo "logPath: ".$this->logPath."<br/>";
		echo "logMaxSize: ".$this->logMaxSize."<br/>";
		echo "serverApiKey: ".$this->serverApiKey."<br/>";
		echo "pushURL: ".$this->pushURL."<br/>";
		echo "push_message: ".$this->push_message."<br/>";
	}
}
/*
$test_push = new appAndroidGCMPushAPI();
$test_push->testInitializationValues();
*/
?>
