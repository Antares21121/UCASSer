<?php
namespace UCASSer\Campus;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils;

/**
 * Renders user mentions as `@昵称 #后缀` so equal display names stay
 * distinguishable in posted content. Idempotent regardless of renderer order:
 * the suffix is appended only when not already present.
 */
class SuffixMentionRenderer
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (!str_contains($xml, 'USERMENTION') || !$this->db->getSchemaBuilder()->hasTable('campus_user_tags')) return $xml;
        $ids = array_unique(Utils::getAttributeValues($xml, 'USERMENTION', 'id'));
        if (!$ids) return $xml;
        $suffixes = $this->db->table('campus_user_tags')->whereIn('user_id', $ids)->get(['user_id', 'suffix'])->pluck('suffix', 'user_id')->all();
        if (!$suffixes) return $xml;
        return Utils::replaceAttributes($xml, 'USERMENTION', function (array $attributes) use ($suffixes): array {
            $suffix = $suffixes[$attributes['id']] ?? null;
            if (!$suffix) return $attributes;
            $name = (string) ($attributes['displayname'] ?? '');
            if ($name === '' || preg_match('/#'.preg_quote((string) $suffix, '/').'(?=\s|$)/u', $name)) return $attributes;
            $attributes['displayname'] = $name.' #'.$suffix;
            return $attributes;
        });
    }
}
