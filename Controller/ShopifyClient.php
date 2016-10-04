<?php
namespace Fgms\ShopifyEmbed\Controller;
use Symfony\Component\Yaml\Parser;

class ShopifyClient  {
	public $shop_domain, $diag_array = array(), $logger = null,$parms;
	private $token;
	private $api_key;
	private $secret;
	private $container;
	private $last_response_headers = null;
												  
	public function __construct($settings,$logger) {			
		$this->name = "ShopifyClient";
		$this->shop_domain = $settings['shop'];									
		$this->secret = $settings['shopify']['shared_secret'];
		$this->api_key = $settings['shopify']['api_key'];		
		$this->token = $settings['token'];
		$this->logger = $logger;		
		$this->logger->notice('ShopifyClient::__consturct',array($this->name,$this->token,$this->api_key, $this->secret));		
		
	}

	public function getApiKey(){
		return $this->api_key;
	}
	// Get the URL required to request authorization
	public function getAuthorizeUrl($scope, $redirect_url='') {
		$url = "http://{$this->shop_domain}/admin/oauth/authorize?client_id={$this->api_key}&scope=" . urlencode($scope);
		if ($redirect_url != ''){
			$url .= "&redirect_uri=" . urlencode($redirect_url);
		}
		return $url;
	}

	

	// Once the User has authorized the app, call this with the code to get the access token
	public function getAccessToken($code) {
		// POST to  POST https://SHOP_NAME.myshopify.com/admin/oauth/access_token
		$url = "https://{$this->shop_domain}/admin/oauth/access_token";
		$payload = "client_id={$this->api_key}&client_secret={$this->secret}&code=$code";
		$response = $this->curlHttpApiRequest('POST', $url, '', $payload, array());
		$response = json_decode($response, true);
		
		if (isset($response['access_token']))
			return $response['access_token'];
		return '';
	}
	

	public function callsMade(){return $this->shopApiCallLimitParam(0);}
	public function callLimit(){return $this->shopApiCallLimitParam(1);}
	public function callsLeft($response_headers){return $this->callLimit() - $this->callsMade();}

	public function call($method, $path, $params=array()){
		$baseurl = "https://{$this->shop_domain}/";	
		$url = $baseurl.ltrim($path, '/');
		$query = in_array($method, array('GET','DELETE')) ? $params : array();
		$payload = in_array($method, array('POST','PUT')) ? stripslashes(json_encode($params)) : array();
		$request_headers = in_array($method, array('POST','PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

		// add auth headers
		$request_headers[] = 'X-Shopify-Access-Token: ' . $this->token;
		$this->logger->notice('++URL: '. $url);
		$this->logger->notice('++Parms: ' .print_R($payload,true));
		$this->logger->notice('++Headers: ',$request_headers);
		$response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);
		$response = json_decode($response, true);
		

		if (isset($response['errors']) or ($this->last_response_headers['http_status_code'] >= 400)){
			$this->logger->notice('++ERROR: '. print_R($response['errors'],true) ."\n\n" .print_R($this->last_response_headers,true));
			return $response;
			//throw new ShopifyApiException($method, $path, $params, $this->last_response_headers, $response);
		}		
		return (is_array($response) and (count($response) > 0)) ? array_shift($response) : $response;
	}

	public function validateSignature($query){
		if(!is_array($query) || empty($query['signature']) || !is_string($query['signature']))
			return false;
		//because this is not part of the calculation but is required by modx
		unset($query['q']);
		unset($query['shop_url']);
		$this->logger->notice('++ShoifyClient validateSignature query '. print_r($query,true));
		if (isset($query['hmac'])){			
			$hmac = $query['hmac'];
			unset($query['hmac']);
			unset($query['signature']);
			$message= trim(http_build_query($query));					
			$hash = hash_hmac('sha256',$message,$this->secret);
			$this->logger->notice("HMAC  hash: $hash = ". $hmac);
			return ($hash === $hmac);
		}
		else {			
			foreach($query as $k => $v) {
				if($k == 'signature') continue;
				if (is_array($v)){
					$signatureSub = array();
					foreach ($v as $ksub=>$vsub){
						$signatureSub[] = $ksub . '=' . $vsub;
					}
					sort($signatureSub);
					$messageSub = implode('',$signatureSub);
					//$messageSub = str_replace(' ','',$messageSub);
					//$signature[] = $k . '=' . $messageSub;
				}
				else {
					$signature[] = $k . '=' . $v;
				}				
			}
			
			sort($signature);
			if (strlen($query['signature']) > 32){
				$message = implode('', $signature);		
				$hash = hash_hmac('sha256',$message,$this->secret);
				$this->logger->notice("Signature > 32 hmac hash: $hash = ". $query['signature']);
				return ($hash === $query['signature']);
			}
			else {				
				$signature = md5($this->secret . implode('', $signature));
				$this->logger->notice("Signature = 32 hmd5 hash: $signature = ". $query['signature']);
				return $query['signature'] == $signature;					
			}
		}
	}

	private function curlHttpApiRequest($method, $url, $query='', $payload='', $request_headers=array()){
		$url = $this->curlAppendQuery($url, $query);
		$ch = curl_init($url);
		$this->curlSetopts($ch, $method, $payload, $request_headers);
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($errno) {
			$this->logger->notice('ShopifyClient::curlHttpApiRequest '. $errno . ' '.$error);
			return;
		}
		list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
		$this->last_response_headers = $this->curlParseHeaders($message_headers);
		
		
		//$this->logger->notice('RETURN HEADERS '.print_R($this->last_response_headers,true));		

		return $message_body;
	}

	private function curlAppendQuery($url, $query){
		if (empty($query)) return $url;
		if (is_array($query)) return "$url?".http_build_query($query);
		else return "$url?$query";
	}

	private function curlSetopts($ch, $method, $payload, $request_headers){
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_USERAGENT, 'ohShopify-php-api-client');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, $method);
		if (!empty($request_headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
		
		if ($method != 'GET' && !empty($payload))
		{
			if (is_array($payload)) $payload = http_build_query($payload);
			curl_setopt ($ch, CURLOPT_POSTFIELDS, $payload);
		}
	}

	private function curlParseHeaders($message_headers){
		$header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
		$headers = array();
		list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim(array_shift($header_lines)), 3);
		foreach ($header_lines as $header_line)
		{
			list($name, $value) = explode(':', $header_line, 2);
			$name = strtolower($name);
			$headers[$name] = trim($value);
		}

		return $headers;
	}
	
	private function shopApiCallLimitParam($index){
		if ($this->last_response_headers == null)	{
			$this->logger->notice('shopApiCallLimitParam::Cannot be called before an API call.');			
		}
		$params = explode('/', $this->last_response_headers['http_x_shopify_shop_api_call_limit']);
		return (int) $params[$index];
	}
	
	//updating metafields
	static 	public function update_metadata($shopify,$request,$params=array()){
		
		$output = array($request->query->get('productId'),$request->query->get('metaId'));
		
		switch ($request->query->get('action','create')){			
			case 'create' :
				
				if ( $request->query->has('productId') ){					
					$output = $shopify->call('POST','/admin/products/'.$request->query->get('productId').'/metafields.json', array('metafield'=>$params) );
				}
				break;
			case 'delete':
				if ($request->query->has('metaId') ){
					$output = $shopify->call('DELETE','/admin/metafields/' . $request->query->get('metaId') . '.json');
				}
				
				break;
			case 'update':
				
				unset($params['key']);
				if ($request->query->has('metaId') ){
					$output = $shopify->call('PUT','/admin/metafields/'.$request->query->get('metaId').'.json', array('metafield'=>$params) );						
				}				
				break;
			default:
				break;
		}
		return $output;	
	
	}
	
	// this is used to cleaning data especially if it has html
	static 	public function clean_data($data){
		return $data;
		//escapes quotes, but dosn't escape html tags		
		$data = htmlspecialchars($data);
		$data = preg_replace("/=/", "=\"\"", $data);
		$data = preg_replace("/&quot;/", "&quot;\"", $data);
		$tags = "/&lt;(\/|)(\w*)(\ |)(\w*)([\\\=]*)(?|(\")\"&quot;\"|)(?|(.*)?&quot;(\")|)([\ ]?)(\/|)&gt;/i";
		$replacement = "<$1$2$3$4$5$6$7$8$9$10>";
		$data = preg_replace($tags, $replacement, $data);
		$data = preg_replace("/=\"\"/", "=", $data);
		$data = preg_replace('!\s+!', ' ', $data);
		return $data;
	}

	
	static public function get_assets($shopify, $type='snippets'){
		//$this->shopify->call('GET','/admin/themes/'. $theme_id .'/assets.json?asset[key]='. $this->cdata['asset'] . '&theme_id='. $theme_id);
		
		$output = $shopify->call('GET','/admin/themes/'. ShopifyClient::get_current_theme($shopify).'/assets.json');
		$asset_array = array();
		foreach ($output as $items){
			$count = null;
			$result = preg_filter('/(^'.$type.'\/|\.liquid$)/', '', $items['key'], -1, $count);			
			if ($count > 1){$asset_array[] = $result;}			
		}
		return $asset_array;
	
		
	}	
	static public function get_products($shopify,$logger){
		//$this->shopify->call('GET','/admin/themes/'. $theme_id .'/assets.json?asset[key]='. $this->cdata['asset'] . '&theme_id='. $theme_id);
		
		$output = $shopify->call('GET','/admin/products.json?fields=id,title,' , array('limit'=>250,'published_status'=>'published'));
		$product_array = array();
		//$logger->notice('get produtcts from shopify'. print_R($output,true));
		foreach ($output as $items){
			$product_array[''.intval($items['id'])] = $items['title'];

		}
		//$logger->notice('get produtcts modified'. print_R($product_array,true));
		return $product_array;
	
		
	}		
	// get current published theme
	static public function get_current_theme($shopify) {
		$output = $shopify->call('GET','/admin/themes.json');
		$currentTheme = false;
		foreach ($output as $theme){
			if ($theme['role'] == 'main'){
				$currentTheme = $theme['id'];
			}
		}
		
		return $currentTheme;
	}
	
	
	
  static public function GET_SETTINGS(Controller $controller) {		
		$yaml = new Parser(); 
		$logger = 	$controller->get('logger');				
		$logger->info($controller->get('kernel')->getRootDir().'/config/parameters.yml');
		$yaml = $yaml->parse(file_get_contents($controller->get('kernel')->getRootDir().'/config/parameters.yml'));
		$store_name = $controller->get('request_stack')->query->has('shop') ? $controller->get('request_stack')->query->get('shop') : $controller->get('request_stack')->getSession()->get('shop');
		$controller->get('request_stack')->getSession()->set('shop',$store_name);
		$shopSettings = $controller->getDoctrine()
			->getManager()
			->getRepository('FgmsShopifyBundle:ShopifyShopSettings')
			->findOneBy(array('storeName'=>$store_name,'status'=>'active'));
		$logger = 	$controller->get('logger');
    $parameters = $yaml['parameters'];
		$array = array('shared_secret' =>$parameters['shopify_shared_secret'],
					 'api_key'=>$parameters['shopify_api_key'],
					 'scope' =>$parameters['shopify_scope'],
					 'redirect_url'=> $parameters['shopify_redirect_url'],
					 'session' =>$controller->get('request_stack')->getSession(),
					 'logger' =>$logger,
					 'template'=>array(),
					 'shop'=>$store_name,
					 'shopify'=>new ShopifyClient($store_name,$shopSettings->getAccessToken(),
												  $parameters['shopify_api_key'],
												  $parameters['shopify_shared_secret'],
												  $logger)
					 );      
	return $array;

		

    }	
	
}
/*
class ShopifyCurlException extends Exception { }
class ShopifyApiException extends Exception
{
	protected $method;
	protected $path;
	protected $params;
	protected $response_headers;
	protected $response;
	
	function __construct($method, $path, $params, $response_headers, $response)
	{
		$this->method = $method;
		$this->path = $path;
		$this->params = $params;
		$this->response_headers = $response_headers;
		$this->response = $response;
		
		parent::__construct($response_headers['http_status_message'], $response_headers['http_status_code']);
	}

	function getMethod() { return $this->method; }
	function getPath() { return $this->path; }
	function getParams() { return $this->params; }
	function getResponseHeaders() { return $this->response_headers; }
	function getResponse() { return $this->response; }
}*/
?>
