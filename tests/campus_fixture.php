<?php
// Development acceptance helper. Never exposed through public/ or enabled in production.
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
$app=(require __DIR__.'/../site.php')->bootApp(); $c=$app->getContainer();
$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$db=$c->make(Illuminate\Database\ConnectionInterface::class); $settings=$c->make(Flarum\Settings\SettingsRepositoryInterface::class);
$out=[];
if ($input['action']==='inspect') {
    $u=Flarum\User\User::query()->findOrFail($input['user_id']);
    $out=['groups'=>$u->groups->pluck('id')->all(),'password_hashed'=>$u->password!==$input['password'] && $u->checkPassword($input['password']), 'verifications'=>$db->table('campus_verifications')->where('user_id',$u->id)->get()->all(),'audit'=>$db->table('campus_audit')->where('target',(string)($input['target'] ?? ''))->get()->all(),'private_rows'=>$db->table('campus_discussion_bookmarks')->where('user_id',$u->id)->count()+$db->table('campus_cases')->where('reporter_id',$u->id)->count()+$db->table('campus_feedback')->where('user_id',$u->id)->count()];
} elseif ($input['action']==='mail') {
    foreach (['mail_driver','mail_host','mail_port','mail_encryption','mail_username','mail_password','mail_from','mail_smtp_verify_peer'] as $key) { $out[$key]=$settings->get($key); if (array_key_exists($key,$input['values'])) $settings->set($key,$input['values'][$key]); }
} elseif ($input['action']==='tokens') {
    $out=['email'=>$db->table('email_tokens')->where('user_id',$input['user_id'])->value('token'),'reset'=>$db->table('password_tokens')->where('user_id',$input['user_id'])->value('token'),'erasure'=>$db->table('gdpr_erasure')->where('user_id',$input['user_id'])->value('verification_token')];
} elseif ($input['action']==='exports') {
    $out=$db->table('gdpr_exports')->where('user_id',$input['user_id'])->get()->all();
} else throw new RuntimeException('Unknown acceptance action');
file_put_contents($argv[2],json_encode($out,JSON_THROW_ON_ERROR));
if (PHP_OS_FAMILY!=='Windows') {chown($argv[2],fileowner($argv[1])); chgrp($argv[2],filegroup($argv[1]));}
chmod($argv[2],0600); echo "Acceptance fixture completed\n";
