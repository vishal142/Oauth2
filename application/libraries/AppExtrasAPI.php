<?php
class appExtrasAPI {
    protected $mysqli;
	protected $response_format;
	protected $header_content_type;
 
    // Constructor - open DB connection
    function __construct() {
       
    	
		$this->response_format = isset($_POST['format'])?(strtolower($_POST['format']) == 'xml'?'xml':'json'):'json'; //json is the default
		// application/json OR text/xml OR text/html (default) */
		$this->header_content_type = 'text/html; charset=utf-8';

		$db_data = parse_ini_file('/var/www/html/apis/resource/db.ini', true);
            
		$mode = $db_data['Use_Mode']['Mode'];
		
		$DB_Server = $db_data[$mode]['DB_Server'];
		$DB_Username = $db_data[$mode]['DB_Username'];
		$DB_Password = $db_data[$mode]['DB_Password'];
		$DB_DBName = $db_data[$mode]['DB_DBName'];
	
       $this->mysqli = new mysqli($DB_Server, $DB_Username, $DB_Password, $DB_DBName);
        //if ($this->mysqli->connect_errno) {
            //echo "Failed to connect to MySQL: (" . $this->mysqli->connect_errno . ") " . $this->mysqli->connect_error;
        //}else{
           // echo $this->mysqli->host_info . "\n";
        //}
	$this->mysqli->set_charset("utf8");
        $this->mysqli->autocommit(FALSE);

    }
 
    // Destructor - close DB connection
    function __destruct() {
        $this->mysqli->close();
    }
	
	// output in necessary format
	// Method: formatResponse() - [Protected Method]
	protected function formatResponse($posts) {
		$publish_information = array("publish"=>array("api_version"=>"1.0.0","developer"=>"www.massoftind.com"));
		if($this->response_format == 'json') 
		{
			$this->header_content_type = 'application/json; charset=utf-8';
			/* header('Content-type: application/json'); */
			echo json_encode(array('raws'=>array_merge($posts, $publish_information)));
		}
		else 
		{
			$this->header_content_type = 'text/xml';
			/* header('Content-type: text/xml'); */
			echo '<?xml version="1.0" encoding="utf-8"?>';
			echo '<raws>';
			foreach($posts as $index => $post) 
			{
				echo '<'.$index.'>';
				if(is_array($post)) 
				{
					foreach($post as $key => $value) 
					{
						$key_name = is_int($key) ? 'row' : $key;
						echo '<',$key_name,'>';
						$this->generateXML($value);
						echo '</',$key_name,'>';
					}
				} else {
					echo htmlentities($post);
				}
				echo '</'.$index.'>';
			}
			echo '<publish><api_version>1.0.0</api_version><developer>www.massoftind.com</developer></publish>';
			echo '</raws>';
		}
	}
	
	// Helper method to generate XML
	private function generateXML($post)
	{
		if(is_array($post)) 
		{
			foreach($post as $key => $value)
			{
				$key_name = is_int($key) ? 'row' : $key;
				echo '<',$key_name,'>';
				$this->generateXML($value);
				echo '</',$key_name,'>';
			}
		} else {
			echo htmlentities($post);
		}
	}

	// Helper method to get a string description for an HTTP status code
	private function getStatusCodeMessage($status)
	{
		$codes = parse_ini_file('../resource/http_response.ini', true);
		return (isset($codes[$status])) ? $codes[$status] : '';
	}

	// Helper method to send a HTTP response code/message
	// function sendResponse() [Protected Method]
	protected function sendResponse($status = 200, $body = '', $content_type = 'text/html; charset=utf-8')
	{
		if (func_num_args() == 3) {
			$this->header_content_type = $content_type;
		}

		$status_header = 'HTTP/1.1 ' . $status . ' ' . $this->getStatusCodeMessage($status);
		header($status_header);
		header('Content-type: ' . $this->header_content_type);
		echo $body;
	}

	//Method: isLoggedIn() - Check If User Is Logged In [Protected Method]
	protected function isLoggedIn($prm_user_id, $prm_pass_key) {
		$user_id = intval($prm_user_id);
		$pass_key = $prm_pass_key;
		
		$query = "SELECT `user_id` FROM `tbl_user_keys` WHERE `user_id` = ".$user_id." AND `id` LIKE '".$pass_key."';";
		$result = $this->mysqli->query($query);
		$row_count = $result->num_rows;
		$result->free();
		
		if ($row_count == 0) {
			return false;
		} else {
			return true;
		}
	}
	
	
	// Method: fetch_push_library() - Get Info on Whether the Push should be sent to iOS or Android for an User
	function fetch_push_library($push_receiver_user_id,$push_device_id) {
           
		$device_os = '';
		$query = "SELECT `device_os` FROM `tbl_user_mobile_devices` WHERE  device_uid='".$push_device_id."'";
		
                $result = $this->mysqli->query($query);
		$row = $result->fetch_array(MYSQLI_ASSOC);
                //$row=mysqli_fetch_array($result,MYSQLI_ASSOC);
		$device_os = $row['device_os'];
		//$result->free();
                		
               
		return $device_os;
	}
	
	// Method: fetch_push_settings() - Get Push Settings for an User
	function fetch_push_settings($user_id) {
            
            
		$query = "SELECT * FROM `tbl_member_push_settings` WHERE `id` ='".$user_id."'";
		
                $result = $this->mysqli->query($query);
		$row = $result->fetch_array(MYSQLI_ASSOC);
		$result->free();
               
		return $row;
               
	}
        
	// Method: canSendPushToUser($user_id) - Check If User is Logged In and Has An Active Device Registration [Private Method]
	public function canSendPushToUser($user_id) {
             
        $query = "SELECT `fk_user_id` FROM `tbl_user_loginkeys` lgn INNER JOIN `tbl_user_mobile_devices` mdv  ON `lgn`.`fk_user_mobile_device_id`=`mdv`.`id` WHERE `mdv`.`status`='active' AND  `lgn`.`fk_user_id` ='".$user_id."' ";
        
		$result = $this->mysqli->query($query);
		$device_ready = ($result->num_rows == 1) ?1:0;
              
        if ($device_ready==1)
        {
            $result->free();
        }

        if ($device_ready==1)  {
			return true;
		} else {
			return false;
		}
	}

	// Method: isUserActive($user_id) - Check If the User is signed in and active [Private Method]
	private function isUserActive($user_id) {
		$query = "SELECT `id` FROM `tbl_member_loginkeys` WHERE `fk_member_id`=".$user_id.";";
		$result = $this->mysqli->query($query);
		$num_rows = intval($result->num_rows);
		$result->free();
		if ($num_rows == 0) {
			return false;
		} else {
			return true;
		}
	}

	// Method: getSoundForAndroid($receiver_id) - Get Sound For Android Push [Private Method]
	private function getSoundForAndroid($receiver_id) {
		$push_settings = $this->fetch_push_settings($receiver_id);
		if ($push_settings['push_sound'] == '1') {
			if ($push_settings['sound_name'] == NULL) {
				$sound = "~sound~:~default~,";
			} else {
				$sound = "~sound~:~".$push_settings['sound_name']."~,";
			}
		} else {
			$sound = "";
		}
		return $sound;
	}


	// ## Sending Push ##
	// ------------------------
	// Method: sendPush($push_receiver_id, $pushMessage) - [Private Method]
	public function sendPush($push_receiver_id, $push_device_id,$pushMessage, $pushType,$device_os,$alert_message='mPokket') {
           
		$push_settings = $this->fetch_push_settings($push_receiver_id,$push_device_id);
                
                //print_r($push_settings);
                
		if ($device_os=='iOS') {
                require_once('appApplePushAPI.php');
				$push_device = new appApplePushAPI();
				$push_device->newMessage($push_receiver_id,$push_device_id);
                                $push_device->addMessageAlert($alert_message);;
                                    $push_device->addMessageCustom('dataset', $pushMessage); 
                                     $push_device->addMessageCustom('push_type', $pushType); 
                                    $push_device->addMessageSound('default');
                                    $conetnt_availablt=1;
                                    $push_device->addMessageContentAvailable($conetnt_availablt);
                                    $push_device->queueMessage();
				// $push_device->InstaPushMessages($push_receiver_id);
				unset($push_device);
                        
		} else if ($device_os=='And') { 
                  
                     require_once('appAndroidGCMPushAPI.php');
                    
                    $push_device = new appAndroidGCMPushAPI();
                    
                    $push_device->newMessage($push_receiver_id,$push_device_id);
                    $push_device->addMessageAlert($pushMessage);
                    $push_device->addMessageSound('default');
                    $push_data = ",~push_type~:~".$pushType."~";
                    $push_device->addMessageAlert($pushMessage);
                    $push_device->addMessageCustom($push_data);
                    $push_device->queueMessage();
                    
                    // $push_device->InstaPushMessages($push_receiver_id);
                    unset($push_device);
                                    
			
		}
	}
        
        // Method: sendPush($push_receiver_id, $pushMessage) - [Private Method]
	public function sendPushDirect($push_receiver_id, $push_device_id,$pushMessage, $pushType,$device_os,$alert_message='mPokket') {
         
		//$push_settings = $this->fetch_push_settings($push_receiver_id,$push_device_id);
                
                //print_r($push_settings);
                
		if ($device_os=='iOS') {
               
                require_once('AppApplePushAPI.php');
				$push_device = new AppApplePushAPI();
				
				$push_device->newMessage($push_receiver_id,$push_device_id);
                $push_device->addMessageAlert($alert_message);;
                $push_device->addMessageCustom('dataset', $pushMessage); 
                $push_device->addMessageCustom('push_type', $pushType); 
                $push_device->addMessageSound('default');
                $conetnt_availablt=1;
                $push_device->addMessageContentAvailable($conetnt_availablt);
                $push_device->queueMessageDirect();
				// $push_device->InstaPushMessages($push_receiver_id);
				unset($push_device);
                        
		} else if ($device_os=='And') {
                  
                     require_once('AppAndroidGCMPushAPI.php');
                     $push_device = new appAndroidGCMPushAPI();
            //echo '<pre>'; print_r($push_device); exit;
            $push_device->newMessage($push_receiver_id,$push_device_id);
            $push_device->addMessageAlert($pushMessage);
            $push_device->addMessageSound('1');
            $push_device->addMessageVibrate('1');
            $push_device->addMessageNotificationMessage($alert_message);
            $push_device->addMessageNotificationTitle('mPokket');
            $push_device->addMessageNotificationImage('icon');

            $push_data = ",~push_type~:~".$pushType."~";
            //$push_device->addMessageAlert($pushMessage);
            $push_device->addMessageCustom($push_data);
            $push_status = $push_device->queueMessageDirect();                    
           // $push_device->InstaPushMessages($push_receiver_id);
            unset($push_device); 



                    /*
                    $push_device = new appAndroidGCMPushAPI();
                    
                    $push_device->newMessage($push_receiver_id,$push_device_id);
                    $push_device->addMessageAlert($pushMessage);
                    $push_device->addMessageSound('default');
                    $push_data = ",~push_type~:~".$pushType."~";
                    $push_device->addMessageAlert($pushMessage);
                    $push_device->addMessageCustom($push_data);
                    $push_device->queueMessageDirect();
                    
                    // $push_device->InstaPushMessages($push_receiver_id);
                    unset($push_device);*/
                                    
			
		}
	}
        
      

	// Method: badge_count_report() - [Private Method]
	private function badge_count_report($badge_receiver_user_id) {
		// Fetching Total Number of New Chat Messages
		$new_chat_messages = $this->get_new_chatmessage_count($badge_receiver_user_id);

		// Fetching Total Number of Unread Invitations
		$unread_invitations =$this->get_unread_invitations_count($badge_receiver_user_id);

		// Fetching Total Number of Unread Notifications
		$unread_notifications = $this->get_unread_notifications_count($badge_receiver_user_id);

		// Fetching Total Number of Unread Friend Requests
		$new_friend_requests = $this->get_unread_friend_requests_count($badge_receiver_user_id);
		
		// Total Badge Count
		$badge_count = $new_chat_messages + $unread_invitations + $unread_notifications + $new_friend_requests;

		
		// Total Count of System Notifications
		// $query = "SELECT COUNT(`id`) AS `notification_count` FROM `tbl_notifications` WHERE `id`=".$badge_receiver_user_id." AND `is_new`='1';";
		// $result = $this->mysqli->query($query);
		// $row = $result->fetch_array(MYSQLI_ASSOC);
		// $badge_count = $row['unread_notifications_count'];
		// $result->free();
		
		return $badge_count;
	}

	
	// Method: formatUnicodeEmoticons() - [Protected Method]
	protected function formatUnicodeEmoticons($message) {
		$arrEmoticon= array('\ue056','\ue415','\ue405','\ue058','\ue106','\ue404','\ue402','\ue057','\ue412','\ue416','\ue40b'); 
		$unicode_message = $message;
		for ($i = 0; $i < sizeof($arrEmoticon); $i++) {
			$unicode_char = str_replace("\\", "", $arrEmoticon[$i]);
			$unicode_message = str_replace($arrEmoticon[$i], json_decode('"\\'.$unicode_char.'"'), $unicode_message);
		}
		return $unicode_message;
	}

	// Method: formatUnicodeEmoticonsForIOS2iOSPush() - [Protected Method]
	protected function formatUnicodeEmoticonsForIOS2iOSPush($message) {
		$arrEmoticon= array('\ue056','\ue415','\ue405','\ue058','\ue106','\ue404','\ue402','\ue057','\ue412','\ue416','\ue40b'); 
		$unicode_message =  json_encode($message);
		for ($i = 0; $i < sizeof($arrEmoticon); $i++) {
			switch ($arrEmoticon[$i]) {
				case '\ue056':
				$unicode_char = "\\ue056";
				break;
				case '\ue057':
				$unicode_char = "\\ue057";
				break;
				case  '\ue058':
				$unicode_char = "\\ue058";
				break;
				case  '\ue40b':
				$unicode_char = "\\ue40b";
				break;
				case  '\ue106':
				$unicode_char = "\\ue106";
				break;
				case  '\ue402':
				$unicode_char = "\\ue402";
				break;
				case '\ue404':
				$unicode_char = "\\ue404";
				break;
				case  '\ue405':
				$unicode_char = "\\ue405";
				break;
				case  '\ue412':
				$unicode_char = "\\ue412";
				break;
				case '\ue415':
				$unicode_char = "\\ue415";
				break;
				case '\ue416':
				$unicode_char = "\\ue416";
				break;
				default:
			}
			
			$unicode_message = str_replace($arrEmoticon[$i], $unicode_char, $unicode_message);
		}
		$unicode_message = substr($unicode_message, 1, strlen($unicode_message)-2);
		return $unicode_message;
	}

	// Push Notification Related Section: End
	// -------------------------------------------
}
?>