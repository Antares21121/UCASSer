<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Flarum\Foundation\ValidationException;
class SearchQuery
{
    public static function build(User $a,array $p): \Illuminate\Database\Eloquent\Builder {
        $q=Discussion::query()->whereVisibleTo($a)->with(['user','tags'])->leftJoin('campus_records as c','c.discussion_id','=','discussions.id');
        $type=(string)($p['type'] ?? ''); if ($type && !isset(Records::CATEGORIES[$type]) && $type!=='discussion') throw new ValidationException(['type'=>'检索类型无效']);
        if ($type==='discussion') $q->whereNull('c.id'); elseif ($type) $q->where('c.type',$type);
        foreach (['category','course_id','semester','section_id'] as $key) if (!empty($p[$key])) $q->where('c.'.$key,$p[$key]);
        foreach (['course','topic','grade','tags'] as $key) if (!empty($p[$key])) $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(c.data, '$.$key')) LIKE ?",['%'.addcslashes(mb_substr((string)$p[$key],0,100),'\\%_').'%']);
        if (!empty($p['tag'])) $q->whereHas('tags',fn($b)=>$b->where('slug',mb_substr((string)$p['tag'],0,100)));
        if (!empty($p['status'])) {
            Records::statusFilter($q,mb_substr((string)$p['status'],0,30),'c.');
        }
        foreach (['from','to','deadline_from','deadline_to','starts_from','starts_to'] as $key) if (!empty($p[$key])) {
            $v=(string)$p[$key]; $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$v); if (!$date || $date->format('Y-m-d')!==$v) throw new ValidationException([$key=>'请填写有效日期']);
            $column=str_starts_with($key,'deadline')?'c.deadline':(str_starts_with($key,'starts')?'c.starts_at':'discussions.created_at');
            $q->where($column,str_ends_with($key,'to')?'<=':'>=',$v.(str_ends_with($key,'to')?' 23:59:59':' 00:00:00'));
        }
        $term=mb_substr(trim((string)($p['q'] ?? '')),0,100); $pattern='%'.addcslashes($term,'\\%_').'%';
        if ($term) $q->where(fn($b)=>$b->where('discussions.title','like',$pattern)->orWhere('c.data','like',$pattern)->orWhereIn('discussions.id',Post::query()->whereVisibleTo($a)->where('type','comment')->where('content','like',$pattern)->select('discussion_id')));
        return $q;
    }
}
