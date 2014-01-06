<?php
/**
 * SimpleProxy aims to be just that: a simple proxy. The initial use case of this
 * class was for easier handling of AJAX cross-domain CORS requests as Safari
 * is still buggy in that department and not setting global domain headers.
 *
 * Any JSON errors are returned with the following:
 * { error: true, errorCode: 'string', errorText: 'string', statusCode: int }
 *
 * If returning errors in HTML, we simply set the http status code appropriately
 * and return an H1 tag containing the error message.
 *
 * Original credit goes out to Ben Alman and his php-simple-proxy script.
 * It provided a solid framework for us to extend and build upon. You can
 * check out the original script here: http://benalman.com/projects/php-simple-proxy/
 *
 * @author      Corey Ballou <http://www.craftblue.com>
 * @copyright   2012 CO Internet S.A.S.
 * @link        http://cointernet.co
 */
class SimpleProxy {

    /**
     * The base API url to call.
     *
     * @var string
     */
    protected $_apiBaseUrl;

    /**
     * The type of response. Can be one of: json, jsonp, or native
     *
     * @var string
     */
    protected $_responseType;

    /**
     * Valid URL regex to be used for validating JSONP and native.
     *
     * @var string
     */
    protected $_validRegex = '/.*/';

    /**
     * Default constructor. Takes configuration options.
     *
     * @access  public
     * @param   string  $api
     * @return  void
     */
    public function __construct($apiBaseUrl, $responseType = 'json', $validRegex = '/.*/')
    {
        if (empty($apiBaseUrl)) {
            throw new Exception('You must provide the base API url.');
        }

        $this->_apiBaseUrl = $apiBaseUrl;
        $this->_responseType = $responseType;
        $this->_validRegex = $validRegex;
    }

    /**
     * Handle proxying a request.
     *
     * @access  public
     * @param   string  $url        The restful URL that follows the baseUrl
     * @param   bool    $cookies    Whether to send cookies
     * @param   bool    $session    Whether to attempt to share the PHP session
     */
    public function request($url, $cookies = FALSE, $session = FALSE)
    {
        $returnData = array();

        // determine if XHR
        $isXHR = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

        // first validate the request endpoint
        if (!preg_match($this->_validRegex, $url)) {
            $returnData = $this->_handleError('badrequest', 'Invalid API request URL.', 405);
        } else {

            // add base url to endpoint url
            $url = $this->_apiBaseUrl . $url;

            // determine if we need to handle GET request
            if (!empty($_GET)) {
                $params = array();
                foreach ($_GET as $k => $v) {
                    $params[] = $k . '=' . urlencode($v);
                }
                $url .= '?' . implode('&', $params);
            }

            $ch = curl_init($url);

            // set request method
            if (!empty($_SERVER['REQUEST_METHOD'])) {
                $method = strtoupper($_SERVER['REQUEST_METHOD']);
            } else {
                $method = 'GET';
            }

            // pass data based on the request method
            switch ($method) {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, TRUE);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($_POST) ? http_build_query($_POST): '');
                    break;

                case 'PUT':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($_POST) ? http_build_query($_POST): '');
                    break;

                case 'DELETE':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($_POST) ? http_build_query($_POST): '');
                    break;

                case 'HEAD':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
                    break;

                case 'GET':
                default:
                    break;
            }

            // check for cookies
            if ($cookies === TRUE && !empty($_COOKIE)) {
                $cookies = array();
                foreach ($_COOKIE as $k => $v) {
                    $cookies[] = $k . '=' . urlencode($v);
                }

                if ($session && SID) {
                    $cookies[] = SID;
                }

                curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookies) . ';');
            }

            // grab the user agent
            $user_agent = 'SimpleProxy - Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.57 Safari/537.1';
            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                $user_agent = 'SimpleProxy - ' . $_SERVER['HTTP_USER_AGENT'];
            }

            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

            // simulate XHR if we did in fact come from an AJAX request
            // http://stackoverflow.com/questions/5972443/php-simulate-xhr-using-curl
            if ($isXHR) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    "Accept" => "application/json, text/javascript, */*; q=0.01",
                    "Accept-Language" => "en-us,en;q=0.5",
                    "Accept-Encoding" => "gzip, deflate",
                    "Accept-Charset" => "ISO-8859-1,utf-8;q=0.7,*;q=0.7",
                    "X-Requested-With" => "XMLHttpRequest",
                ));
            }

            // ugly SSL override
            if (strpos($this->_apiBaseUrl, 'https') !== FALSE) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            }

            // retrieve and split the header and contents
            $response = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // appropriately handle response and any possible errors
            if ($response === FALSE) {
                $returnData = $this->_handleError('badrequest', 'Invalid API request.', 500);
            } else if ($status_code == 404) {
                $returnData = $this->_handleError('badrequest', 'API endpoint does not exist.', 404);
            } else if ($status_code == 500) {
                $returnData = $this->_handleError('badrequest', 'An unrecoverable error has occurred.', 500);
            } else if ($status_code > 200) {
                $returnData = $this->_handleError('badrequest', 'An error has occurred.', $status_code);
            } else {
                // split response into headers and data
                list($header, $returnData) = preg_split('/([\r\n][\r\n])\\1/', $response, 2);

                // cleanup
                unset($response);

                // deal with the response headers
                if (!empty($header)) {
                    // split headers by line
                    $headers = preg_split('/[\r\n]+/', $header);

                    // if we're responding with native headers matching the server response
                    if ($this->_responseType == 'native') {
                        // set appropriate headers
                        foreach ($headers as $header) {
                            if (preg_match('/^(?:Content-Type|Content-Language|Set-Cookie):/i', $header)) {
                                header($header);
                            }
                        }
                    }

                    // if handling JSON response and we want to propagate cookies
                    else if ($cookies) {
                        foreach ($headers as $header) {
                            if (preg_match('/^(?:Set-Cookie):/i', $header)) {
                                header($header);
                            }
                        }
                    }
                }
            }
        }

        // get the JSONP callback
        $jsonp_callback =
            $this->_responseType == 'jsonp' && isset($_GET['callback']) ? $_GET['callback'] : NULL;

        // generate appropriate response content type
        if ($this->_responseType != 'native') {
            header('Content-Type: application/' . ($isXHR ? 'json' : 'x-javascript'));

            // Generate JSON/JSONP string
            $data = json_encode($returnData);
            $data = json_last_error() == JSON_ERROR_NONE ? $returnData : $data;

            echo $jsonp_callback ? "$jsonp_callback($data)" : $data;
        } else {
            echo $returnData;
        }

        die;
    }

    /**
     * Handles an error.
     *
     * @access  public
     * @return  void
     */
    protected function _handleError($type, $message, $statusCode)
    {
        $returnData = array();

        // handle error based on response type
        if ($this->_responseType == 'native') {
            $returnData = '<h1>' . $message . '</h1>';
            if (!empty($statusCode)) {
                $this->_setStatusCode($statusCode);
            }
        } else {
            $returnData['error'] = true;
            $returnData['errorCode'] = $type;
            $returnData['errorText'] = $message;

            if (!empty($statusCode)) {
                $returnData['statusCode'] = $statusCode;
            }
        }

        return $returnData;
    }

    /**
     * Handles setting the custom header status code with a whitelist of
     * available status codes.
     *
     * @access  public
     * @param   int     $code
     * @return  void
     */
    protected function _setStatusCode($code)
    {
        $str = '';

        switch ($code) {
            case 100:
                $str = '100 Continue';
                break;
            case 200:
                $str = '200 Ok';
                break;
            case 301:
                $str = '301 Moved Permanently';
                break;
            case 302:
                $str = '302 Found';
                break;
            case 400:
                $str = '400 Bad Request';
                break;
            case 401:
                $str = '401 Unauthorized';
                break;
            case 403:
                $str = '403 Forbidden';
                break;
            case 404:
                $str = '404 Not Found';
                break;
            case 405:
                $str = '405 Method Not Allowed';
                break;
            case 407:
                $str = '407 Proxy Authentication Required';
                break;
            case 408:
                $str = '408 Request Timeout';
                break;
            case 410:
                $str = '410 Gone';
                break;
            case 420:
                $str = '420 Enhance Your Calm';
                break;
            case 429:
                $str = '429 Too Many Requests';
                break;
            case 500:
                $str = '500 Internal Server Error';
                break;
            case 501:
                $str = '501 Not Implemented';
                break;
            case 502:
                $str = '502 Bad Gateway';
                break;
            case 503:
                $str = '503 Service Unavailable';
                break;
            case 504:
                $str = '504 Gateway Timeout';
                break;
            case 509:
                $str = '509 Bandwidth Limit Exceeded';
                break;
            default:
                $str = '200 Ok';
                break;
        }

        if (isset($_SERVER['SERVER_PROTOCOL'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $str);
        } else {
            header('HTTP/1.0 ' . $str);
        }
    }
}
