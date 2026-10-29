<?php
namespace UCASSer\Campus;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Parser;

/**
 * Resolves hand-typed `@昵称#后缀` mentions into the native quoted mention
 * token with the stable user id, so the mentions extension stores the right
 * USERMENTION id, notifies the right account and stays correct after renames.
 * Native `@"..."#id` and `@username` tokens are left untouched.
 */
class SuffixMentionParser
{
    public function __construct(private ConnectionInterface $db, private UserTags $tags) {}

    public function __invoke(Parser $parser, mixed $context, string $text, ?User $actor): string
    {
        if (!$this->tags->enabled() || !str_contains($text, '#')) return $text;
        $suffixes = self::collectSuffixes($text);
        if (!$suffixes) return $text;
        $map = $this->db->table('campus_user_tags')->whereIn('suffix', array_keys($suffixes))->get(['suffix', 'user_id'])->pluck('user_id', 'suffix')->all();
        return self::transform($text, $map);
    }

    /** All #suffix candidates in the text, as [suffix => true]. */
    public static function collectSuffixes(string $text): array
    {
        $suffixes = [];
        if (preg_match_all('/(?<![A-Za-z0-9_@#])@([\p{L}\p{N}][\p{L}\p{N}_·\-]{0,59})#([0-9]{3,7})(?!\d)/u', $text, $plain)) {
            foreach ($plain[3] as $suffix) $suffixes[(int) $suffix] = true;
        }
        if (preg_match_all('/@"([^"]{1,60})"#([0-9]{3,7})(?!\d)/u', $text, $quoted)) {
            foreach ($quoted[2] as $suffix) $suffixes[(int) $suffix] = true;
        }
        return $suffixes;
    }

    /**
     * Pure transform used by the parser and the unit test: replace suffix
     * mentions with the native quoted token for each resolved suffix. The
     * replacement cannot re-match (quoted name plus numeric user id). Only a
     * Latin letter/digit/underscore before "@" suppresses the match, so CJK
     * text like `和@晓明#6665` resolves without needing a space.
     */
    public static function transform(string $text, array $suffixToUserId): string
    {
        if (!$suffixToUserId) return $text;
        $replaceQuoted = function (array $match) use ($suffixToUserId): string {
            $userId = $suffixToUserId[(int) $match[2]] ?? null;
            return $userId ? '@"'.$match[1].'"#'.$userId : $match[0];
        };
        $replacePlain = function (array $match) use ($suffixToUserId): string {
            $userId = $suffixToUserId[(int) $match[2]] ?? null;
            return $userId ? '@"'.$match[1].'"#'.$userId : $match[0];
        };
        $text = preg_replace_callback('/@"([^"]{1,60})"#([0-9]{3,7})(?!\d)/u', $replaceQuoted, $text);
        return preg_replace_callback('/(?<![A-Za-z0-9_@#])@([\p{L}\p{N}][\p{L}\p{N}_·\-]{0,59})#([0-9]{3,7})(?!\d)/u', $replacePlain, $text);
    }
}
