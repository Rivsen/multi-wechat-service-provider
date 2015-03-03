<?php
namespace Rswork\Silex\Wechat;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use JMS\SerializerBundle\Annotation as JMSAnno;
use Symfony\Component\Routing\Generator\UrlGenerator;

class Wechat extends \Pimple
{
    /**
     * @JMSAnno\Exclude
     */
    public $app;

    /**
     * @JMSAnno\Type("array")
     *
     * @var array $values
     */
    protected $values = array();

    /**
     * @JMSAnno\Type("string")
     *
     * @var string $appid
     */
    protected $appid;

    /**
     * @JMSAnno\Type("string")
     *
     * @var string $secret
     */
    protected $secret;

    /**
     * @JMSAnno\Type("string")
     *
     * @var string $name
     */
    protected $name;

    /**
     * @JMSAnno\Exclude
     */
    protected $booted = false;

    /**
     * @JMSAnno\Type("string")
     *
     * @var string $openid
     */
    protected $openid;

    /**
     * @JMSAnno\Exclude
     */
    protected $code;

    /**
     * @JMSAnno\Exclude
     */
    protected $state;

    public function __construct( Application $app, $appid, $secret, $name )
    {
        parent::__construct(array());
        $this->init( $app, $appid, $secret, $name );
    }

    public function init( Application $app, $appid = null, $secret = null, $name = null )
    {
        $this->app = $app;
        $values = array();

        if( isset( $app['config']['wechat'][$name]['fields'] ) AND is_array($app['config']['wechat'][$name]['fields']) ) {
            $values = $app['config']['wechat'][$name]['fields'];

            if( !isset( $app['config']['wechat'][$name]['name'] ) ) {
                $values['name'] = $name;
            } else {
                $values['name'] = $app['config']['wechat'][$name]['name'];
            }
        }

        $this->values = array_merge( $this->values, $values );

        if( !$this->booted ) {
            $this->booted = true;
            $this->addDebug('Booted wechat: ['.$this->name.'] '.$this['name']);

            if( isset( $app['session'] ) AND $app['session']->isStarted() AND isset( $app['serializer'] ) ) {
                $app->on(KernelEvents::TERMINATE, function() use ($app) {
                    $app['session']->set( 'wechat.'.$this->name, $app['serializer']->serialize($this, 'json') );
                    $this->addDebug('Serialized wechat: ['.$this->name.'] '.$this['name']);
                }, -500);
            }
        }

        if( $name ) {
            $this->name = $name;
        }

        if( $appid ) {
            $this->appid = $appid;
        }

        if( $secret ) {
            $this->secret = $secret;
        }
    }

    public function getOpenid()
    {
        return $this->openid;
    }

    public function getAppid()
    {
        return $this->appid;
    }

    public function checkRequest( Request $request )
    {
        if( !$this->booted ) {
            $this->addError('Can\'t check request bedore wechat booted: ['.$this->name.'] '.$this['name']);
            return;
        }

        $this->addDebug('wechat check request');

        $this->addDebug('request uri: '.$request->getUri());

        $querys = $request->query->all();

        if( $request->query->has('code') ) {
            $this->code = $request->query->get('code');
            unset( $querys['code'] );
        }

        if( $this->openid ) {
            // do something
            // ...
        } else if ( $this->app['debug'] ) {
            $this->openid = $this['debug_openid'];
        } elseif ( $this->code ) {
            $data = $this->requestCode();
            if( !$data ) {
                if( isset( $querys['scope'] ) ) {
                    return $this->app->redirect( $this->createRedirectUrl($request->get('_route'), $querys, $querys['scope']) );
                } else {
                    return $this->app->redirect( $this->createRedirectUrl($request->get('_route'), $querys) );
                }
            } else {
                $this->openid = $data['openid'];
                $this['access_token'] = $data['access_token'];
                $this['refresh_token'] = $data['refresh_token'];
                $this['scope'] = $data['scope'];
                $this['expires_in'] = $data['expires_in'];
                $this['expires_at'] = time() + $data['expires_at'];
            }
        } else {
            return $this->app->redirect( $this->createRedirectUrl($request->get('_route'), $querys) );
        }

        $this->addDebug('request openid is '.$this->openid);
    }

    protected function requestCode()
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->appid}&secret={$this->secret}&code={$this->code}&grant_type=authorization_code";

        /**
        {
            "access_token":"ACCESS_TOKEN",
            "expires_in":7200,
            "refresh_token":"REFRESH_TOKEN",
            "openid":"OPENID",
            "scope":"SCOPE"
        }
         */

        try {
            $json = file_get_contents($url);
            $this->addDebug( 'get wechat response json data: '.$json );
            $json = json_decode($json, true);
        } catch( \Exception $e ) {
            $this->addError( 'get wechat response json data faild.' );
            $json = false;
        }

        $this->code = null;

        return $json;
    }

    public function createRedirectUrl( $routeName, $querys, $scope = 'snsapi_base' )
    {
        if( isset( $querys['scope'] ) ) {
            unset( $querys['scope'] );
        }

        if( isset( $querys['code'] ) ) {
            unset( $querys['code'] );
        }

        if( isset( $querys['state'] ) ) {
            unset( $querys['state'] );
        }

        $redirectUri = $this->app['url_generator']->generate($routeName, $querys, UrlGenerator::ABSOLUTE_URL);

        if( $scope == 'snsapi_userinfo' ) {
            return sprintf( $this['auth_userinfo'], $this->getAppid(), urlencode($redirectUri) );
        } else {
            return sprintf( $this['auth_base'], $this->getAppid(), urlencode($redirectUri) );
        }
    }

    public function getName()
    {
        return $this->name;
    }

    public function addInfo( $message, array $context = array() )
    {
        return $this->app['monolog']->addInfo($message, $context);
    }

    public function addDebug( $message, array $context = array() )
    {
        return $this->app['monolog']->addDebug($message, $context);
    }

    public function addError( $message, array $context = array() )
    {
        return $this->app['monolog']->addError($message, $context);
    }
}
