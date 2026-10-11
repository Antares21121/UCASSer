<?php
namespace UCASSer\Campus;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
class Notice implements BlueprintInterface,AlertableInterface
{
    public function __construct(private Discussion $discussion,private User $actor,private string $action,private int $record) {}
    public function getFromUser(): ?User {return $this->actor;}
    public function getSubject(): ?AbstractModel {return $this->discussion;}
    public function getData(): array {return ['action'=>$this->action,'record_id'=>$this->record];}
    public static function getType(): string {return 'campusUpdate';}
    public static function getSubjectModel(): string {return Discussion::class;}
}
