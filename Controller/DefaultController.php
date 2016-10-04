<?php

namespace Fgms\ShopifyEmbed\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;


use \Fgms\ShopifyEmbed\Entity\ShopSettings;


class DefaultController extends Controller {
	var  $cdata = array(),
			$errors = array(),
			$post_flag = false,
			$options = array(),
			$debug_flag ,
			$formtype = '',
			$redirect_url="",
			$shopify=null,
			$logger = null,
			$request = null,
			$session = null,
			$settings = array('page'=>array('title'=>'Install Shopify App'),'token'=>''),
			$template_array = array(),
			$message403 = '<div style="text-align: center; width: 400px; padding: 50px; margin: 0 auto;"><h1 style="text-transform: uppercase">403 Forbidden</h1>You are not allowed to access this file. Contact webmaster@fifthgeardev.com for more details.</div>',
			$_diag = array();


	public function __construct(){	
		
	}


	public function indexAction(Request $request){
		return $this->get_app_settings($request);
	}
  public function oauthAction(Request $request){		
		return $this->get_app_settings($request);			
   }
	
	
	private function get_app_settings($request=null) {
		
		$this->logger = 	$this->get('logger');				
		// getting request and session var
		$this->request = empty($request) ? Request::createFromGlobals() : $request;
		$this->session = $this->request->getSession();
		$code = $this->request->query->has('code') ? $this->request->query->get('code') : false;		
		
		$shopSession = empty($this->session) ? false : $this->session->has('shop') ? $this->session->get('shop') : false;
		$this->settings['shop'] = $this->request->query->has('shop') ? $this->request->query->get('shop') : $shopSession;		
		

		// getting shopify config
		$yaml = new Parser(); 				
		$file = $this->get('kernel')->getRootDir().'/config/shopify.yml';
		$fileContent = null;
		
		try {
			$filesContent = file_get_contents($file);
			$this->settings['shopify'] = $yaml->parse($filesContent);
			if ((!empty($this->settings['shopify']['api_key'])) and 
					(!empty($this->settings['shopify']['shared_secret'])) and
					( !empty($this->settings['shop']))) {	
				
				// store settings
				$this->settings['db'] = $this->getDoctrine()
					->getManager()
					->getRepository('FgmsShopifyEmbedBundle:ShopSettings')
					->findOneBy(array('storeName'=>$this->settings['shop'],'status'=>'active'));			
				
				if (empty($this->settings['db'])) {
					$this->logger->warning('No Database For Shop');
					if (!empty($this->session)){
						$this->session->clear();
					}
					return $this->add_new_store($code);	
				}
				
				//shopify client
				$this->shopify = new ShopifyClient(	$this->settings,$this->logger);			
			}
			else {				
				return $this->add_new_store($code);		
				//$this->logger->error('Oauth Session Attempted but has no shop name');			
				//throw $this->createAccessDeniedException($this->message403);
			}			
			
		}
		catch (Exception $e){
			$this->logger->error($e->getMessage());
		}
			
		if (!empty($code)){
			return $this->add_new_store($code);	
		}			
		return $this->render('FgmsShopifyEmbedBundle:Default:index.html.twig', $this->settings);
	}	
	
	



	private function add_new_store($code){
		$this->logger->warning('Adding New Store Logic');
		// this means nothing has been done, need to get a code from shopify 1st
		if (empty($code)){
			
			$form = $this->createFormBuilder()
				->add('shop',TextType::class)
				->add('save',SubmitType::class,array('label'=>'Add App'))
				->getForm();
			//check form request
			$form->handleRequest($this->request);
			if ($form->isValid()){
				$this->settings['shop'] = $form->getData()['shop'];					
				// Step 1: get the shopname from the user and redirect the user to the
				// shopify authorization page where they can choose to authorize this app
				$this->shopify = new ShopifyClient($this->settings, $this->logger);
				$pageURL = 'http';
				if ($this->request->server->get("HTTPS") == "on") 		{ $pageURL .= "s"; }
				$pageURL .= "://";
				if ($this->request->server->get("SERVER_PORT") != "80") {
						$pageURL .= $this->request->server->get("SERVER_NAME") .
						":".
						$this->request->server->get("SERVER_PORT") .
						$this->request->server->get("REQUEST_URI");
				}
				else  {
						$pageURL .= $this->request->server->get("SERVER_NAME").
						$this->request->server->get("REQUEST_URI");
				}

				// redirect to authorize url
				if ((!empty($this->settings['shopify']['scope'])) and (! empty($this->settings['shopify']['redirect_url']))){
					$auth_url = $this->shopify->getAuthorizeUrl($this->settings['shopify']['scope'], $this->settings['shopify']['redirect_url']);
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
       	$this->session->set('token',$this->settings['token']) ;            
				
				// updating database.
				$em = $this->getDoctrine()->getManager();
				$this->settings['db'] = $em->getRepository('FgmsShopifyEmbedBundle:ShopSettings')
					->findOneBy(array('storeName'=>$this->settings['shop'],'status'=>'active'));
																												 
				if (!$this->settings['db']){
					$this->settings['db'] = new ShopSettings();
					$this->settings['db']->setCreateDate();
					$this->settings['db']->setStoreName($this->settings['shop']);
				}
				
				$this->settings['db']->setStatus('active');
				$this->settings['db']->setAccessToken($this->settings['token']);
				$em->persist($this->settings['db']);
				$em->flush();
				
				$this->session->set('shop',$this->settings['shop']) ;
				$this->logger->notice('FGMS App Succesfully Added to '. $this->settings['shop']);
      }	
			return $this->render('FgmsShopifyEmbedBundle:Default:index.html.twig', $this->settings);
		}
		return $this->render('FgmsShopifyEmbedBundle:Default:app-add.html.twig', $this->settings);
		
	}
	
	/*
	public function testAction(){
		$em = $this->getDoctrine()->getManager();
		$this->settings['shop'] = 'test.myshopify.com';
		$this->settings['token'] ='mytoken';
		$this->settings['db'] = $em->getRepository('FgmsShopifyEmbedBundle:ShopSettings')
			->findOneBy(array('storeName'=>$this->settings['shop'],'status'=>'active'));

		if (!$this->settings['db']){
			$this->settings['db'] = new ShopSettings();
			$this->settings['db']->setCreateDate();
			$this->settings['db']->setStoreName($this->settings['shop']);
		}

		$this->settings['db']->setStatus('active');
		$this->settings['db']->setAccessToken($this->settings['token']);
		$em->persist($this->settings['db']);
		$em->flush();		
		
	$response = new Response();
	$response->setContent('<html><body><h1>Hello world!</h1><pre>{{dump()}}</pre></body></html>');
	$response->setStatusCode(Response::HTTP_OK);
	// set a HTTP response header
	$response->headers->set('Content-Type', 'text/html');
	return $response;
	}*/
	
}