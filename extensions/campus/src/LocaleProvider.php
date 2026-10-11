<?php
namespace UCASSer\Campus;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Locale\LocaleManager;

class LocaleProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving(LocaleManager::class, function (LocaleManager $locales): void {
            $locales->addLocale('zh-Hans', '简体中文');
        });
    }
}
