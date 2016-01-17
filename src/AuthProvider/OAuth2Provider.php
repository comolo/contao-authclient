<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2014 Leo Feyer
 *
 * @package   AuthClient
 * @author    Hendrik Obermayer - Comolo GmbH
 * @license   -
 * @copyright 2014 Hendrik Obermayer
 */


/**
 * Namespace
 */
namespace Comolo\SuperLoginClient\ContaoEdition\AuthProvider;

use Comolo\SuperLoginClient\ContaoEdition\Model\SuperLoginServerModel;
use League\OAuth2\Client\Provider\GenericProvider as OAuth2GenericProvider;

/**
 * Class ClcAuthProvider
 *
 * @copyright  2015 Hendrik Obermayer
 * @author     Hendrik Obermayer - Comolo GmbH
 * @package    Devtools
 */
class OAuth2Provider extends AuthProvider
{
    /**
     * initialize CLC Client
     * @return ClcClient
     */
    protected function getClcClient()
    {
        // Get public key from certificate
        $resPubKey = openssl_pkey_get_public($this->getServerKey());
        $pubKeyArr = openssl_pkey_get_details($resPubKey);

        $client = new ClcClient($this->server_address, $this->public_id, $pubKeyArr['key']);
        $client->setVersion($GLOBALS['AUTH_CLIENT']['version']);

        return $client;
    }

    /**
     * redirect to CLC Server
     */
    public function runRequest()
    {
        $client = $this->getClcClient();
        $client->setClientUrl($this->getReturnUrl());

        $requestUrl = $client->generateRequestUrl();

        // Save timestamp to session
        $genTimestamp = $client->getGenerationTimestamp();
        $_SESSION['clc_gen_timestamp'] = $genTimestamp;

        // Forward
        header("Location: " . $requestUrl);
        exit;
    }

    /**
     * check the response of the clc server
     * trigger login if response in valid
     *
     * @return bool|mixed
     * @throws \Exception
     */
    public function checkResponse()
    {
        $requestHash = urldecode(\Input::post('rqh'));
        $responseHash = urldecode(\Input::post('rsh'));
        $responseData = urldecode(\Input::post('rdata'));

        $generationTime = (isset($_SESSION['clc_gen_timestamp'])) ? $_SESSION['clc_gen_timestamp'] : false;

        if ($requestHash && $responseHash && $generationTime)
        {
            $client = $this->getClcClient();

            if (
                true === $client->checkRequestHash($requestHash, $generationTime) &&
                true === $client->checkResponseHash($responseHash, $requestHash, $responseData)
            ) {
                return unserialize($responseData);
            }
            else {
                throw new \Exception('Invalid response data!');
                return false;
            }
        }
    }

    /**
     * called when the backend form was saved
     * check certificate and write it to the database
     *
     * @param $dc
     * @return bool
     * @throws \Exception
     */
    public function onSubmitDcForm($dc)
    {
        if ($dc->activeRecord->server_key != '')
        {
            $keyPath = $dc->activeRecord->server_key;

            if (!is_array($dc->activeRecord->server_key)) {

                // check if serialized
                if (substr(trim($keyPath), 0, 1) != 'a') {
                    return;
                }

                // unserialize
                $keyPath = unserialize($keyPath);
            }

            if(empty($keyPath[0])) {
                throw new \Exception('Couldn´t get cert file path!');
            }

            $keyPath = trim(TL_ROOT . '/' . $keyPath[0]);

            $strKey = file_get_contents($keyPath);
            unlink($keyPath);

            $authServer = AuthClientServerModel::findById($dc->activeRecord->id);
            $authServer->server_key = $strKey;

            // OpenSSL
            $arrCert = @openssl_x509_parse($strKey);
            if(is_array($arrCert)) {
                $certName = $arrCert['subject']['O'];

                if(isset($arrCert['subject']['OU']) &&  $arrCert['subject']['OU'] != '') {
                    $certName .= ' | ' . $arrCert['subject']['OU'];
                }

                $authServer->name = $certName;
                $authServer->validTo = $arrCert['validTo_time_t'];

                // URI
                if(str_replace('URI:', '', $arrCert['subject']['CN']) !== $arrCert['subject']['CN']) {
                    $authServer->server_address = str_replace('URI:', '', $arrCert['subject']['CN']);
                }
                else {
                    throw new \Exception('No URI in Certificate CN given.');
                }

            }
            else {
                throw new \Exception('Error reading cert file!');
            }


            // Request Public id
            $authServer->public_id = $this->requestPublicId(
                $authServer->server_address,
                $arrCert['extensions']['subjectKeyIdentifier'],
                $_SERVER['SERVER_NAME'],
                isset($GLOBALS['TL_CONFIG']['websiteTitle'])
                    ? $GLOBALS['TL_CONFIG']['websiteTitle']
                    : $_SERVER['SERVER_NAME']
            );

            // Save auth server model
            $authServer->save();
        }
        else {
            throw new \Exception('Certification file is empty!');
        }
    }

    /**
     * Display information about the certificate
     *
     * @param $value
     * @param $dc
     * @return string
     */
    public function getAuthServerInfo($value, $dc) {
        $authServer = AuthClientServerModel::findById($dc->activeRecord->id);
        $arrInfo = array();

        if($authServer->server_key != '') {
            $arrCert = @openssl_x509_parse($authServer->server_key);
            $arrInfo[] = $arrCert['extensions']['subjectKeyIdentifier'];
            $arrInfo[] = $arrCert['subject']['O'] . ' | '.$arrCert['subject']['OU'];
            $arrInfo[] = $arrCert['subject']['L'].' - '.$arrCert['subject']['ST'].' - '.$arrCert['subject']['C'];
            $arrInfo[] = $arrCert['subject']['CN'];
            $arrInfo[] = 'VALID:'.date('d-m-Y', (int) $authServer->validTo);
        }

        return implode("\n", $arrInfo);
    }

    /**
     * request a public id for this client
     *
     * @param $serverUrl string url of the superlogin server
     * @param $certificateHash hash hash of the server certificate
     * @param $domain domain domain of this website
     * @param $websiteTitle title title of this website
     *
     * @return string public id
     * @throws \Exception could not get public id from server
     */
    protected function requestPublicId($serverUrl, $certificateHash, $domain, $websiteTitle) {

        // add register to server url
        $serverUrl .= '/register';

        // data to post
        $postData = array(
            'name' => $websiteTitle,
            'type' => $GLOBALS['AUTH_CLIENT']['type'], // contao
            'version' => $GLOBALS['AUTH_CLIENT']['version'],
            'certificateHash' => $certificateHash,
            'domain' => $domain
        );

        // send post data curl-less
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
            ),
        );

        $context  = stream_context_create($options);
        $result = file_get_contents($serverUrl, false, $context);

        // Check result
        if(substr($result, 0, 4) == "*ID:"
            && substr($result, -1, 1) == "*"
            && strlen($result) < 40)
        {
            return substr($result, 4, -1);
        }

        throw new \Exception("Could not get public id! Error: " . htmlentities($result));
    }
    
    /**
     * generate OAuth Authorization Url
     */
    public function generateAuthorizationUrl(SuperLoginServerModel $server)
    {
        $provider = new OAuth2GenericProvider([
            'clientId'                => $server->public_id,    // The client ID assigned to you by the provider
            'clientSecret'            => $server->secret,   // The client password assigned to you by the provider
            'redirectUri'             => 'http://example.com/your-redirect-url/',
            'urlAuthorize'            => $server->url_authorize,
            'urlAccessToken'          => $server->url_access_token,
            'urlResourceOwnerDetails' => $server->url_resource_owner_details,
        ]);

        return $provider->getAuthorizationUrl();
    }
}