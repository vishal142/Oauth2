<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once('src/OAuth2/Autoloader.php');
require(APPPATH.'/libraries/REST_Controller.php');


class Token extends REST_Controller
{

	  function __construct() {
    parent::__construct();
      
      $dsn = 'mysql:dbname='.$this->config->item('oauth_db_database').';host='.$this->config->item('oauth_db_host');
        $dbusername = $this->config->item('oauth_db_username');
        $dbpassword = $this->config->item('oauth_db_password');

        OAuth2\Autoloader::register();

          //print_r($dsn);exit;
      $storage = new OAuth2\Storage\Pdo(array('dsn' => $dsn, 'username' => $dbusername, 'password' => $dbpassword));
       //print_r($storage); exit;

      //Pass a storage object or array of storage objects to the OAuth2 server class
        $this->oauth_server = new OAuth2\Server($storage);


     // Add the "Client Credentials" grant type (it is the simplest of the grant types)
        $this->oauth_server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));



        // Add the "Authorization Code" grant type (this is where the oauth magic happens)
        $this->oauth_server->addGrantType(new OAuth2\GrantType\AuthorizationCode($storage));

           }


	 function token_post() {
    // Handle a request for an OAuth2.0 Access Token and send the response to the client
        
        $this->oauth_server->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
    }
}
