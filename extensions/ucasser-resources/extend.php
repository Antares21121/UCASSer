<?php

use Flarum\Extend;
use UCASSer\Resources\Api\CategoriesController;
use UCASSer\Resources\Api\ResourcesController;
use UCASSer\Resources\Api\ReportsController;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/forum.js')
        ->css(__DIR__.'/css/forum.css')
        ->route('/resources', 'ucasser.resources'),
    (new Extend\Routes('api'))
        ->get('/ucasser/categories', 'ucasser.categories.index', CategoriesController::class)
        ->post('/ucasser/categories', 'ucasser.categories.create', CategoriesController::class)
        ->patch('/ucasser/categories/{id}', 'ucasser.categories.update', CategoriesController::class)
        ->get('/ucasser/resources', 'ucasser.resources.index', ResourcesController::class)
        ->post('/ucasser/resources', 'ucasser.resources.create', ResourcesController::class)
        ->patch('/ucasser/resources/{id}', 'ucasser.resources.update', ResourcesController::class)
        ->get('/ucasser/reports', 'ucasser.reports.index', ReportsController::class)
        ->post('/ucasser/reports', 'ucasser.reports.create', ReportsController::class)
        ->patch('/ucasser/reports/{id}', 'ucasser.reports.update', ReportsController::class),
];
