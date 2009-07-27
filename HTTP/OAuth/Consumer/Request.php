<?php
/**
 * HTTP_OAuth
 *
 * Implementation of the OAuth specification
 *
 * PHP version 5.2.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php. If you did not receive
 * a copy of the New BSD License and are unable to obtain it through the web,
 * please send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category  HTTP
 * @package   HTTP_OAuth
 * @author    Jeff Hodsdon <jeffhodsdon@gmail.com>
 * @copyright 2009 Jeff Hodsdon <jeffhodsdon@gmail.com>
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @link      http://pear.php.net/package/HTTP_OAuth
 * @link      http://github.com/jeffhodsdon/HTTP_OAuth
 */

require_once 'Validate.php';
require_once 'HTTP/OAuth/Message.php';
require_once 'HTTP/OAuth/Consumer/Response.php';
require_once 'HTTP/OAuth/Signature.php';
require_once 'HTTP/OAuth/Exception.php';

/**
 * HTTP_OAuth_Consumer_Request
 *
 * Class to make OAuth requests to a provider.  Given a url, consumer secret,
 * token secret, and HTTP method make and sign a request to send.
 *
 * @category  HTTP
 * @package   HTTP_OAuth
 * @author    Jeff Hodsdon <jeffhodsdon@gmail.com>
 * @copyright 2009 Jeff Hodsdon <jeffhodsdon@gmail.com>
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @link      http://pear.php.net/package/HTTP_OAuth
 * @link      http://github.com/jeffhodsdon/HTTP_OAuth
 */
class HTTP_OAuth_Consumer_Request extends HTTP_OAuth_Message
{

    /**
     *  Auth type constants
     */
    const AUTH_HEADER = 1;
    const AUTH_POST   = 2;
    const AUTH_GET    = 3;

    /**
     * Auth type
     *
     * @var int $authType Authorization type
     */
    protected $authType = self::AUTH_HEADER;

    /**
     * Url
     *
     * @var string $url Url to request
     */
    protected $url = null;

    /**
     * HTTP Method
     *
     * @var string $message HTTP method to use
     */
    protected $method = null;

    /**
     * Secrets
     *
     * Consumer and token secrets that will be used to sign
     * the request
     *
     * @var array $secrets Array of consumer and token secret
     */
    protected $secrets = array();

    /**
     * Construct
     *
     * Sets url, secrets, and http method
     *
     * @param string $url     Url to be requested
     * @param array  $secrets Array of consumer and token secret
     * @param string $method  HTTP method
     *
     * @return void
     */
    public function __construct($url, array $secrets, $method = 'POST')
    {
        $this->setUrl($url);
        $this->method  = $method;
        $this->secrets = $secrets;
    }

    /**
     * Sets a url
     *
     * @param string $url Url to request
     *
     * @return void
     * @throws HTTP_OAuth_Exception on invalid url
     */
    public function setUrl($url)
    {
        if (!Validate::uri($url)) {
            throw new HTTP_OAuth_Exception("Invalid url: $url");
        }

        $this->url = $url;
    }

    /**
     * Gets secrets
     *
     * @return array Secrets array
     */
    public function getSecrets()
    {
        return $this->secrets;
    }

    /**
     * Sets authentication type
     *
     * Valid auth types are self::AUTH_HEADER, self::AUTH_POST,
     * and self::AUTH_GET
     *
     * @param int $type Auth type defined by this class constants
     *
     * @return void
     */
    public function setAuthType($type)
    {
        $this->authType = $type;
    }

    /**
     * Gets authentication type
     *
     * @return int Set auth type
     */
    public function getAuthType()
    {
        return $this->authType;
    }

    /**
     * Sends request
     *
     * Builds and sends the request. This will sign the request with
     * the given secrets at self::$secrets.
     *
     * @return HTTP_OAuth_Consumer_Response Response instance
     * @throws HTTP_OAuth_Exception when request fails
     */
    public function send()
    {
        $request = $this->buildRequest();
        try {
            $response = $request->send();
        } catch (Exception $e) {
            throw new HTTP_OAuth_Exception($request->getResponseInfo('error'));
        }

        return new HTTP_OAuth_Consumer_Response($response);
    }

    /**
     * Builds request for sending
     *
     * Adds timestamp, nonce, signs, and creates the HttpRequest object.
     *
     * @return HttpRequest Instance of the request object ready to send()
     */
    protected function buildRequest()
    {
        $sig = HTTP_OAuth_Signature::factory($this->getSignatureMethod());

        $this->oauth_timestamp = time();
        $this->oauth_nonce     = md5(microtime(true) . rand(1, 999));
        $this->oauth_version   = '1.0';
        $this->oauth_signature = $sig->build($this->getRequestMethod(),
            $this->getUrl(), $this->getParameters(), $this->secrets[0],
            $this->secrets[1]);

        $method = HttpRequest::METH_POST;
        if ($this->method == 'GET') {
            $method = HttpRequest::METH_GET;
        }

        $request = new HttpRequest($this->url, $method);
        $request->addHeaders(array('Expect' => ''));
        $params = $this->getOAuthParameters();
        switch ($this->getAuthType()) {
        case self::AUTH_HEADER:
            $auth = $this->getAuthForHeader($params);
            $request->addHeaders(array('Authorization' => $auth));
            break;
        case self::AUTH_POST:
            $request->addPostData(HTTP_OAuth::urlencode($params));
            break;
        case self::AUTH_GET:
            $request->addQueryData(HTTP_OAuth::urlencode($params));
            break;
        default:
            throw new HTTP_OAuth_Exception;
            break;
        }

        return $request;
    }

    /**
     * Creates OAuth header
     *
     * Given the passed in OAuth parameters, put them together
     * in a formated string for a Authorization header.
     *
     * @param array $params OAuth parameters
     *
     * @return void
     */
    protected function getAuthForHeader(array $params)
    {
        $url    = parse_url($this->url);
        $realm  = $url['scheme'] . '://' . $url['host'] . '/';
        $header = 'OAuth realm="' . $realm . '"';
        foreach ($params as $name => $value) {
            $header .= ", " . HTTP_OAuth::urlencode($name) . '="' .
                HTTP_OAuth::urlencode($value) . '"';
        }

        return $header;
    }

    /**
     * Gets request method
     *
     * @return string HTTP request method
     */
    public function getRequestMethod()
    {
        return $this->method;
    }

    /**
     * Gets url
     *
     * @return string Url to request
     */
    public function getUrl()
    {
        return $this->url;
    }

}

?>
