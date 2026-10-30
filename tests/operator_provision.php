<?php
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='development') throw new RuntimeException('Development CLI only');
define('UCASSER_OPERATOR_PROVISION_LIBRARY',true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/provision_operator.php';
$c=(require __DIR__.'/../site.php')->bootApp()->getContainer();
$db=$c->make(Illuminate\Database\ConnectionInterface::class);
$counts=fn()=>[$db->table('users')->count(),$db->table('campus_operators')->count(),$db->table('campus_audit')->count()];
$check=function($condition,$message){if(!$condition)throw new RuntimeException($message);};
$before=$counts();$db->beginTransaction();
try {
    $name='provision_'.bin2hex(random_bytes(4));
    $input=['username'=>$name,'email'=>$name.'@example.invalid','password'=>bin2hex(random_bytes(16)),'owner_email_confirmed'=>true];
    ucasserProvisionOperator($c,$input,false);$check($counts()===$before,'Preview must roll back account, role and audit');
    $first=ucasserProvisionOperator($c,$input,true);
    $user=Flarum\User\User::findOrFail($first['user_id']);
    $check(!$user->isAdmin() && $user->is_email_confirmed,'Separate confirmed operator must not be administrator');
    $after=$counts();$hash=$user->password;
    $repeat=ucasserProvisionOperator($c,$input+['ignored'=>'value'],true);
    $check(!$repeat['created'] && $counts()===$after,'Repeated provision must be idempotent including audit');
    $check($user->fresh()->password===$hash,'Existing password must not be reset');
    foreach ([array_replace($input,['email'=>'other@example.invalid']),array_replace($input,['owner_email_confirmed'=>false]),array_replace($input,['username'=>$name.'_other']),array_replace($input,['expected_create'=>true])] as $bad) {
        $failed=false;try {ucasserProvisionOperator($c,$bad,true);}catch(Throwable $e){$failed=true;}
        $check($failed && $counts()===$after,'Identity conflicts and missing attestation must fail without changes');
    }
    $actor=Flarum\User\User::query()->get()->first(fn($u)=>$u->isAdmin());
    $failed=false;try {ucasserProvisionOperator($c,['username'=>$actor->username,'email'=>$actor->email,'owner_email_confirmed'=>true],true);}catch(Throwable $e){$failed=true;}
    $check($failed && $counts()===$after,'Must reject administrator account as independent operator');
    echo "Operator provisioning preview, apply, idempotence, conflict rollback and role boundary OK\n";
} finally {$db->rollBack();}
$check($counts()===$before,'All test fixtures must roll back');
