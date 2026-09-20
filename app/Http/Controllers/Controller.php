<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Schema;

class Controller extends BaseController
{
    use DispatchesJobs, ValidatesRequests, ApiResponse;

    /** 表名 => 列名清单，按进程缓存（Octane 下随 worker 存活） */
    private static array $sortableColumns = [];

    /**
     * 判断排序字段是否为该表真实存在的列。
     *
     * 后台列表的 sort[].id 直接来自请求，未经校验就丢进 orderBy()：
     * Laravel 的标识符包裹能挡住注入，但仍会让攻击者用报错探测表结构，
     * 这里统一收敛成“必须是本表真实列名”。
     */
    protected function isSortableColumn(EloquentBuilder|QueryBuilder $builder, mixed $field): bool
    {
        if (!is_string($field) || !preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            return false;
        }

        $table = $builder instanceof EloquentBuilder
            ? $builder->getModel()->getTable()
            : (string) $builder->from;

        if (!isset(self::$sortableColumns[$table])) {
            self::$sortableColumns[$table] = Schema::getColumnListing($table);
        }

        return in_array($field, self::$sortableColumns[$table], true);
    }

    /**
     * 统一处理 sort=[{id,desc}] 形式的排序参数
     */
    protected function applySortParam($request, EloquentBuilder|QueryBuilder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        foreach ((array) $request->input('sort') as $sort) {
            if (!is_array($sort) || !isset($sort['id'])) {
                continue;
            }

            if (!$this->isSortableColumn($builder, $sort['id'])) {
                continue;
            }

            $builder->orderBy($sort['id'], !empty($sort['desc']) ? 'DESC' : 'ASC');
        }
    }
}
