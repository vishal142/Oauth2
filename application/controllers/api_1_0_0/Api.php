<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once('src/OAuth2/Autoloader.php');
require(APPPATH.'/libraries/REST_Controller.php');


class Api extends REST_Controller
{

	  public function __construct() {
               parent::__construct();

            // Auth2

            $dsn  = 'mysql:dbname=' . $this->config->item('oauth_db_database') . ';host=' . $this->config->item('oauth_db_host');
            $dbusername = $this->config->item('oauth_db_username');
            $dbpassword = $this->config->item('oauth_db_password');

            OAuth2\Autoloader::register();

            // $dsn is the Data Source Name for your database, for exmaple "mysql:dbname=my_oauth2_db;host=localhost"
            $storage = new OAuth2\Storage\Pdo(array(
            'dsn' => $dsn,
            'username' => $dbusername,
            'password' => $dbpassword
            ));

            // Pass a storage object or array of storage objects to the OAuth2 server class
            $this->oauth_server = new OAuth2\Server($storage);

            // Add the "Client Credentials" grant type (it is the simplest of the grant types)
            $this->oauth_server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));

            // Add the "Authorization Code" grant type (this is where the oauth magic happens)
            $this->oauth_server->addGrantType(new OAuth2\GrantType\AuthorizationCode($storage));

            // End Auth 2
           }


	 public function user_get(){
     $developer = array('maintenance By'=>'Unknown Tech');

       if(!$this->oauth_server->verifyResourceRequest(OAuth2\Request::createFromGlobals())){

        $r = array();
        $status = 'User not found';
        $responce = 'http_response_unauthorized';

      }else{
	 	   $r = array('0'=>'Unknown Tech','1'=>'Vishal Gupta','2'=>'Sagar Singh',
       		'3'=>'Brijesh Tiwari','4'=>'Sourav De','5'=>'Priya Singh'); #User Array fetch on Database
       $status = 'User found';
       $responce = 'http_response_ok';

       }

       $this->response(array(
                'raws' => $r,
                'status'=> $status,
                'developer' =>$developer
            ),$this->config->item($responce)
          );

      }
}
