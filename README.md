SimpleProxy aims to be just that: *a simple proxy*. The initial use case of this class was for easier handling of AJAX 
cross-domain CORS requests as Safari is still buggy in that department 
and was not setting global domain headers.

Any JSON encountered errors are returned with the following format:

```
{ error: true, errorCode: 'string', errorText: 'string', statusCode: int }
```

If returning errors in HTML, we simply set the http status code appropriately
and return an H1 tag containing the error message.

## Opinionated

We initially had no intentions of opening this bad boy up, so some of the decisions 
are fairly opinionated such as the handling of error messages being wrapped in
`H1` tags. These should be removed for future versions.

## Usage

```php
// the base path to your remote API
$apiBaseUrl = 'https://www.domain.co/api/';

// initialize the library
$sp = new SimpleProxy($apiBaseUrl);

// the relative path of the request URI to forward on
$endpoint = getenv('REQUEST_URI');

// proxy the request
// automatically handles echoing the return JSON(-P) data with appropriate headers
$sp->request($endpoint, $cookies = FALSE, $session = FALSE);
```

Note that the other two parameters passed to `request()` are `$cookies` and `$session`. 


#### `$cookies` ####

If set to `TRUE`, SimpleProxy will copy all cookies found in `$_COOKIE` and forward them on via `CURLOPT_COOKIE`. Likewise, when handling response data from the server, SimpleProxy will search for headers matchin `Set-Cookie` and copy the header in it's response back to the client.

#### `$session` ####
 
If set to `TRUE`, SimpleProxy will check for the global constant `SID` and add it to the copied cookies array passed via `CUROPT_COOKIE`. Note that this option also requires that the `$cookies` parameter be set to `TRUE`.
 
## Credits

Original credit goes out to Ben Alman and his php-simple-proxy script.
It provided a solid framework for us to extend and build upon. You can
check out the original script here: http://benalman.com/projects/php-simple-proxy/
