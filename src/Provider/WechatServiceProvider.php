<?php

namespace Rswork\Silex\Provider;

use Silex;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class ServiceProvider implements ServiceProviderInterface
{
    public function register( Application $app )
    {
        if( isset( $app['config']['wechat'] ) AND count( $app['config']['wechat'] ) > 0 ) {

            if( isset( $_GET['wxapp'] ) AND isset( $app['config']['wechat'][$_GET['wxapp']] ) ) {
                $default = $_GET['wxapp'];
            }

            $i = 0;

            foreach( $app['config']['wechat'] as $wxname => $wxconfig ) {
                if( !$wxname ) {
                    continue;
                }

                if( $i == 0 AND !isset($default) ) {
                    $default = $wxname;
                    $i++;
                }

                $app['wechat.'.$wxname] = $app->share(function(Application $app) use ($wxname, $wxconfig) {
                    if( $app['session']->isStarted() AND $wechat = $app['session']->get('wechat.'.$wxname) AND isset($app['serializer']) ) {
                        $wechat = $app['serializer']->deserialize( $wechat, 'Rswork\Silex\Wechat\Wechat', 'json' );
                        $wechat->init( $app, $wxconfig['appid'], $wxconfig['secret'], $wxname );

                        return $wechat;
                    } else {
                        return new Wechat( $app, $wxconfig['appid'], $wxconfig['secret'], $wxname );
                    }
                });

                $app['wechat.'.$wxname.'.check'] = $app->share(function(Request $request) use ($app, $wxname, $wxconfig) {
                    return $app['wechat.'.$wxname]->checkRequest( $request );
                });
            }

            if( $default ) {
                $app['wechat'] = $app->share(function(Application $app) use ($default) {
                    return $app['wechat.'.$default];
                });

                $app['wechat.check'] = $app->share(function(Request $request) use ($default, $app) {
                    return call_user_func( $app->raw('wechat.'.$default.'.check'), $request);
                });
            }
        }
    }

    public function boot( Application $app )
    {
        if( !$app['session']->isStarted() ) {
            $app['session']->start();
        }
    }
}
