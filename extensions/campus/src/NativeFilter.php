<?php
namespace UCASSer\Campus;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Foundation\ValidationException;
class NativeFilter implements FilterInterface
{
    public function getFilterKey(): string { return 'campus'; }
    public function filter(SearchState $state,string|array $value,bool $negate): void {
        if (!is_string($value) || strlen($value)>4000) throw new ValidationException(['campus'=>'筛选条件无效']);
        $params=json_decode($value,true);
        if (!is_array($params)) throw new ValidationException(['campus'=>'筛选条件无效']);
        foreach ($params as $parameter) if (!is_scalar($parameter) && $parameter!==null) throw new ValidationException(['campus'=>'筛选条件无效']);
        $query=SearchQuery::build($state->getActor(),$params)->select('discussions.id');
        $state->getQuery()->whereIn('discussions.id',$query,'and',$negate);
    }
}
