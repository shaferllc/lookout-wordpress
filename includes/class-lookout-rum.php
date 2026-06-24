<?php
/**
 * Real User Monitoring beacon injector.
 *
 * Prints a small vanilla-JS snippet into the front-end footer that:
 *   - captures front-end JS errors (window.onerror / unhandledrejection) and reports them to
 *     /api/ingest as language:js at 100% (errors are rare and high-value), and
 *   - on a sampled fraction of page views, collects Web Vitals (LCP, FCP, TTFB, CLS, INP) and
 *     sends one /api/ingest/rum beacon at page hide.
 *
 * RUM runs in visitors' browsers, so it is gated on an explicit opt-in (lookout_rum_enabled),
 * the remote `rum` signal being enabled, and never injected into wp-admin. The beacon uses
 * keepalive fetch (not sendBeacon) so it can set X-Lookout-Client-Sampled and the server does
 * not double-sample.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Rum
{
    public static function enabled(): bool
    {
        if (! Lookout_Consent::granted()) {
            return false;
        }
        if (! get_option('lookout_enabled', false) || ! get_option('lookout_rum_enabled', false)) {
            return false;
        }
        if (is_admin()) {
            return false;
        }
        $api_key = (string) get_option('lookout_api_key', '');
        $base = (string) get_option('lookout_base_url', '');
        if ($api_key === '' || $base === '') {
            return false;
        }

        return Lookout_Config::is_enabled('rum');
    }

    /**
     * Config the browser snippet needs. JS errors ride at 100%; Web Vitals at the rum sample rate.
     *
     * @return array<string, mixed>
     */
    public static function script_config(): array
    {
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        return [
            'errors' => $base.'/api/ingest',
            'rum' => $base.'/api/ingest/rum',
            'key' => (string) get_option('lookout_api_key', ''),
            'env' => (string) get_option('lookout_environment', 'production'),
            'rate' => Lookout_Config::sample_rate('rum'),
        ];
    }

    public static function render(): void
    {
        if (! self::enabled()) {
            return;
        }

        $config = wp_json_encode(self::script_config());
        echo "<script>(function(){var c=".$config.";".self::snippet().'})();</script>';
    }

    /**
     * The browser collector. Kept dependency-free and defensive: any failure is swallowed so the
     * page is never affected by telemetry.
     */
    private static function snippet(): string
    {
        return <<<'JS'
if(!c||!c.key){return;}
var sampled=Math.random()<(c.rate||0),sent=0;
function post(url,body,sampledHeader){
  try{
    var h={'Content-Type':'application/json'};
    if(sampledHeader){h['X-Lookout-Client-Sampled']='1';}
    fetch(url,{method:'POST',headers:h,body:JSON.stringify(body),keepalive:true,credentials:'omit'});
  }catch(e){}
}
function reportError(msg,src,stack){
  if(sent>=10){return;}sent++;
  post(c.errors,{api_key:c.key,message:String(msg||'Script error').slice(0,2000),exception_class:'JavaScriptError',
    level:'error',language:'js',environment:c.env,url:location.href,file:src||location.href,
    stack_trace:String(stack||'').slice(0,20000)});
}
window.addEventListener('error',function(e){reportError(e&&e.message,e&&e.filename,e&&e.error&&e.error.stack);});
window.addEventListener('unhandledrejection',function(e){var r=e&&e.reason;reportError(r&&r.message||r,location.href,r&&r.stack);});
if(!sampled){return;}
var vit={lcp:null,fcp:null,cls:0,inp:0},ttfb=null;
try{var nav=performance.getEntriesByType('navigation')[0];if(nav){ttfb=Math.round(nav.responseStart);}}catch(e){}
function ob(type,cb){try{var o=new PerformanceObserver(function(l){l.getEntries().forEach(cb);});o.observe({type:type,buffered:true});return o;}catch(e){return null;}}
ob('largest-contentful-paint',function(en){vit.lcp=Math.round(en.startTime);});
ob('paint',function(en){if(en.name==='first-contentful-paint'){vit.fcp=Math.round(en.startTime);}});
ob('layout-shift',function(en){if(!en.hadRecentInput){vit.cls+=en.value;}});
ob('event',function(en){if(en.duration>vit.inp){vit.inp=Math.round(en.duration);}});
var flushed=false;
function flush(){
  if(flushed){return;}flushed=true;
  post(c.rum,{api_key:c.key,page_url:location.href,navigation_type:'load',environment:c.env,
    user_agent:navigator.userAgent,lcp_ms:vit.lcp,fcp_ms:vit.fcp,ttfb_ms:ttfb,inp_ms:vit.inp||null,
    cls:Math.round(vit.cls*1000)/1000},true);
}
addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden'){flush();}});
addEventListener('pagehide',flush);
JS;
    }
}
