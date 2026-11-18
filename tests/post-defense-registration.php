<?php
// Owns one schema-only disposable database. Never changes source rows or private config.
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--run'||empty($argv[2]))exit("Usage: php tests/post-defense-registration.php --run /path/to/original/checkout\n");
require rtrim($argv[2],'/').'/includes/db_config.php';
$syntheticSource=in_array('--synthetic-source',$argv,true);
if(!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)||(!$syntheticSource && DB_NAME!=='bcihs_library'))exit("Expected local source only.\n");
$dsn='mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4';
$sourceName=DB_NAME;
if ($syntheticSource) {
 $sourceName='bcihs_registration_source_'.bin2hex(random_bytes(5));
 $setup=new PDO($dsn,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $setup->exec('CREATE DATABASE `'.$sourceName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $setup->exec('USE `'.$sourceName.'`');$setup->exec(file_get_contents(__DIR__.'/../sql/schema.sql'));
 $setup->exec('ALTER TABLE users DROP INDEX uq_users_phone_normalized, DROP COLUMN phone_normalized');
}
$source=new PDO($dsn.';dbname='.$sourceName,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function snapshot(PDO $p):array {$s=[];foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){$r=$p->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);$hashes=array_map(fn($x)=>hash('sha256',json_encode($x)),$r);sort($hashes);$s[$t]=$hashes;}return $s;}
$before=snapshot($source);$db='bcihs_registration_test_'.bin2hex(random_bytes(5));$root=sys_get_temp_dir().'/'.$db;mkdir($root,0700);
$created=false;$server=null;$checks=0;$failed=false;
if($probe=@fsockopen('127.0.0.1',8906)){fclose($probe);rmdir($root);exit("Port 8906 already in use. No database created.\n");}
function check(bool $v,string $label):void {global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
function client():array {global $root;return ['cookie'=>$root.'/cookie-'.bin2hex(random_bytes(4)),'csrf'=>''];}
function request(string $endpoint,array &$s,?array $data=null,bool $json=false):array {
 [$endpointPath,$queryString]=array_pad(explode('?',$endpoint,2),2,'');
 $c=curl_init('http://127.0.0.1:8906/backend/'.$endpointPath.'.php'.($queryString!==''?'?'.$queryString:''));$headers=[];
 if($json)$headers=['Content-Type: application/json','X-CSRF-Token: '.$s['csrf']];
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$s['cookie'],CURLOPT_COOKIEJAR=>$s['cookie'],CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
 if($data!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$json?json_encode($data):http_build_query($data));}
 $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$code,json_decode((string)$body,true),$body];
}
function parallelRequests(array $jobs): array {
 $multi=curl_multi_init();$handles=[];
 foreach($jobs as [$endpoint,$session,$data,$json]) {
  $c=curl_init('http://127.0.0.1:8906/backend/'.$endpoint.'.php');
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json?json_encode($data):http_build_query($data),CURLOPT_HTTPHEADER=>$json?['Content-Type: application/json','X-CSRF-Token: '.$session['csrf']]:[]]);
  $handles[]=$c;curl_multi_add_handle($multi,$c);
 }
 do {$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,1);}while($active&&$status===CURLM_OK);
 $codes=[];foreach($handles as $c){$codes[]=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $codes;
}
try {
 $source->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $p=new PDO($dsn.';dbname='.$db,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec('SET FOREIGN_KEY_CHECKS=0');foreach($source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$p->exec($source->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);$p->exec('SET FOREIGN_KEY_CHECKS=1');
 $env=getenv();foreach(['HOST'=>DB_HOST,'PORT'=>DB_PORT,'NAME'=>$db,'USER'=>DB_USER,'PASS'=>DB_PASS] as $k=>$v)$env['BCIHS_DB_'.$k]=$v;
 $env['BCIHS_SMS_PROVIDER']='simulation';$env['BCIHS_SMS_MODE']='simulate';$env['BCIHS_SMS_LIVE_ENABLED']='0';$env['PHP_CLI_SERVER_WORKERS']='4';
 $run=static function(string $file,array $args=[])use($env,$root):int {$proc=proc_open(array_merge([PHP_BINARY,$file],$args),[0=>['pipe','r'],1=>['file',$root.'/migration.out','a'],2=>['file',$root.'/migration.err','a']],$pipes,dirname(__DIR__),$env);fclose($pipes[0]);return proc_close($proc);};
 $seed=$p->prepare('INSERT INTO users(full_name,phone,password_hash,role) VALUES(?,?,?,"student")');
 $seed->execute(['Legacy One','09170009999','synthetic']);$seed->execute(['Legacy Two','+639170009999','synthetic']);
 check($run('scripts/migrate-unique-phones.php',['--apply'])!==0,'duplicate migration refuses');
 check(!$p->query("SHOW COLUMNS FROM users LIKE 'phone_normalized'")->fetch(),'duplicate refusal preserves schema');
 check((int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'conflicts preserved');
 $p->exec('DELETE FROM users');
 $seed->execute(['Existing Name Preserved','09170006666','synthetic']);$seed->execute(['Legacy Missing',null,'synthetic']);$seed->execute(['Legacy Malformed','invalid','synthetic']);
 $legacy=$p->query('SELECT id,full_name,phone FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

 check($run('scripts/migrate-unique-phones.php',['--apply'])===0,'isolated migration');
 check($p->query('SELECT id,full_name,phone FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$legacy,'migration preserves legacy names and phone bytes');
 $columns=$p->query("SHOW COLUMNS FROM users LIKE 'phone_normalized'")->fetch();check((bool)$columns,'unique column installed');
 // Each run gets its own app path, so login-rate budgets cannot leak between synthetic runs.
 $app=$root.'/app';mkdir($app,0700);
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__),FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){if(!$file->isFile()||$file->isLink())continue;$rel=substr($file->getPathname(),strlen(dirname(__DIR__))+1);
 if(preg_match('#^(\.git|uploads|vendor|tests|scripts)/#',$rel)||in_array(basename($rel),['db-local.php','sms-local.php','pickup-local.php','semaphore-api-key.php','borrowing-policy.json','report-local.json'],true))continue;
 $dest=$app.'/'.$rel;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);copy($file->getPathname(),$dest);}
 $server=proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init','-d','session.save_path='.$root,'-S','127.0.0.1:8906','-t',$app],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__),$env);
 fclose($pipes[0]);$ready=false;for($i=0;$i<30;$i++){if($f=@fsockopen('127.0.0.1',8906)){fclose($f);$ready=true;break;}usleep(100000);}check($ready,'isolated HTTP server');
 $pass='Synthetic-Only-Password1!';$users=[];
 foreach(['student','teacher','librarian','visitor'] as $i=>$role){
  $s=client();[$code,$token]=request('registration-token',$s);$s['csrf']=$token['csrfToken'];
  $form=['role'=>$role,'csrf_token'=>$s['csrf'],'first_name'=>'María Anne','middle_name'=>'D.','last_name'=>'De la Cruz','phone'=>'0917000000'.($i+1),'password'=>$pass,'confirm_password'=>$pass,'student_id'=>'SYN-'.$i,'grade_level'=>'12','section'=>'A','id_number'=>'EMP-'.$i,'email'=>'synthetic'.$i.'@example.invalid'];
  [$code]=request('register-process',$s,$form);check($code===302,'registration redirect '.$role);
  $q=$p->prepare('SELECT * FROM users WHERE phone=:phone');$q->execute(['phone'=>$form['phone']]);$u=$q->fetch(PDO::FETCH_ASSOC);check((bool)$u,'registration persisted '.$role);check($u['full_name']==='María Anne D. De la Cruz','composed name '.$role);$users[]=$u;
  $beforeCount=$p->query('SELECT COUNT(*) FROM users')->fetchColumn();$dup=$form;$dup['phone']='+63'.substr($form['phone'],1);$dup['student_id']='DUP-'.$i;$dup['id_number']='DUP-'.$i;$dup['email']='dup'.$i.'@example.invalid';request('register-process',$s,$dup);check($p->query('SELECT COUNT(*) FROM users')->fetchColumn()===$beforeCount,'duplicate international rejected '.$role);
 }
 $s=client();[$code,$token]=request('registration-token',$s);$form['csrf_token']=$token['csrfToken'];$form['phone']='09910000001';$form['email']='invalid@example.invalid';
 foreach([['first_name'=>''],['last_name'=>''],['first_name'=>'123'],['phone'=>'bad']] as $bad){$n=$p->query('SELECT COUNT(*) FROM users')->fetchColumn();request('register-process',$s,array_replace($form,$bad));check($p->query('SELECT COUNT(*) FROM users')->fetchColumn()===$n,'invalid form rejected');}
 $hash=password_hash($pass,PASSWORD_DEFAULT);$q=$p->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)');$q->execute(['synthetic-admin',$hash]);
 $staff=client();request('admin-login-process',$staff,['identifier'=>'synthetic-admin','password'=>$pass]);[, $adminProfile]=request('profile-data',$staff);$staff['csrf']=$adminProfile['csrfToken'];
 foreach($users as $u){[$code]=request('user-approval-action',$staff,['id'=>$u['id'],'status'=>'accepted'],true);check($code===200,'existing approval workflow '.$u['role']);}
 $pdo=$p;require_once __DIR__.'/../includes/account-management.php';
 foreach([['Ma. Lourdes','','De la Peña'],['Jean-Luc',"O’Neil",'Dela Cruz'],['李','','王']] as [$first,$middle,$last])check(registrationName(['first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last])!=='' ,'legitimate names');

 foreach($users as $u){$s=client();$identifier=$u['student_id']?:($u['id_number']?:$u['email']);[$code]=request('login-process',$s,['identifier'=>$identifier,'password'=>$pass]);check($code===302,'login '.$u['role']);[$code,$profile]=request('profile-data',$s);check($code===200&&$profile['full_name']===$u['full_name'],'existing profile display '.$u['role']);$s['csrf']=$profile['csrfToken'];
 $update=['full_name'=>$u['full_name'],'phone'=>$u['phone'],'year_section'=>'12 — A','department'=>'QA','email'=>$u['email']];[$code]=request('profile-update',$s,$update,true);check($code===200,'unchanged own phone '.$u['role']);$update['phone']=$users[($u['id']===$users[0]['id'])?1:0]['phone'];[$code]=request('profile-update',$s,$update,true);check($code===409,'profile duplicate blocked '.$u['role']);}
 $jobs=[];$n=(int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
 foreach([1,2] as $i){$s=client();[, $token]=request('registration-token',$s);$data=['role'=>'student','csrf_token'=>$token['csrfToken'],'first_name'=>'Concurrent','last_name'=>'Student','phone'=>$i===1?'09910008888':'+639910008888','password'=>$pass,'confirm_password'=>$pass,'student_id'=>'CON-'.$i,'grade_level'=>'12','section'=>'A'];$jobs[]=['register-process',$s,$data,false];}
 parallelRequests($jobs);check((int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()===$n+1,'concurrent equivalent registration one winner');
 $jobs=[];
 foreach(array_slice($users,0,2) as $u){$s=client();request('login-process',$s,['identifier'=>$u['student_id']?:$u['id_number'],'password'=>$pass]);[, $profile]=request('profile-data',$s);$s['csrf']=$profile['csrfToken'];$data=['full_name'=>$u['full_name'],'phone'=>'09910007777','year_section'=>'12 — A','department'=>'QA'];$jobs[]=['profile-update',$s,$data,true];}
 $codes=parallelRequests($jobs);sort($codes);check($codes===[200,409],'concurrent profiles one winner');
 $q=$p->query("SELECT COUNT(*) FROM users WHERE phone_normalized='09910007777'");check((int)$q->fetchColumn()===1,'unique database constraint');
 if(in_array($argv[3]??'',['--borrowing','--reminders','--integrated'],true))require __DIR__.'/post-defense-borrowing.php';
 if(in_array($argv[3]??'',['--reminders','--integrated'],true))require __DIR__.'/post-defense-reminders.php';
 if(($argv[3]??'')==='--integrated')require __DIR__.'/post-defense-visibility.php';
 check(snapshot($source)===$before,'all original source records unchanged');
 if(getenv('BCIHS_LOCAL_VISUAL_HOLD')==='1'){echo "ISOLATED VISUAL CHECK READY: http://127.0.0.1:8906/ — press Enter to clean up.\n";fgets(STDIN);check(snapshot($source)===$before,'original records unchanged after visual review');}
 echo "Passed $checks isolated registration/profile/migration checks. Original records unchanged; provider HTTP disabled.\n";
} catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");$failed=true;} finally {
 if(is_resource($server)){
  // PHP's worker children can outlive proc_terminate; target only this run's exact app path.
  $processes=[];exec('ps -ax -o pid=,command=',$processes);
  foreach($processes as $process)if(preg_match('/^\s*(\d+)\s+(.+)$/',$process,$match)
      && str_contains($match[2],'-S 127.0.0.1:8906') && isset($app) && str_contains($match[2],$app))posix_kill((int)$match[1],SIGTERM);
  proc_terminate($server);proc_close($server);
 }

 if($created)$source->exec('DROP DATABASE `'.$db.'`');
 if($syntheticSource)$source->exec('DROP DATABASE `'.$sourceName.'`');
 $cleanup=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($cleanup as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}@rmdir($root);
}

if($failed)exit(1);
