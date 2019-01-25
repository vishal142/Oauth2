<?php
class AppApplePushAPI {
    // Database Object
	private $mysqli;

	// Array of APNS Connection Settings
	private $apnsData;
	// Push Mode: Sandbox or Production
	private $pushMode;
	// Whether to trigger errors
	private $showPushErrors;
	// Whether APNS should log errors
	private $logPushErrors;
	// Log path for APNS errors
	private $logPath;

	// Max files size of log before it is truncated. 1048576 = 1MB.  Added incase you do not add to a log
	// rotator so this script will not accidently make gigs of error logs if there are issues with install
	private $logMaxSize; // max log size before it is truncated

	// Absolute path to your Production Certificate
	private $productionCertificate;
	
	// Passphrase for Production Certificate
	private $productionPassphrase;

	// Apples Production APNS Gateway
	private $productionSsl;

	// Apples Production APNS Feedback Service
	private $productionFeedback;

	// Absolute path to your Development Certificate
	private $sandboxCertificate; // change this to your development certificate absolute path

	// Passphrase for Sandbox Certificate
	private $sandboxPassphrase;

	// Apples Sandbox APNS Gateway
	private $sandboxSsl;

	// Apples Sandbox APNS Feedback Service
	private $sandboxFeedback;

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
		$this->mysqli->set_charset("utf8");
        $this->mysqli->autocommit(FALSE);
		$this->myCounter = 1;

                
		// Apple Push Initialization
		// -------------------------
		$apns_push_data = parse_ini_file('/var/www/html/apis/resource/config.ini', true);
		//$phyicalwebroot = realpath($_SERVER['DOCUMENT_ROOT'].$apns_push_data['Website']['VirtualFolder']);
		// $phyicalwebroot = realpath($_SERVER['DOCUMENT_ROOT'].$gcm_push_data['Website']['VirtualFolder']);
		$phyicalwebroot = realpath($apns_push_data['Website']['WebRoot'].$apns_push_data['Website']['VirtualFolder']);
		
		$this->pushMode = $apns_push_data['PUSH_MODE']['Mode'];
		$this->showPushErrors = ($apns_push_data['APNS']['showPushErrors']=='1'?true:false);
		$this->logPushErrors = ($apns_push_data['APNS']['logPushErrors']=='1'?true:false);
		$this->logPath = $phyicalwebroot.$apns_push_data['APNS']['logPath']."/".$apns_push_data['APNS']['logFile'];
		$this->logMaxSize = $apns_push_data['APNS']['logMaxSize'];
		//$productionCertificatePath = $apns_push_data['APNS']['productionCertificatePath'];		
		$productionCertificatePath = $apns_push_data['APNS']['cron_productionCertificatePath'];
		$productionCertificateFile = $apns_push_data['APNS']['productionCertificateFile'];
		// $this->productionCertificate = $phyicalwebroot.$productionCertificatePath."/".$productionCertificateFile;
               $this->productionCertificate = $productionCertificatePath.$productionCertificateFile;
		$this->productionPassphrase = $apns_push_data['APNS']['productionPassphrase'];
		$this->productionSsl = $apns_push_data['APNS']['productionSsl'];
		$this->productionFeedback = $apns_push_data['APNS']['productionFeedback'];
		//$sandboxCertificatePath = $apns_push_data['APNS']['sandboxCertificatePath'];
		$sandboxCertificatePath = $apns_push_data['APNS']['cron_sandboxCertificatePath'];
                $sandboxCertificateFile = $apns_push_data['APNS']['sandboxCertificateFile'];
		// $this->sandboxCertificate = $phyicalwebroot.$sandboxCertificatePath."/".$sandboxCertificateFile;
		$this->sandboxCertificate = $sandboxCertificatePath.$sandboxCertificateFile;
		$this->sandboxPassphrase = $apns_push_data['APNS']['sandboxPassphrase'];
		$this->sandboxSsl = $apns_push_data['APNS']['sandboxSsl'];
		$this->sandboxFeedback = $apns_push_data['APNS']['sandboxFeedback'];
		$this->push_message = '';
		// -------------------------

		$this->checkSetup();
		$this->apnsData = array(
			'mode'=>$this->pushMode,
			'P'=>array(
				'certificate'=>$this->productionCertificate,
				'ssl'=>$this->productionSsl,
				'feedback'=>$this->productionFeedback,
				'passphrase'=>$this->productionPassphrase
			),
			'S'=>array(
				'certificate'=>$this->sandboxCertificate,
				'ssl'=>$this->sandboxSsl,
				'feedback'=>$this->sandboxFeedback,
				'passphrase'=>$this->sandboxPassphrase
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
						$args['device_token'],
						$args['device_name'],
						$args['device_model'],
						$args['device_version'],
						$args['device_os'],
						$args['push_mode']
					);
					//	$args['pushbadge'],
					//	$args['pushalert'],
					//	$args['pushsound'],
					break;

				case "fetch";
					$this->_fetchMessages();
					break;

				default:
					echo "No APNS Task Provided...\n";
					break;
			}
		}
		// ---------------------------------
    }
 
    // Destructor - close DB connection
    function __destruct() {
        $this->mysqli->close();
    }

	// Method: checkSetup() - Check if the certificates are available and also provide a notice if they are not as secure as they could be [Private Method]
	private function checkSetup(){
		if(!file_exists($this->productionCertificate)) $this->_triggerError('ERROR: Missing Production Certificate.', E_USER_ERROR);
		if(!file_exists($this->sandboxCertificate)) $this->_triggerError('ERROR: Missing Sandbox Certificate.', E_USER_ERROR);

		clearstatcache();
    	$certificateMod = substr(sprintf('%o', fileperms($this->productionCertificate)), -3);
		$sandboxCertificateMod = substr(sprintf('%o', fileperms($this->sandboxCertificate)), -3); 

		if($certificateMod>644)  $this->_triggerError('NOTICE: Production Certificate is insecure! Suggest chmod 644.');
		if($sandboxCertificateMod>644)  $this->_triggerError('NOTICE: Sandbox Certificate is insecure! Suggest chmod 644.');
	}

	// Method: _triggerError() [Private Method]
	// Use PHP error handling to trigger User Errors or Notices.  If logging is enabled, errors will be written to the log as well.
	// Disable on screen errors by setting showPushErrors to false;
	private function _triggerError($error, $type=E_USER_NOTICE){
		$backtrace = debug_backtrace();
		$backtrace = array_reverse($backtrace);
		$error .= "\n";
		$i=1;
		foreach($backtrace as $errorcode){
			$file = ($errorcode['file']!='') ? "-> File: ".basename($errorcode['file'])." (line ".$errorcode['line'].")":"";
			$error .= "\n\t".$i.") ".$errorcode['class']."::".$errorcode['function']." {$file}";
			$i++;
		}
		$error .= "\n\n";
		if($this->logPushErrors && file_exists($this->logPath)){
			if(filesize($this->logPath) > $this->logMaxSize) $fh = fopen($this->logPath, 'w');
			else $fh = fopen($this->logPath, 'a');
			fwrite($fh, $error);
			fclose($fh);
		}
		if($this->showPushErrors) trigger_error($error, $type);
	}
	
	// Method: _registerDevice() - Register Apple device [Private Method]
	// Using your Delegate file to auto register the device on application launch.
	//  This will happen automatically from the Delegate.m file in your iPhone Application using our code.
	// @param Bigint $user_id User Id
	// @param string $appname Application Name
	// @param string $appversion Application Version
	// @param string $device_uid 40 charater unique user id of Apple device
	// @param string $device_token 64 character unique device token tied to device id
	// @param string $device_name User selected device name
	// @param string $device_model Model of device 'iPhone' or 'iPod'
	// @param string $device_version Current version of device
	// @param string $device_os OS Version of Device
	// @param string $pushbadge Whether Badge Pushing is Enabled or Disabled [0|1]
	// @param string $pushalert Whether Alert Pushing is Enabled or Disabled [0|1]
	// @param string $pushsound Whether Sound Pushing is Enabled or Disabled [0|1]
	// @param string $push_mode Whether Production or Sandbox [P|S]
	// $pushbadge, $pushalert, $pushsound,
	private function _registerDevice($user_id, $appname, $appversion, $device_uid, $device_token, $device_name, $device_model, $device_version, $device_os, $push_mode) {

		if(strlen($appname)==0) $this->_triggerError('ERROR: Application Name must not be blank.', E_USER_ERROR);
		else if(strlen($appversion)==0) $this->_triggerError('ERROR: Application Version must not be blank.', E_USER_ERROR);
		else if(strlen($device_uid)!=40) $this->_triggerError('ERROR: Device ID must be 40 characters in length.', E_USER_ERROR);
		else if(strlen($device_token)!=64) $this->_triggerError('ERROR: Device Token must be 64 characters in length.', E_USER_ERROR);
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
		$device_token = $this->mysqli->prepare($device_token);
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
		$this->mysqli->query("SET NAMES 'utf8';"); // force utf8 encoding if not your default
		// , '{$pushbadge}', '{$pushalert}', '{$pushsound}'
		//		`pushbadge`='{$pushbadge}',
		//		`pushalert`='{$pushalert}',
		//		`pushsound`='{$pushsound}',
		$sql = "INSERT INTO `tbl_member_push_devices` (`user_id`, `appname`, `appversion`, `device_uid`, `device_token`, `device_name`, `device_model`, `device_version`, `device_os`, `badge_count`, `push_mode`, `status`, `created`, `modified`)
				VALUES (
					{$user_id}, '{$appname}', '{$appversion}', '{$device_uid}', '{$device_token}', '".$this->mysqli->real_escape_string($device_name)."', '{$device_model}',	'{$device_version}', 
					'{$device_os}', '0',  '{$push_mode}', 'active', NOW(), NOW()
				)
				ON DUPLICATE KEY UPDATE
				`device_token`='{$device_token}',
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
		$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_uid`='{$device_uid}' AND `user_id`<>{$user_id};";
		$this->mysqli->query($sql);
		$this->mysqli->commit();
	}

	// Method: _unregisterDevice() - Unregister Apple device [Private Method]
	// This gets called automatically when Apple's Feedback Service responds with an invalid token.
	// @param string $device_token 64 character unique device token tied to device id
	private function _unregisterDevice($user_id=NULL, $appname, $device_uid, $device_token){
		if ($user != NULL) {
			$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_token`='{$device_token}' AND `user_id`={$user_id} AND 
					`appname`='{$appname}' AND `device_uid`='{$device_uid}';";
			$this->mysqli->query($sql);
			$this->mysqli->commit();
		}
		 else {
			$sql = "UPDATE `tbl_member_push_devices` SET `status`='uninstalled' WHERE `device_token`='{$device_token}' AND `appname`='{$appname}' AND 
					`device_uid`='{$device_uid}';";
			$this->mysqli->query($sql);
			$this->mysqli->commit();
		}
	}
	
	// Method: _fetchMessages() - Fetch Messages [Private Method]
	// This gets called by a cron job that runs as often as you want.  You might want to set it for every minute.
	// @param string $device_token 64 character unique device token tied to device id
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
					$device_token = $this->mysqli->prepare($row['device_token']);
					$push_mode = $this->mysqli->prepare($row['push_mode']);
					$user_id = $this->mysqli->prepare($row['user_id']);
					$device_uid = $this->mysqli->prepare($row['device_uid']);
					$appname = $this->mysqli->prepare($row['appname']);
					*/
					
					$push_message_id = $row['id'];
					$message = stripslashes($row['message']);
					$device_token = $row['device_token'];
					$push_mode = $row['push_mode'];
					$user_id = ($row['fk_member_id']!=NULL)?$row['fk_member_id']:0;
					$device_uid = $row['device_uid'];
					$appname = $row['appname'];
					$this->_pushMessage($push_message_id, $message, $device_token, $push_mode, $user_id, $device_uid, $appname);
				}
			}
		}
	}

	// Method: _pushMessage() - Push APNS Messages [Private Method]
	// This gets called automatically by _fetchMessages.  This is what actually deliveres the message.
	// @param int $push_message_id
	// @param string $message JSON encoded string
	// @param string $device_token 64 character unique device token tied to device id
	// @param string $push_mode Which SSL to connect to, Sandbox or Production
	private function _pushMessage($push_message_id, $message, $device_token, $push_mode, $user_id, $device_uid, $appname){
		

		if(strlen($push_message_id)==0) $this->_triggerError('ERROR: Missing message push_message_id.', E_USER_ERROR);
		if(strlen($message)==0) $this->_triggerError('ERROR: Missing message.', E_USER_ERROR);
		if(strlen($device_token)==0) $this->_triggerError('ERROR: Missing message token.', E_USER_ERROR);
		if(strlen($push_mode)==0) $this->_triggerError('ERROR: Missing push mode status.', E_USER_ERROR);
		if(strlen($user_id)==0) $this->_triggerError('ERROR: Missing user id.', E_USER_ERROR);
		if(strlen($device_uid)==0) $this->_triggerError('ERROR: Missing device uid.', E_USER_ERROR);
		if(strlen($appname)==0) $this->_triggerError('ERROR: Missing appname.', E_USER_ERROR);
               
		$ctx = stream_context_create();
                
		stream_context_set_option($ctx, 'ssl', 'local_cert', $this->apnsData[$push_mode]['certificate']);
		stream_context_set_option($ctx, 'ssl', 'passphrase', $this->apnsData[$push_mode]['passphrase']);
		$fp = stream_socket_client($this->apnsData[$push_mode]['ssl'], $error, $errorString, 60, STREAM_CLIENT_CONNECT, $ctx);

		
		
		if(!$fp){
			$this->_pushFailed($push_message_id);
			$this->_triggerError("NOTICE: Failed to connect to APNS: {$error} {$errorString}.");
		}
		else {
			
			$msg = chr(0).pack("n",32).pack('H*',$device_token).pack("n",strlen($message)).$message;
			$fwrite = fwrite($fp, $msg);
			if(!$fwrite) {
				
				$this->_pushFailed($push_message_id);
				$this->_triggerError("ERROR: Failed writing to stream.", E_USER_ERROR);
			}
			else {
                   
				$this->_pushSuccess($push_message_id);
			}
		}
		fclose($fp);

		$this->_checkFeedback($push_mode, $user_id, $device_uid, $appname);
	}

	// Method: _checkFeedback() - Fetch APNS Messages [Private Method]
	// This gets called automatically by _pushMessage.  This will check with APNS for any invalid tokens and disable them from receiving further notifications.
	// @param string $push_mode Which SSL to connect to, Sandbox or Production
	private function _checkFeedback($push_mode, $user_id=NULL, $device_uid, $appname){
		$ctx = stream_context_create();
		stream_context_set_option($ctx, 'ssl', 'local_cert', $this->apnsData[$push_mode]['certificate']);
		stream_context_set_option($ctx, 'ssl', 'passphrase', 'Mass4Pass'); //$this->apnsData[$push_mode]['certificate_password']
		stream_context_set_option($ctx, 'ssl', 'verify_peer', false);
		$fp = stream_socket_client($this->apnsData[$push_mode]['feedback'], $error, $errorString, 60, STREAM_CLIENT_CONNECT, $ctx);

		if(!$fp) $this->_triggerError("NOTICE: Failed to connect to device: {$error} {$errorString}.");
		while ($devcon = fread($fp, 38)){
			$arr = unpack("H*", $devcon);
			$rawhex = trim(implode("", $arr));
			$device_token = substr($rawhex, 12, 64);
			if(!empty($device_token)){
				$this->_unregisterDevice($user_id, $appname, $device_uid, $device_token);
				$this->_triggerError("NOTICE: Unregistering Device Token: {$device_token}.");
			}
		}
		fclose($fp);
	}

	// Method: _pushSuccess() - APNS Push Success [Private Method]
	// This gets called automatically by _pushMessage.  When no errors are present, then the message was delivered.
	// @param int $push_message_id Primary ID of message that was delivered
	private function _pushSuccess($push_message_id){
		//$sql = "DELETE FROM `tbl_user_push_messages` WHERE `id`='{$push_message_id}'";
                 $sql = "UPDATE `tbl_user_push_messages` SET `status`='D' WHERE `id`='{$push_message_id}' LIMIT 1;";
		$this->mysqli->query($sql);
                $this->mysqli->commit();
	}

	// Method: _pushFailed() - APNS Push Failed [Private Method]
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
	// $pushbadge, $pushalert, $pushsound, 
	// $pushbadge, $pushalert, $pushsound, 
	public function registerUserDevice($user_id, $appname, $appversion, $device_uid, $device_token, $device_name, $device_model, 
												$device_version, $device_os, $push_mode) {
		$this->_registerDevice($user_id, $appname, $appversion, $device_uid, $device_token, $device_name, $device_model, $device_version, 
								$device_os, $push_mode);
	}
	
	// Method: unregisterUserDevice() - Unregister User+Device While Logging Off
	public function unregisterUserDevice($user_id, $appname, $device_uid, $device_token){
		$this->_unregisterDevice($user_id, $appname, $device_uid, $device_token);
	}
	
	//Method: CronPushMessage() - Instantly Pushes a Message
	public function CronPushMessage($push_message_id, $user_id, $message, $device_uid, $device_token, $appname, $push_mode){
		$this->_pushMessage($push_message_id, $message, $device_token, $push_mode, $user_id, $device_uid, $appname);
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
					
					$push_message_id = $push_id;
					$message = stripslashes($row['message']);
					$device_token = $row['device_token'];
					$push_mode = $row['push_mode'];
					$user_id = $row['fk_receiver_user_id'];
					$device_uid = $row['device_uid'];
					$appname = 'mPokket';
					$this->_pushMessage($push_message_id, $message, $device_token, $push_mode, $user_id, $device_uid, $appname);
				
			}
		}
	}

	// Sample For Using newMessage()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $apns = new APNS($db); // CREATE THE OBJECT
	// $apns->newMessage(1, '2010-01-01 00:00:00'); // START A MESSAGE... SECOND ARGUMENT ACCEPTS ANY DATETIME STRING
	// $apns->addMessageAlert('You got your emails.'); // ALERTS ARE TRICKY... SEE EXAMPLES
	// $apns->addMessageBadge(9); // PASS A NUMBER
	// $apns->addMessageSound('bingbong.aiff'); // ADD A SOUND
	// $apns->queueMessage(); // AND SEND IT ON IT'S WAY
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
		$this->push_message['aps'] = array();
		$this->push_message['send']['to'] = $user_id;
                $this->push_message['send']['device_id'] = $device_id;
		$this->push_message['send']['when'] = $delivery;
	}

	// Sample For Using queueMessage()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $apns = new APNS($db);
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageAlert('You got your emails.');
	// $apns->addMessageBadge(9);
	// $apns->addMessageSound('bingbong.aiff');
	// $apns->queueMessage(); // ADD THE MESSAGE TO QUEUE
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
                $sql = "SELECT `badge_count` FROM `tbl_member_push_devices` WHERE `device_uid`='".$this->push_message['send']['device_id']."' AND `fk_member_id`='".$this->push_message['send']['to']."' ";
		if($result = $this->mysqli->query($sql)){
			if($result->num_rows){
				while($row = $result->fetch_array(MYSQLI_ASSOC)){
					//$pushbadge = $row['badge_count'];
					$this->addMessageBadge($pushbadge);
					/*
					$pushbadge = $this->mysqli->prepare($row['pushbadge']);
					$pushalert = $this->mysqli->prepare($row['pushalert']);
					$pushsound = $this->mysqli->prepare($row['pushsound']);
					*/
				}
				$deliver = true;
			}
		}
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
				unset($this->push_message['aps']['badge']);
			}
			if($pushalert=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Alert Notifications, Alert will not be delivered.');
				unset($this->push_message['aps']['alert']);
			}
			if($pushsound=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Sound Notifications, Sound will not be delivered.');
				unset($this->push_message['aps']['sound']);
			}

			/*
			$user_id = $this->mysqli->prepare($to);
			$message = $this->_jsonEncode($this->push_message);
			$message = $this->mysqli->prepare($message);
			*/
			$user_id = $to;
			$message = $this->_jsonEncode($this->push_message);
			//$message = $message;
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
		$sql = "SELECT `badge_count`,id FROM `tbl_salesrep_mobile_devices` WHERE `device_uid`='".$this->push_message['send']['device_id']."' ";
		$result = $this->mysqli->query($sql);
		//$row = $result->fetch_array(MYSQLI_ASSOC);
		//print_r($row);
		
		
			$result->num_rows;
			if($result->num_rows>0){
				
				while($row = $result->fetch_array(MYSQLI_ASSOC)){
					$pushbadge = $row['badge_count'];
					$push_device_msg_id=$row['id'];
					$this->addMessageBadge($pushbadge);
					/*
					$pushbadge = $this->mysqli->prepare($row['pushbadge']);
					$pushalert = $this->mysqli->prepare($row['pushalert']);
					$pushsound = $this->mysqli->prepare($row['pushsound']);
					*/
				}
				$deliver = true;
			}
		
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
				unset($this->push_message['aps']['badge']);
			}
			if($pushalert=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Alert Notifications, Alert will not be delivered.');
				unset($this->push_message['aps']['alert']);
			}
			if($pushsound=='0'){
				$this->_triggerError('NOTICE: This user has disabled Push Sound Notifications, Sound will not be delivered.');
				unset($this->push_message['aps']['sound']);
			}

			/*
			$user_id = $this->mysqli->prepare($to);
			$message = $this->_jsonEncode($this->push_message);
			$message = $this->mysqli->prepare($message);
			*/
			$user_id = $to;
			
			$message = $this->_jsonEncode($this->push_message);
			//$message = $message;
			$delivery = (!empty($when)) ? "'{$when}'":'NOW()';
			$uuid=$this->create_guid();
			$this->mysqli->query("SET NAMES 'utf8';"); // force utf8 encoding if not your default
			//$sql = "INSERT INTO `tbl_member_push_messages` VALUES (UUID(), {$user_id}, '".$this->mysqli->real_escape_string($message)."', '{$delivery}', 'Q', NOW(), NOW());";
			 
                          $sql="INSERT INTO `tbl_user_push_messages` (`fk_receiver_user_id`, `device_uid`, `message`, `delivery`, `status`, `created`, `send_retry`, `retry_count`, `last_retry`, `modified`,`attend_mode`) VALUES 
                            ($user_id, '".$device_id."', '".$message."', '".$delivery."', 'Q', CURRENT_TIMESTAMP, '0', NULL, NULL, CURRENT_TIMESTAMP,'D');";
                        $this->mysqli->query($sql);
                  $insert_id=$this->mysqli->insert_id;

			$this->mysqli->commit();

                 
                        $this->instaPushMessages($insert_id);
		}
	}


	public function create_guid() {
	    $microTime = microtime();
	    list($a_dec, $a_sec) = explode(" ", $microTime);
	    $dec_hex = dechex($a_dec * 1000000);
	    $sec_hex = dechex($a_sec);
	    $this->ensure_length($dec_hex, 5);
	    $this->ensure_length($sec_hex, 6);
	    $guid = "";
	    $guid .= $dec_hex;
	    $guid .= $this->create_guid_section(3);
	    $guid .= '-';
	    $guid .= $this->create_guid_section(4);
	    $guid .= '-';
	    $guid .= $this->create_guid_section(4);
	    $guid .= '-';
	    $guid .= $this->create_guid_section(4);
	    $guid .= '-';
	    $guid .= $sec_hex;
	    $guid .= $this->create_guid_section(6);
	    return $guid;
	}
	public function ensure_length(&$string, $length) {
	    $strlen = strlen($string);
	    if ($strlen < $length) {
	        $string = str_pad($string, $length, "0");
	    } else if ($strlen > $length) {
	        $string = substr($string, 0, $length);
	    }
	}

	public function create_guid_section($characters) {
	    $return = "";
	    for ($i = 0; $i < $characters; $i++) {
	        $return .= dechex(mt_rand(0, 15));
	    }
	    return $return;
	}
	// Sample For Using addMessageAlert()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $apns = new APNS($db);
	//
	// // SIMPLE ALERT
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageAlert('Message received from Bob'); // MAKES DEFAULT BUTTON WITH BOTH 'Close' AND 'View' BUTTONS
	// $apns->queueMessage();
	//
	// // CUSTOM 'View' BUTTON
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageAlert('Bob wants to play poker', 'PLAY'); // MAKES THE 'View' BUTTON READ 'PLAY'
	// $apns->queueMessage();
	//
	// // NO 'View' BUTTON
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageAlert('Bob wants to play poker', ''); // MAKES AN ALERT WITH JUST AN 'OK' BUTTON
	// $apns->queueMessage();
	//
	// // CUSTOM LOCALIZATION STRING FOR YOUR APP
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageAlert(NULL, NULL, 'GAME_PLAY_REQUEST_FORMAT', array('Jenna', 'Frank'));
	// $apns->queueMessage();
	// </code>
	
	// Method: addMessageAlert() - Add Message Alert
	public function addMessageAlert($alert=NULL, $actionlockey=NULL, $lockey=NULL, $locargs=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if(isset($this->push_message['aps']['alert'])){
			unset($this->push_message['aps']['alert']);
			$this->_triggerError('NOTICE: An existing alert was already created but not delivered. The previous alert has been removed.');
		}
		switch(true){
			case (!empty($alert) && empty($actionlockey) && empty($lockey) && empty($locargs)):
				if(!is_string($alert)) $this->_triggerError('ERROR: Invalid Alert Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['aps']['alert'] = (string)$alert;
				break;

			case (!empty($alert) && !empty($actionlockey) && empty($lockey) && empty($locargs)):
				if(!is_string($alert)) $this->_triggerError('ERROR: Invalid Alert Format. See documentation for correct procedure.', E_USER_ERROR);
				else if(!is_string($actionlockey)) $this->_triggerError('ERROR: Invalid Action Loc Key Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['aps']['alert']['body'] = (string)$alert;
				$this->push_message['aps']['alert']['action-loc-key'] = (string)$actionlockey;
				break;

			case (empty($alert) && empty($actionlockey) && !empty($lockey) && !empty($locargs)):
				if(!is_string($lockey)) $this->_triggerError('ERROR: Invalid Loc Key Format. See documentation for correct procedure.', E_USER_ERROR);
				$this->push_message['aps']['alert']['loc-key'] = (string)$lockey;
				$this->push_message['aps']['alert']['loc-args'] = $locargs;
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
	// $apns = new APNS($db);
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageBadge(9); // HAS TO BE A NUMBER
	// $apns->queueMessage();
	// </code>

	// Method: addMessageBadge() - Add Message Badge
	public function addMessageBadge($number=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($number) {
			if(isset($this->push_message['aps']['badge'])) $this->_triggerError('NOTICE: Message Badge has already been created. Overwriting with '.$number.'.');
			$this->push_message['aps']['badge'] = (int)$number;
		}
	}
        
        // Method: addMessageBadge() - Add Message Badge
	public function addMessageContentAvailable($number=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($number) {
			if(isset($this->push_message['aps']['content_available'])) $this->_triggerError('NOTICE: Message Badge has already been created. Overwriting with '.$number.'.');
			$this->push_message['aps']['content_available'] = (int)$number;
		}
	}
        
	// Sample For Using addMessageCustom()
	// <code>
	// $db = new DbConnect();
	// $db->show_errors();
	// $apns = new APNS($db);
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageCustom('acme1', 42); // CAN BE NUMBER...
	// $apns->addMessageCustom('acme2', 'foo'); // ... STRING
	// $apns->addMessageCustom('acme3', array('bang', 'whiz')); // OR ARRAY
	// $apns->queueMessage();
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
	// $apns = new APNS($db);
	// $apns->newMessage(1, '2010-01-01 00:00:00');
	// $apns->addMessageSound('bingbong.aiff'); // STRING OF FILE NAME
	// $apns->queueMessage();
	// </code>

	// Method: addMessageSound() - Add Message Sound
	// @param string $sound Name of sound file in your Resources Directory
	// @access public
	public function addMessageSound($sound=NULL){
		if(!$this->push_message) $this->_triggerError('ERROR: Must use newMessage() before calling this method.', E_USER_ERROR);
		if($sound) {
			if(isset($this->push_message['aps']['sound'])) $this->_triggerError('NOTICE: Message Sound has already been created. Overwriting with '.$sound.'.');
			$this->push_message['aps']['sound'] = (string)$sound;
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
		echo "productionCertificate: ".$this->productionCertificate."<br/>";
		echo "productionPassphrase: ".$this->productionPassphrase."<br/>";
		echo "productionSsl: ".$this->productionSsl."<br/>";
		echo "productionFeedback: ".$this->productionFeedback."<br/>";
		echo "sandboxCertificate: ".$this->sandboxCertificate."<br/>";
		echo "sandboxPassphrase: ".$this->sandboxPassphrase."<br/>";
		echo "sandboxSsl: ".$this->sandboxSsl."<br/>";
		echo "sandboxFeedback: ".$this->sandboxFeedback."<br/>";
		echo "push_message: ".$this->push_message."<br/>";
	}
}
/*
$test_push = new appApplePushAPI();
$test_push->testInitializationValues();
*/
?>