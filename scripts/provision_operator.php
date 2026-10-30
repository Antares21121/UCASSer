<?php
/** Owner-run CLI account provisioning through the normal Flarum administration API. */
function ucasserProvisionOperator($container, array $input, bool $apply): array
{
    if (($input['owner_email_confirmed'] ?? null) !== true) throw new RuntimeException('Explicit server-owner confirmation of the account email is required');
    $username=trim((string) ($input['username'] ?? ''));
    $email=trim((string) ($input['email'] ?? ''));
    $password=(string) ($input['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/D',$username) || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid account identity');
    $db=$container->make(Illuminate\Database\ConnectionInterface::class);
    $actor=Flarum\User\User::query()->where('is_email_confirmed',true)->get()->first(fn($user)=>$user->isAdmin());
    if (!$actor) throw new RuntimeException('No existing confirmed administrator can attribute owner provisioning');
    $db->beginTransaction();
    try {
        $user=Flarum\User\User::query()->where('username',$username)->lockForUpdate()->first();
        if ($user && (mb_strtolower($user->email)!==mb_strtolower($email) || $user->isAdmin())) throw new RuntimeException('Existing account identity or administrator conflict; account was not changed');
        if (Flarum\User\User::query()->where('email',$email)->when($user,fn($q)=>$q->where('id','!=',$user->id))->exists()) throw new RuntimeException('Email is already used by another account');
        $created=!$user;
        if (array_key_exists('expected_create',$input) && $input['expected_create']!==$created) throw new RuntimeException('Account changed after preview; repeat preview before applying');
        $confirmed=$user && $user->is_email_confirmed;
        $client=$container->make(Flarum\Api\Client::class)->withActor($actor)->withoutErrorHandling();
        if (!$user) {
            if (strlen($password)<16) throw new RuntimeException('A new account requires a password of at least 16 characters');
            $response=$client->withBody(['data'=>['type'=>'users','attributes'=>[
                'username'=>$username,'email'=>$email,'password'=>$password,'isEmailConfirmed'=>true,
            ]]])->post('/users');
            if ($response->getStatusCode()!==201) throw new RuntimeException('Account creation failed');
            $id=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)['data']['id'];
            $user=Flarum\User\User::findOrFail($id);
        } elseif (!$confirmed) {
            $response=$client->withBody(['data'=>['type'=>'users','id'=>(string)$user->id,'attributes'=>['isEmailConfirmed'=>true]]])->patch('/users/'.$user->id);
            if ($response->getStatusCode()!==200) throw new RuntimeException('Owner email confirmation failed');
            $user->refresh();
        }
        if ($user->isAdmin() || !$user->is_email_confirmed) throw new RuntimeException('Provisioned account must be a confirmed ordinary user');
        $already=$db->table('campus_operators')->where('user_id',$user->id)->exists();
        if (!$already) {
            $command=$container->make(UCASSer\Campus\OperatorGrantCommand::class);
            $code=$command->run(new Symfony\Component\Console\Input\ArrayInput(['username'=>$username]),new Symfony\Component\Console\Output\BufferedOutput());
            if ($code!==0) throw new RuntimeException('Operator grant failed');
        }
        if ($created || !$confirmed || !$already) $db->table('campus_audit')->insert([
            'actor_id'=>$actor->id,'action'=>'operator.owner.provision','target'=>'user:'.$user->id,
            'reason'=>'Server owner confirmed account email; separate operator only; administrator role not granted',
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $result=['apply'=>$apply,'created'=>$created,'operator'=>true,'administrator'=>false,'user_id'=>$user->id];
        if ($apply) $db->commit(); else $db->rollBack();
        return $result;
    } catch (Throwable $error) {$db->rollBack();throw $error;}
}

if (!defined('UCASSER_OPERATOR_PROVISION_LIBRARY')) {
    if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
    try {
        $args=array_slice($_SERVER['argv']??[],1);
        if (array_diff($args,['--apply'])) throw new RuntimeException('Usage: php scripts/provision_operator.php [--apply], JSON input on stdin');
        require __DIR__.'/../vendor/autoload.php';
        $input=json_decode(stream_get_contents(STDIN,8192),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new RuntimeException('Invalid provisioning input');
        $container=(require __DIR__.'/../site.php')->bootApp()->getContainer();
        echo json_encode(ucasserProvisionOperator($container,$input,in_array('--apply',$args,true)),JSON_UNESCAPED_UNICODE)."\n";
    } catch (Throwable $error) {
        // Do not echo validation payloads that may contain account credentials.
        fwrite(STDERR,"Operator provisioning failed; check identity conflicts and explicit owner email confirmation. No provisioning transaction was committed.\n");exit(1);
    }
}
