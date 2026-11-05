<?php
namespace MarrisonCustomUpdater\MaintenanceClient {class Settings {static $authorized=true;static function repository_config_managed(){return self::$authorized;}}}
namespace {
 $root=sys_get_temp_dir().'/mcu-state-'.bin2hex(random_bytes(8));mkdir($root.'/plugins/example',0777,true);mkdir($root.'/plugins/mcu',0777,true);
 file_put_contents($root.'/plugins/example/main.php','<?php');file_put_contents($root.'/plugins/mcu/main.php','<?php');
 define('ABSPATH',$root.'/');define('WP_PLUGIN_DIR',$root.'/plugins');define('MCU_PLUGIN_DIR',$root.'/plugins/mcu/');$sourceRoot=dirname(__DIR__).'/marrison-custom-updater/';mkdir(MCU_PLUGIN_DIR.'includes/mcu-client',0777,true);file_put_contents(MCU_PLUGIN_DIR.'includes/mcu-client/class-plugin-security.php','<?php');
 $plugins=['example/main.php'=>['Name'=>'Example','Version'=>'1.0']];$active=[];$mode='';$allowed=true;$multisite=false;$calls=[];$options=[];$checks=0;
 class WP_Error {function __construct(public $code,public $message){}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
 function is_wp_error($v){return $v instanceof WP_Error;}function wp_is_file_mod_allowed($context){return $GLOBALS['allowed'];}function is_multisite(){return $GLOBALS['multisite'];}
 function get_plugins(){return $GLOBALS['plugins'];}function wp_clean_plugins_cache($v){}function sanitize_text_field($s){return strip_tags($s);}function wp_strip_all_tags($s){return strip_tags($s);}function wp_json_encode($s,$flags=0){return json_encode($s,$flags);}
 function is_plugin_active($f){return in_array($f,$GLOBALS['active'],true);}function validate_plugin_requirements($f){return $GLOBALS['mode']==='compatibility'?new WP_Error('incompatible','Incompatible'):true;}
 function activate_plugin($file,$redirect,$network,$silent){$GLOBALS['calls'][]='activate';if($network||$silent)throw new \RuntimeException('Flags');if($GLOBALS['mode']==='failure')return new WP_Error('failed','Failed');if($GLOBALS['mode']==='throw')throw new \RuntimeException('private');if($GLOBALS['mode']!=='noop')$GLOBALS['active'][]=$file;echo 'output';}
 function deactivate_plugins($file,$silent){$GLOBALS['calls'][]='deactivate';if($silent)throw new \RuntimeException('Hooks suppressed');$GLOBALS['active']=array_values(array_diff($GLOBALS['active'],[$file]));echo 'output';}
 require $sourceRoot.'includes/mcu-client/class-plugin-security.php';require $sourceRoot.'includes/traits/PluginStateOperationsTrait.php';
 class Runner {use \MCU_Plugin_State_Operations_Trait;public $locked=false;public $busy=false;function mcu_acquire_update_lock(...$args){if($this->busy)return new WP_Error('locked','Busy');$this->locked=true;return 'lock';}function mcu_release_update_lock($l){$this->locked=false;}}
 $runner=new Runner();function check($v,$label){if(!$v)throw new \RuntimeException($label);$GLOBALS['checks']++;}
 function run($desired,$file='example/main.php',$override=[]){$identity=\MarrisonCustomUpdater\MaintenanceClient\Plugin_Security::metadata($file,$GLOBALS['plugins'][$file]??[]);return $GLOBALS['runner']->mcu_remote_set_plugin_active([],array_replace(['plugin_file'=>$file,'expected_fingerprint'=>$identity['review_fingerprint'],'active'=>$desired],$override));}
 try{
 ob_start();$r=run(true);$output=ob_get_clean();check($r['success']&&$r['active']===true&&is_plugin_active('example/main.php'),'Activation verified');check($output===''&&!$runner->locked,'Output buffered and lock released');
 $before=count($calls);check(run(true)['already_current']&&count($calls)===$before,'Already active no-op');check(run(false)['success']&&!is_plugin_active('example/main.php'),'Deactivation verified');
 foreach(['compatibility','failure','throw','noop'] as $mode){$r=run(true);check(!$r['success']&&!$runner->locked,'Failure and lock release: '.$mode);}$mode='';
 $plugins['example/main.php']['RequiresPlugins']='dependency';check(!run(true)['success'],'Missing dependency blocks activation');unset($plugins['example/main.php']['RequiresPlugins']);run(true);$plugins['dependent/main.php']=['Name'=>'Dependent','RequiresPlugins'=>'example'];$active[]='dependent/main.php';check(!run(false)['success']&&is_plugin_active('example/main.php'),'Active dependents block deactivation');unset($plugins['dependent/main.php']);$active=[];
 check(!run(true,'example/main.php',['expected_fingerprint'=>str_repeat('a',64)])['success'],'Changed identity rejected');check(!run('false')['success'],'Ambiguous state rejected');check(!run(true,'../outside.php')['success'],'Unsafe path rejected');
 $plugins['mcu/main.php']=['Name'=>'MCU','Version'=>'1.0'];$active[]='mcu/main.php';check(!run(false,'mcu/main.php')['success']&&is_plugin_active('mcu/main.php'),'MCU cannot deactivate itself');$active=[];
 $runner->busy=true;$before=count($calls);check(!run(true)['success']&&count($calls)===$before,'Concurrent lock blocks mutation');$runner->busy=false;
 $allowed=false;check(!run(true)['success'],'File policy enforced');$allowed=true;$multisite=true;check(!run(true)['success'],'Network scope cannot change');$multisite=false;
 \MarrisonCustomUpdater\MaintenanceClient\Settings::$authorized=false;check(!run(true)['success'],'Authorization enforced');
 echo "MCU plugin state: $checks checks passed (real runner and metadata, WordPress doubles).\n";
 }finally{unlink(MCU_PLUGIN_DIR.'includes/mcu-client/class-plugin-security.php');rmdir(MCU_PLUGIN_DIR.'includes/mcu-client');rmdir(MCU_PLUGIN_DIR.'includes');unlink($root.'/plugins/example/main.php');unlink($root.'/plugins/mcu/main.php');rmdir($root.'/plugins/example');rmdir($root.'/plugins/mcu');rmdir($root.'/plugins');rmdir($root);}
}
