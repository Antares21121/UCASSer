<?php
namespace UCASSer\Campus;

use Flarum\Messages\DialogMessage\Event\Created;
use Illuminate\Database\ConnectionInterface;

class ChatImages
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(Created $event): void
    {
        $message = $event->message;
        if (!$message->user_id || !$message->dialog_id) return;
        preg_match_all('~/api/campus/chat-images/([a-f0-9]{32})~', $message->content, $matches);
        foreach (array_unique($matches[1] ?? []) as $id) {
            $this->db->table('campus_chat_images')->where('id', $id)
                ->where('owner_id', $message->user_id)->whereNull('dialog_id')
                ->update(['dialog_id' => $message->dialog_id]);
        }
    }
}
