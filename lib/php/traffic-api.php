<?php
/**
 * Utility class for Traffic API connection
 *
 * @version 0.0.1
 */
class TrafficAPI
{
	private static $Version = '0.0.1';
	private static $UserAgent = 'SalesLV/Traffic-API';
	private static $UAString = '';

	private static $VerifySSL = false;

    // Error constants
	// No error
	const ERROR_NONE = 0;
	// Response from Traffic was invalid, cannot be parsed
	const ERROR_INVALID_RESPONSE = 1;
	// Response from Traffic was empty, no data contained (something should be there always for valid requests)
	const ERROR_EMPTY_RESPONSE = 2;
	// An error has happened with HTTP request to Traffic
	const ERROR_REQUEST = 3;
	// No library for HTTP requests available
	const ERROR_CANNOT_MAKE_HTTP_REQUEST = 4;
	// Attachments upload not supported with the current configuration
	const ERROR_ATTACHMENTS_NOT_SUPPORTED_WITH_THIS_METHOD = 5;
	// Traffic responded with error
	const ERROR_RESPONSE = 6;

	// Response error messages
	private static $ResponseErrors = array(
		'InvalidRecipients' => 'Incorrect recipient number (in case of a single number) or incorrectly assembled recipient array',
		'InvalidSender' => 'Sender ID doesn’t exist or isn’t available',
		'InvalidCountryCode' => 'Incorrect or unsupported country code, if specified',
		'ContentTooLong' => 'Long SMS message has more than 7 content parts',
		'QuotaExceeded' => 'Account quota has been exceeded',
		'InvalidShortenLink' => 'An invalid website address was provided in the ShortenLinksOverride parameter',
		'InvalidMessageID' => 'erroneous or not specified message ID'
	);

	/**
	 * @var string Traffic API key.
	 */
	private $APIKey = '';
	/**
	 * @var string Traffic API endpoint URL
	 */
	private $APIURL = 'https://traffic.sales.lv/API:0.16/';
	/**
	 * @var ERROR_* Error code, one of TrafficAPI::ERROR_* constants
	 */
	private $ErrNo = 0;
	/**
	 * @var string Human-readable error message
	 */
	private $Error = '';

	public $Debug = [
		'LastHTTPRequest' => [
			'URL' => '',
			'Request' => [],
			'Response' => []
		]
	];

	// !Public utility methods

	/**
	 * Constructor
	 * @var string API key, it should be provided to you along with the rest of the account data.
	 */
	public function __construct($Key)
	{
		self::$UAString = self::$UserAgent.'/'.self::$Version;
		if (extension_loaded('http'))
		{
			self::$UAString .= '-http';
		}
		elseif (extension_loaded('curl'))
		{
			self::$UAString .= '-curl';
		}
		elseif (ini_get('allow_url_fopen'))
		{
			self::$UAString .= '-stream';
		}

		$this -> APIKey = $Key;
	}

	public function __get($Name)
	{
		if ($Name == 'Error' || $Name == 'ErrNo' || $Name == 'Debug')
		{
			return $this -> {$Name};
		}
		return null;
	}

	// !API calls

    public function Send($Recipients, string $Sender, string $Content, ?int $SendTime = null, ?string $CC = null, int $Validity = 0, bool $Transliteration = false, string $TransliterationFallback = '', bool $ShortenLinks = true, ?array $ShortenLinksOverride = null)
    {
		$PostData = array(
			'Command' => 'Send',
			'Recipients' => is_array($Recipients) ? json_encode($Recipients) : $Recipients,
			'Sender' => $Sender,
			'Content' => $Content
		);

		if(! empty($SendTime)) {
			$PostData['SendTime'] = $SendTime;
		}

		if(! empty($CC)) {
			$PostData['CC'] = $CC;
		}

		if(! empty($Validity)) {
			$PostData['Validity'] = $Validity;
		}

		if(! empty($Transliteration)) {
			$PostData['Transliteration'] = $Transliteration;
		}

		if(! empty($TransliterationFallback)) {
			$PostData['TransliterationFallback'] = $TransliterationFallback;
		}

		if(! empty($ShortenLinks)) {
			$PostData['ShortenLinks'] = $ShortenLinks;
		}

		if(! empty($ShortenLinksOverride)) {
			$PostData['ShortenLinksOverride'] = $ShortenLinksOverride;
		}

		$Data = $this -> HTTPRequest($this -> APIURL, $PostData);

		return $this -> ParseResponse($Data);
    }

    public function GetSenders()
    {
		$Data = $this -> HTTPRequest($this -> APIURL, array(
			'Command' => 'GetSenders'
		));

		return $this -> ParseResponse($Data);
    }

    public function GetDelivery($ID)
    {
        $Data = $this -> HTTPRequest($this -> APIURL, array(
			'Command' => 'GetDelivery',
			'ID' => is_array($ID) ? json_encode($ID) : $ID
		));

		return $this -> ParseResponse($Data);
    }

	public function GetReport($ID)
    {
        $Data = $this -> HTTPRequest($this -> APIURL, array(
			'Command' => 'GetReport',
			'ID' => is_array($ID) ? json_encode($ID) : $ID
		));

		return $this -> ParseResponse($Data);
    }

	// !Public utility methods

	// !Private utility methods
	private function ParseResponse($Response)
	{
		if (!is_array($Response))
		{
			$this -> SetError(self::ERROR_INVALID_RESPONSE, 'Invalid response from Traffic, cannot parse');
			return false;
		}

		$this -> SetError(self::ERROR_NONE, '');

		$Body = false;
		if ($Response['Body'])
		{
			$Body = json_decode($Response['Body'], true);
		}

		if (!$Response['Body'])
		{
			$this -> SetError(self::ERROR_EMPTY_RESPONSE, 'Empty response from Traffic');
		}
		elseif (!$Body)
		{
			$ErrorMessage = 'Invalid response from Traffic, cannot parse';
			if (is_null($Body))
			{
				// JSON parsing error
				$ErrorMessage = 'JSON parsing error'.(function_exists('json_last_error') ? ' #'.json_last_error() : '');
			}

			$this -> SetError(self::ERROR_INVALID_RESPONSE, $ErrorMessage);
		}
		elseif (!empty($Body['Error']))
		{
			$ResponseErrorMsg = isset(self::$ResponseErrors[$Body['Error']]) ? self::$ResponseErrors[$Body['Error']] : $Body['Error'];
			$this -> SetError(self::ERROR_RESPONSE, $ResponseErrorMsg);
		}

		return $Body;
	}

	private function SetError($ErrorCode, $ErrorMessage)
	{
		$this -> ErrNo = $ErrorCode;
		$this -> Error = $ErrorMessage;

		return null;
	}

	// !HTTP request utilities
	/**
	 * Utility method for making HTTP requests (used to abstract the HTTP request implementation)
	 *	pecl_http extension is recommended, however, if it is not available, the request will be made by other means.
	 *
	 * @param string URL to make the request to
	 * @param array POST data if it is a POST request. If this is empty, a GET request will be made, if populated - POST. Optional.
	 * @param array Additional headers to pass to the service, optional.
	 *
	 * @return array Array containing response data: array(
	 *	'Code' => int HTTP status code (200, 403, etc.),
	 *	'Headers' => array Response headers
	 *	'Content' => string Response body 
	 * )
	 */
	private function HTTPRequest($URL, array $POSTData = null, array $Headers = null, array $Files = null)
	{
		$doPost = !is_null($POSTData);

		if($doPost) {
			$POSTData['APIKey'] = $this->APIKey;
		}

		$this -> Debug['LastHTTPRequest']['URL'] = $URL;
		$this -> Debug['LastHTTPRequest']['Method'] = $doPost ? 'POST' : 'GET';
		$this -> Debug['LastHTTPRequest']['Request'] = $POSTData;
		$this -> Debug['LastHTTPRequest']['Response'] = '';

		$Result = [];

		try
		{
			if (extension_loaded('http'))
			{
				$Result = self::HTTPRequest_http($URL, $POSTData, $Headers, $Files);
			}
			elseif (extension_loaded('curl'))
			{
				$Result = self::HTTPRequest_curl($URL, $POSTData, $Headers, $Files);
			}
			elseif (ini_get('allow_url_fopen'))
			{
				if ($Files)
				{
					return $this -> SetError(self::ERROR_ATTACHMENTS_NOT_SUPPORTED_WITH_THIS_METHOD, 'Attachment upload not supported for this HTTP connection method (stream context,) please install curl or pecl_http');
				}
				$Result = self::HTTPRequest_fopen($URL, $POSTData, $Headers, $Files);
			}
			else
			{
				return $this -> SetError(self::ERROR_CANNOT_MAKE_HTTP_REQUEST, 'No means to make a HTTP request are available (pecl_http, curl or allow_url_fopen)');
			}
		}
	  	catch (Exception $E)
	  	{
	  		$this -> SetError(self::ERROR_REQUEST, $E -> getMessage());

		  	return false;
	  	}

	  	$this -> Debug['LastHTTPRequest']['Response'] = $Result;

		return $Result;
	}

	/**
	 * Utility method for making HTTP requests with the pecl_http extension, see HTTPRequest for more information
	 */
	private static function HTTPRequest_http($URL, array $POSTData = null, array $Headers = null, array $Files = null)
	{
		$Method = $POSTData ? HttpRequest::METH_POST : HttpRequest::METH_GET;

  		$Request = new HttpRequest($URL, $Method);
  		if ($Headers)
  		{
  			$Request -> setHeaders($Headers);
  		}
  		$Request -> setPostFields($POSTData);

		if ($Files)
		{
			foreach ($Files as $File)
			{
				$Request -> addPostFile($File['name'], $File['tmp_name'], $File['type']);
			}
		}

  		$Request -> send();

  		return [
  			'Headers' => array_merge(
  				[
	  				'Response Code' => $Request -> getResponseCode(),
	  				'Response Status' => $Request -> getResponseStatus()
	  			],
	  			$Request -> getResponseHeader()
  			),
  			'Body' => $Request -> getResponseBody()
  		];
	}

	/**
	 * Utility method for making HTTP requests with CURL. See TrafficAPI::HTTPRequest for more information
	 */
	private static function HTTPRequest_curl($URL, array $POSTData = null, array $Headers = null, array $Files = null)
	{
		if ($Files)
		{
			if (!$POSTData)
			{
				$POSTData = [];
			}

			$Index = 0;
			foreach ($Files as $File)
			{
				$POSTData['Attachment['.$Index.']'] = curl_file_create($File['tmp_name'], $File['type'], $File['name']);
				$Index++;
			}
		}

		// Preparing request headers
		$Headers = ['Expect' => ''];
		$Headers = self::PrepareHeaders($Headers, $URL);

		$cURLParams = [
			CURLOPT_URL => $URL, 
			CURLOPT_HEADER => 1,
			CURL_HTTP_VERSION_1_0 => true,
			CURLOPT_POST => $POSTData ? 1 : 0,
			CURLOPT_CONNECTTIMEOUT => 60,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_FAILONERROR => 1,
			CURLOPT_USERAGENT => self::$UAString,
			CURLOPT_SAFE_UPLOAD => true,
			CURLOPT_POSTFIELDS => $POSTData,
			CURLOPT_ENCODING => 'gzip',
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_HTTPHEADER => $Headers,
			CURLOPT_SSL_VERIFYPEER => self::$VerifySSL
		];

		// Making the request
		$cURLRequest = curl_init();
		curl_setopt_array($cURLRequest, $cURLParams);
		$ResponseBody = curl_exec($cURLRequest);
		curl_close($cURLRequest);

		$ResponseBody = str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $ResponseBody);
		$ResponseParts = explode("\n\n", $ResponseBody);

		$ResponseHeaders = [];
		if (count($ResponseParts) > 1)
		{
			$ResponseHeaders = self::ParseHeadersFromString($ResponseParts[0]);
		}

		$ResponseBody = isset($ResponseParts[1]) ? $ResponseParts[1] : $ResponseBody;

		return [
			'Headers' => $ResponseHeaders,
			'Body' => $ResponseBody
		];
	}

	/**
	 * Utility method for making the HTTP request with file_get_contents. See TrafficAPI::HTTPRequest for more information
	 */
	private static function HTTPRequest_fopen($URL, array $POSTData = null, array $Headers = null, array $Files = null)
	{
		// Preparing request body
		$POSTBody = $POSTData ? self::PrepareBody($POSTData) : '';

		// Preparing headers
		$Headers = self::PrepareHeaders($Headers, $URL, strlen($POSTBody));
		$Headers = implode("\r\n", $Headers)."\r\n";

		// Making the request
		$Context = stream_context_create([
			'http' => [
				'method' => $POSTBody ? 'POST' : 'GET',
				'header' => $Headers,
				'content' => $POSTBody,
				'protocol_version' => 1.0
			]
		]);

		$Content = file_get_contents($URL, false, $Context);

		$ResponseHeaders = $http_response_header;
		$ResponseHeaders = self::ParseHeadersFromArray($ResponseHeaders);

		return [
			'Headers' => $ResponseHeaders,
			'Body' => $Content
		];
	}

	/**
	 * Utility for HTTP requests to prepare header arrays
	 *
	 * @param array Headers to send in addition to the default set (keys are names, values are content)
	 * @param string URL that will be used for the request (for the "Host" header)
	 * @param int Optional content length for the Content-Length header
	 *
	 * return array Headers in a numeric array. Each item in the array is a separate header string containing both name and content
	 */
	private static function PrepareHeaders(array $Headers = null, $URL, $ContentLength = null)
	{
		$URLInfo = parse_url($URL);
		$Host = $URLInfo['host'];

		$DefaultHeaders = [
			'Host' => $Host,
			'Connection' => 'close',
			'User-Agent' => self::$UAString
		];

		if (!is_null($ContentLength))
		{
			$DefaultHeaders['Content-Length'] = $ContentLength;
		}

		if ($Headers)
		{
			$Headers = array_merge($DefaultHeaders, $Headers);
		}
		else
		{
			$Headers = $DefaultHeaders;
		}

		$Result = [];
		foreach ($Headers as $Name => $Content)
		{
			$Result[] = $Name.': '.$Content;
		}
		
		return $Result;
	}

	/**
	 * Prepares POST request body content for sending
	 *
	 * @param array Data to send
	 *
	 * @return string Body content suitable for a HTTP request
	 */
	private static function PrepareBody(array $Data)
	{
		$POSTBody = [];
		foreach ($Data as $Key => $Value)
		{
			$POSTBody[] = $Key.'='.urlencode($Value);
		}

		return implode('&', $POSTBody);
	}

	/**
	 * Parses raw HTTP header text into an associative array
	 *
	 * @param string Raw header text
	 *
	 * @return array Associative array with header data. Two additional elements are created:
	 *	- Response Status: Status message, for example, "OK" for requests with 200 status code
	 *	- Response Code: The numeric status code - 200, 301, 401, 503, etc.
	 */
	private static function ParseHeadersFromString($HeaderString)
	{
		if (function_exists('http_parse_headers'))
		{
			$Result = http_parse_headers($HeaderString);
		}
		else
		{
			$Headers = explode("\n", $HeaderString);

			$Result = self::ParseHeadersFromArray($Headers);
		}
	
		return $Result;
	}

	/**
	 * Parses raw header array into an associative array.
	 *
	 * @param array Array containing the headers
	 *
	 * @return array Associative array with header data. Two additional elements are created:
	 *	- Response Status: Status message, for example, "OK" for requests with 200 status code
	 *	- Response Code: The numeric status code - 200, 301, 401, 503, etc.
	 */
	private static function ParseHeadersFromArray(array $Headers)
	{
		$Result = [];

		$CurrentHeader = 0;

		foreach ($Headers as $Index => $RawHeader)
		{
			if ($Index == 0 || strpos($RawHeader, 'HTTP/') === 0)
			{
				// HTTP status headers could be repeated on further lines if any redirects are encountered.
				list($Discard, $StatusCode, $Status) = explode(' ', $RawHeader, 3);
				$Result['Response Code'] = $StatusCode;
				$Result['Response Status'] = $Status;

				continue;
			}

			$RawHeader = explode(':', $RawHeader, 2);

			if (count($RawHeader) > 1)
			{
				$CurrentHeader = trim($RawHeader[0]);
				$Result[$CurrentHeader] = trim($RawHeader[1]);
			}
			elseif (count($RawHeader) == 1)
			{
				$Result[$CurrentHeader] .= ' '.trim($RawHeader[0]);
			}
			else
			{
				$CurrentHeader = false;
			}
		}

		return $Result;
	}
}
?>
