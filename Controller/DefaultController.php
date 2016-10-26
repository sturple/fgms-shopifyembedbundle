<?php

namespace Fgms\ShopifyEmbed\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use \Fgms\ShopifyEmbed\Entity\ShopSettings;

class DefaultController extends Controller {
	var $redirect_url="",
			$shopify=null,
			$logger = null,
			$request = null,
			$session = null,
			$doctrine = null,			
			$filelocator = null,
			$templating = null,
			$appNameSpace = 'FgmsShopifyEmbedBundle',
			$settings = array('page'=>array('title'=>'Install Shopify App')),
			$message403 = '<div style="text-align: center; width: 400px; padding: 50px; margin: 0 auto;"><h1 style="text-transform: uppercase">403 Forbidden</h1>You are not allowed to access this file. Contact webmaster@fifthgeardev.com for more details.</div>';

	public function __construct(\Symfony\Bridge\Monolog\Logger $logger, 
															\Symfony\Component\Config\FileLocator $filelocator, 
															\Doctrine\Bundle\DoctrineBundle\Registry $doctrine,
															\Symfony\Bundle\FrameworkBundle\Templating\EngineInterface $templating,
															\Symfony\Component\HttpFoundation\Session\Session $session
															
														 ){	
		$this->logger = $logger;
		$this->filelocator = $filelocator;
		$this->doctrine = $doctrine;		
		$this->templating = $templating;
		$this->session = $session;			
	}

	public function private_app_call($settings, $method='GET', $call='shop.json' ){	
		$url = 'https://' . $settings['apikey'].':'.$settings['password'].'@'.$settings['shop'].$call;
		$client = new \GuzzleHttp\Client();
		$options = empty($settings['options']) ? array() : array('json' => $settings['options']);
		$res = $client->request($method,$url,$options);	
		if ($res->getStatusCode() === 200){			
			return json_decode($res->getBody(),true);
		}		
	}
		
	public function get_shopify($appNameSpace=null) {		
		if (!empty($appNameSpace)){
			$this->appNameSpace = $appNameSpace;
			$this->settings['namespace'] = $this->appNameSpace;
		}		
		// getting request and session var
		$this->request = Request::createFromGlobals();			
			
		$shopSession = empty($this->session) ? false : $this->session->has('shop') ? $this->session->get('shop') : false;
		$this->settings['shop'] = $this->request->query->has('shop') ? $this->request->query->get('shop') : $shopSession;		
		$this->session->set('shop',$this->settings['shop']);
		$this->settings['host'] = $this->get_hostname();	

		// getting shopify config
		$yaml = new Parser(); 	
		$file = null;
		try {
			$file = $this->filelocator->locate('@'.$this->appNameSpace.'/Resources/config/shopify.yml');
		}
		catch (\InvalidArgumentException $e){
			throw $this->createNotFoundException('Cannot Find Shopify Setting config at '.'@'.$this->appNameSpace.'/Resources/config/shopify.yml');
		}
		$fileContent = null;
		
		try {
			$filesContent = file_get_contents($file);
			$this->settings['shopify'] = $yaml->parse($filesContent);
			if ((!empty($this->settings['shopify']['api_key'])) and 
					(!empty($this->settings['shopify']['shared_secret'])) and
					( !empty($this->settings['shop']))) {	
				
				// store settings
				$this->settings['db'] = $this->doctrine
					->getManager()
					->getRepository('FgmsShopifyEmbedBundle:ShopSettings')
					->findOneBy(array('storeName'=>$this->settings['shop'],'status'=>'active','nameSpace'=>$this->appNameSpace));			
				
				if (!empty($this->settings['db'])) {
					//shopify client
					$this->shopify = new ShopifyClient(	$this->settings,$this->logger);		
					return $this->shopify;								
				}
			}			
		}
		catch (Exception $e){
			$this->logger->error($e->getMessage());
		}			
		return false;
	}	

	public function add_new_store(){		
		$this->request = Request::createFromGlobals();
		$this->session = $this->request->getSession();
		$code = $this->request->query->has('code') ? $this->request->query->get('code') : false;	
		
		$this->logger->warning('Adding New Store Logic');
		// this means nothing has been done, need to get a code from shopify 1st
		if (empty($code)){
			$formFactory = Forms::createFormFactoryBuilder()
				->getFormFactory();
			$form = $formFactory->createBuilder()
				->add('shop',TextType::class)
				->add('save',SubmitType::class,array('label'=>'Add App'))
				->getForm();
			//check form request
			$form->handleRequest();
			if ($form->isValid()){
				$this->settings['shop'] = $form->getData()['shop'];					
				// Step 1: get the shopname from the user and redirect the user to the
				// shopify authorization page where they can choose to authorize this app
				$this->shopify = new ShopifyClient($this->settings, $this->logger);				

				// redirect to authorize url
				if ((!empty($this->settings['shopify']['scope'])) and (! empty($this->settings['shopify']['redirect_url']))){
					$auth_url = $this->shopify->getAuthorizeUrl($this->settings['shopify']['scope'], $this->get_hostname() .$this->settings['shopify']['redirect_url']);
					$this->logger->notice('* STEP 1. Pageurl: '. $auth_url);		
					return $this->redirect($auth_url);
				}
				else {
					$this->logger->error('--------Access Denied Exception');
					throw $this->createAccessDeniedException($this->message403);
				}
				
			}
			$this->settings['form'] = $form->createView();
		}
		else {			
      $this->logger->notice('** STEP 2. Getting token from Code for store: ' .$this->settings['shop'] .' with code: '. $code );
			$this->shopify = new ShopifyClient($this->settings,$this->logger);
			
			$this->settings['token'] = $this->shopify->getAccessToken($code);
      if ($this->settings['token'] != ''){
				// updating database.
				$em = $this->doctrine->getManager();
				$this->settings['db'] = $em->getRepository('FgmsShopifyEmbedBundle:ShopSettings')
					->findOneBy(array('storeName'=>$this->settings['shop'],'status'=>'active','nameSpace'=>$this->settings['namespace']));
																												 
				if (!$this->settings['db']){
					$this->settings['db'] = new ShopSettings();
					$this->settings['db']->setCreateDate();
					$this->settings['db']->setStoreName($this->settings['shop']);
				}
				$this->settings['db']->setNameSpace($this->appNameSpace);
				$this->settings['db']->setStatus('active');
				$this->settings['db']->setAccessToken($this->settings['token']);
				$em->persist($this->settings['db']);
				$em->flush();				
				
				$this->logger->notice('FGMS App Succesfully Added to '. $this->settings['shop']);
      }	
			return $this->renderTemplate('Default:index.html.twig');
		}
		return $this->renderTemplate('Default:app-add.html.twig');		
	}
	
	public function get_settings(){
		return $this->settings;
	}
	
	public function set_settings($settings){
		return $this->settings = array_merge($this->settings,$settings);
	}
	
	private function get_hostname(){
		if ($this->request->server->has('HTTP_X_CODEANYWHERE_PROXY_DOMAIN')){
			$host = $this->request->server->get('HTTP_X_FORWARDED_PROTO') . '://';
			$host .= $this->request->server->get('HTTP_X_CODEANYWHERE_PROXY_DOMAIN');
		}
		else {
			$host = 'http';
			if ($this->request->server->get("HTTPS") == "on") 		{ $host .= "s"; }
			$host .= "://";
			if ($this->request->server->get("SERVER_PORT") != "80") {
					$host .= $this->request->server->get("SERVER_NAME") .
					":".
					$this->request->server->get("SERVER_PORT") .
					$this->request->server->get("REQUEST_URI");
			}
			else  {
					$host .= $this->request->server->get("SERVER_NAME").
					$this->request->server->get("REQUEST_URI");
			}					
		}
		return $host; 
	}
	
	private function renderTemplate($template){
		if ($this->templating->exists($this->appNameSpace.':'.$template) ){
			return $this->templating->renderResponse($this->appNameSpace.':'.$template,$this->settings);
		}
		else {
			return $this->templating->renderResponse('FgmsShopifyEmbedBundle:'.$template,$this->settings);
		}		
	}
}