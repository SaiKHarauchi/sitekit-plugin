<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\SiteKit42\Service;

use Exception;
use Google\Auth\HttpHandler\HttpClientCache;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Auth\OAuth2;
use Google_Client;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Extended Google API client with custom functionality for Site Kit.
 *
 * @since 1.2.0
 * @ignore
 */
class Google_Site_Kit_Client extends Google_Client
{
    const CLIENT_NAME = 'ec-cube/google-site-kit/0.1.0';

    /**
     * Callback to pass a potential exception to while refreshing an access token.
     *
     * @since 1.2.0
     *
     * @var callable|null
     */
    protected $token_exception_callback;

    /**
     * Construct the Google client.
     *
     * @since 1.2.0
     *
     * @param array $config Client configuration.
     */
    public function __construct(array $config = [])
    {
        if (isset($config['token_exception_callback'])) {
            $this->setTokenExceptionCallback($config['token_exception_callback']);
        }

        unset($config['token_exception_callback']);

        parent::__construct($config);
    }

    /**
     * Sets the function to be called when fetching an access token results in an exception.
     *
     * @since 1.2.0
     *
     * @param callable $exception_callback Function accepting an exception as single parameter.
     */
    public function setTokenExceptionCallback(callable $exception_callback)
    {
        $this->token_exception_callback = $exception_callback;
    }

    /**
     * Sets whether or not to return raw requests and returns a callback to reset to the previous value.
     *
     * @since 1.2.0
     *
     * @param bool $defer Whether or not to return raw requests.
     *
     * @return callable Callback function that resets to the original $defer value.
     */
    public function withDefer($defer)
    {
        $orig_defer = $this->shouldDefer();
        $this->setDefer($defer);

        // Return a function to restore the original refer value.
        return function () use ($orig_defer) {
            $this->setDefer($orig_defer);
        };
    }

    /**
     * Adds auth listeners to the HTTP client based on the credentials set in the Google API Client object.
     *
     * @since 1.2.0
     *
     * @param ClientInterface $http The HTTP client object.
     *
     * @return ClientInterface The HTTP client object.
     *
     * @throws Exception Thrown when fetching a new access token via refresh token on-the-fly fails.
     */
    public function authorize(ClientInterface $http = null)
    {
        if ($this->isUsingApplicationDefaultCredentials()) {
            return parent::authorize($http);
        }

        $token = $this->getAccessToken();
        if (isset($token['refresh_token']) && $this->isAccessTokenExpired()) {
            $callback = $this->getConfig('token_callback');

            try {
                $creds = $this->fetchAccessTokenWithRefreshToken($token['refresh_token']);
                if ($callback) {
                    // Due to original callback signature this can only accept the token itself.
                    call_user_func($callback, '', $creds['access_token']);
                }
            } catch (Exception $e) {
                // Pass exception to special callback if provided.
                if ($this->token_exception_callback) {
                    call_user_func($this->token_exception_callback, $e);
                }
                throw $e;
            }
        }

        return parent::authorize($http);
    }

    /**
     * Fetches an OAuth 2.0 access token by using a temporary code.
     *
     * @param string $code Temporary authorization code, or undelegated token code.
     *
     * @return array Access token.
     *
     * @throws Exception
     * @since 1.2.0 Ported from Google_Site_Kit_Proxy_Client.
     *
     * @since 1.0.0
     */
    public function fetchAccessTokenWithAuthCode($code,$codeVerifier = null)
    {
        if (strlen($code) === 0) {
            throw new InvalidArgumentException('Invalid code');
        }

        $auth = $this->getOAuth2Service();
        $auth->setCode($code);
        $auth->setRedirectUri($this->getRedirectUri());

        $http_handler = HttpHandlerFactory::build($this->getHttpClient());

        $creds = $this->fetchAuthToken($auth, $http_handler);
        if ($creds && isset($creds['access_token'])) {
            $creds['created'] = time();
            $this->setAccessToken($creds);
        }

        return $creds;
    }

    /**
     * Fetches a fresh OAuth 2.0 access token by using a refresh token.
     *
     * @param string $refresh_token Optional. Refresh token. Unused here.
     *
     * @return array Access token.
     *
     * @throws Exception
     *
     * @since 1.2.0 Ported from Google_Site_Kit_Proxy_Client.
     * @since 1.0.0
     */
    public function fetchAccessTokenWithRefreshToken($refresh_token = null)
    {
        if (null === $refresh_token) {
            $refresh_token = $this->getRefreshToken();
            if (!$refresh_token) {
                throw new LogicException('refresh token must be passed in or set as part of setAccessToken');
            }
        }

        $this->getLogger()->info('OAuth2 access token refresh');
        $auth = $this->getOAuth2Service();
        $auth->setRefreshToken($refresh_token);

        $http_handler = HttpHandlerFactory::build($this->getHttpClient());

        $creds = $this->fetchAuthToken($auth, $http_handler);
        if ($creds && isset($creds['access_token'])) {
            $creds['created'] = time();
            if (!isset($creds['refresh_token'])) {
                $creds['refresh_token'] = $refresh_token;
            }
            $this->setAccessToken($creds);
        }

        return $creds;
    }

    protected function createDefaultHttpClient()
    {
        $options = [
            'exceptions' => false,
            'base_uri' => $this->getConfig('base_path'),
            'headers' => [
                'User-Agent' => self::CLIENT_NAME,
            ]
        ];
        return new Client($options);
    }

    /**
     * Fetches an OAuth 2.0 access token using a given auth object and HTTP handler.
     *
     * This method is used in place of {@see OAuth2::fetchAuthToken()}.
     *
     * @param OAuth2 $auth OAuth2 instance.
     * @param callable|null $http_handler Optional. HTTP handler callback. Default null.
     *
     * @return array Access token.
     *
     * @throws Exception
     *
     * @since 1.0.0
     * @since 1.2.0 Ported from Google_Site_Kit_Proxy_Client.
     */
    protected function fetchAuthToken(OAuth2 $auth, callable $http_handler = null)
    {
        if (is_null($http_handler)) {
            $http_handler = HttpHandlerFactory::build(HttpClientCache::getHttpClient());
        }

        $request = $auth->generateCredentialsRequest();
        $response = $http_handler($request);
        $credentials = $auth->parseTokenResponse($response);
        if (!empty($credentials['error'])) {
            $this->handleAuthTokenErrorResponse($credentials['error'], $credentials);
        }

        $auth->updateToken($credentials);

        return $credentials;
    }

    /**
     * Handles an erroneous response from a request to fetch an auth token.
     *
     * @since 1.2.0
     *
     * @param string $error Error code / error message.
     * @param array  $data  Associative array of full response data.
     *
     */
    protected function handleAuthTokenErrorResponse($error, array $data)
    {
        throw new RuntimeException($error.json_encode($data));
    }
}
