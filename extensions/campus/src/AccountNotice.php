<?php
namespace UCASSer\Campus;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
class AccountNotice implements BlueprintInterface,AlertableInterface
{
    public function __construct(private User $recipient,private string $action,private int $target) {}
    public function getFromUser(): ?User {return null;}
    public function getSubject(): ?AbstractModel {return $this->recipient;}
    public function getData(): array {return ['action'=>$this->action,'target'=>$this->target];}
    public static function getType(): string {return 'campusAccount';}
    public static function getSubjectModel(): string {return User::class;}
}
