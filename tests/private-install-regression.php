<?php
namespace MarrisonCustomUpdater\MaintenanceClient {
 class Settings { public static $authorized=true; public static function repository_config_managed(){return self::$authorized;} public static function get_secret(){return 'fixture-secret';} public static function record_request(...$args){} }
 class Rate_Limiter { public static function allow($ip){return true;} }
}
namespace {
 define('ABSPATH', __DIR__.'/missing-wordpress/'); define('WP_PLUGIN_DIR', __DIR__.'/missing-plugins');
 class WP_Error { function __construct(public $code, public $message=''){} function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} }
 class Automatic_Upgrader_Skin {}
 $plugins=[];$active=[];$filters=[];$transients=[];$mode='';$calls=[];$allowed=true;$checks=0;
 $catalog=[['slug'=>'example','name'=>'Example','version'=>'1.0','download_url'=>'https://repo.invalid/private/example.zip']];
 function is_wp_error($v){return $v instanceof WP_Error;} function wp_is_file_mod_allowed($context){return $GLOBALS['allowed'];}
 function get_plugins(){return $GLOBALS['plugins'];} function get_option($k,$default=false){return $k==='marrison_repo_url'?'https://repo.invalid/private/':($GLOBALS['options'][$k]??$default);}
 function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;}
 function wp_clean_plugins_cache($v){} function wp_tempnam($v){return tempnam(sys_get_temp_dir(),'mcu-install-test-');}
 function wp_safe_remote_get($url,$args){$GLOBALS['calls'][]=$url; if(($args['redirection']??-1)!==0)throw new \RuntimeException('Redirect permitted');return ['code'=>$GLOBALS['mode']==='download'&&!str_ends_with($url,'index.php')?302:200,'body'=>json_encode($GLOBALS['catalog'])];}
 function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
 function wp_delete_file($f){unlink($f);} function add_filter($k,$fn,$priority,$argc){$GLOBALS['filters'][$k]=$fn;} function remove_filter($k,$fn,$priority){unset($GLOBALS['filters'][$k]);}
 function is_plugin_active($f){return in_array($f,$GLOBALS['active'],true);} function is_multisite(){return false;}
 function validate_plugin_requirements($f){return $GLOBALS['mode']==='compatibility'?new WP_Error('incompatible','Incompatible PHP'):true;}
 function activate_plugin($f,$redirect,$network,$silent){$GLOBALS['calls'][]='activate';if($silent||$network)throw new \RuntimeException('Unexpected activation flags');if($GLOBALS['mode']==='activation')return new WP_Error('failed','Activation failed');if($GLOBALS['mode']==='throw')throw new \RuntimeException('private detail');$GLOBALS['active'][]=$f;return null;}
 function sanitize_text_field($s){return strip_tags($s);} function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));} function wp_strip_all_tags($s){return strip_tags($s);} function wp_generate_uuid4(){return 'fixture-job';}
 function get_transient($k){return $GLOBALS['transients'][$k]??false;} function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;}
 function apply_filters($k,$v,...$args){return $k==='mcu_remote_install_plugin'?$GLOBALS['runner']->mcu_remote_install_plugin($v,...$args):$v;}
 class Plugin_Upgrader {
 function __construct($skin){} function install($path,$args){
  $GLOBALS['calls'][]='install';if($args['overwrite_package']!==false)throw new \RuntimeException('Overwrite enabled');
  $check=$GLOBALS['filters']['upgrader_source_selection']('/tmp/'.($GLOBALS['mode']==='identity'?'different':'example').'/', '', $this);if(is_wp_error($check))return $check;
  if($GLOBALS['mode']==='install')return new WP_Error('filesystem','Filesystem denied');
  $GLOBALS['plugins']['example/main.php']=['Name'=>'Example'];return true;
 } function plugin_info(){return 'example/main.php';}
 }
 require __DIR__.'/../marrison-custom-updater/includes/traits/InstallOperationsTrait.php';
 require __DIR__.'/../marrison-custom-updater/includes/mcu-client/class-actions-controller.php';
 require __DIR__.'/../marrison-custom-updater/includes/mcu-client/class-authenticator.php';
 class Runner {use \MCU_Install_Operations_Trait; public $locked=false;public $busy=false;function mcu_acquire_update_lock(...$args){if($this->busy)return new WP_Error('locked','Busy');$this->locked=true;return 'lock';}function mcu_release_update_lock($lock){$this->locked=false;}}
 $runner=new Runner();$method=new \ReflectionMethod(\MarrisonCustomUpdater\MaintenanceClient\Actions_Controller::class,'execute');
 function run(){return $GLOBALS['method']->invoke(null,'install_plugin',['slug'=>'example','package'=>'https://evil.invalid/evil.zip'],'1',microtime(true));}
 function check($ok,$label){if(!$ok)throw new \RuntimeException($label);$GLOBALS['checks']++;}
 $r=run();check($r['success']&&is_plugin_active('example/main.php'),'Installs and activates');check(!$runner->locked&&!$filters,'Releases lock and hook');check(!in_array('https://evil.invalid/evil.zip',$calls,true),'Ignores arbitrary caller URL');check($options['mcu_master_update_status']['status']==='completed','Persists outcome');
 $before=count($calls);$r=run();check($r['success']&&!in_array('install',array_slice($calls,$before),true),'Active plugin is a no-op');
 $active=[];$before=count($calls);$r=run();check(!$r['success']&&!in_array('activate',array_slice($calls,$before),true),'Inactive existing plugin is not reactivated');
 foreach(['download','identity','install','compatibility','activation','throw'] as $mode){$plugins=[];$active=[];$r=run();check(!$r['success']&&!$runner->locked&&!$filters,'Failure cannot become success: '.$mode);check($options['mcu_master_update_status']['status']==='failed','Failure persisted: '.$mode);}
 $mode='';$plugins=[];$active=[];$catalog[0]['download_url']='https://evil.invalid/example.zip';$r=run();check(!$r['success']&&$r['error_code']==='untrusted_package','Foreign origin rejected');
 $catalog[0]['download_url']='https://repo.invalid/private/../outside.zip';$r=run();check(!$r['success'],'Traversal rejected');$catalog=[];$r=run();check(!$r['success'],'Missing membership rejected');
 $catalog=[['slug'=>'example','version'=>'1.0','download_url'=>'https://evil.invalid/old.zip'],['slug'=>'example','version'=>'2.0','download_url'=>'https://repo.invalid/private/latest.zip']];$plugins=[];$active=[];$r=run();check($r['success']&&in_array('https://repo.invalid/private/latest.zip',$calls,true),'Newest private ZIP selected when several versions share a slug');
 $catalog=[['slug'=>'example','version'=>'2.0','download_url'=>'https://www.repo.invalid/private/latest.zip']];$plugins=[];$active=[];$r=run();check($r['success'],'Canonical www package alias is accepted');
 $before=count($calls);$runner->busy=true;$r=run();check(!$r['success']&&count($calls)===$before,'Concurrent lock blocks all I/O');$runner->busy=false;
 \MarrisonCustomUpdater\MaintenanceClient\Settings::$authorized=false;$r=run();check(!$r['success']&&count($calls)===$before,'Unauthorized client cannot install');
 \MarrisonCustomUpdater\MaintenanceClient\Settings::$authorized=true;$allowed=false;$r=run();check(!$r['success']&&count($calls)===$before,'File policy enforced');
 class WP_REST_Request {public $headers=[];public $body='';function get_header($k){return $this->headers[$k]??'';}function get_body(){return $this->body;}function get_method(){return 'POST';}function get_route(){return '/marrison-maintenance/v1/action';}}
 $req=new WP_REST_Request();$req->body=json_encode(['operation'=>'install_plugin','site_id'=>'1','parameters'=>['slug'=>'example']]);
 $req->headers=['x-marrison-site-id'=>'1','x-marrison-timestamp'=>(string)time(),'x-marrison-nonce'=>'fixture-nonce-00001'];
 $canonical=\MarrisonCustomUpdater\MaintenanceClient\Authenticator::canonical_string('POST',$req->get_route(),$req->headers['x-marrison-timestamp'],$req->headers['x-marrison-nonce'],$req->body);
 $req->headers['x-marrison-signature']=hash_hmac('sha256',$canonical,'fixture-secret');
 $bad=clone $req;$bad->body=str_replace('example','malicious',$req->body);check(!\MarrisonCustomUpdater\MaintenanceClient\Authenticator::authenticate($bad)['ok'],'Tampered body rejected');
 check(\MarrisonCustomUpdater\MaintenanceClient\Authenticator::authenticate($req)['ok'],'Authentic signed install accepted');
 check(!\MarrisonCustomUpdater\MaintenanceClient\Authenticator::authenticate($req)['ok'],'Nonce replay rejected');
 $req->headers=[];check(!\MarrisonCustomUpdater\MaintenanceClient\Authenticator::authenticate($req)['ok'],'Unsigned install rejected');
 echo "Private install: $checks checks passed; real dispatcher, runner and HMAC; no network.\n";
}
