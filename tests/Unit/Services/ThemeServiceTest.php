<?php

namespace Tests\Unit\Services;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「当前启用的是哪个主题」这个问题在代码里有两个来源：渲染端
 * （routes/web.php）读 frontend_theme，而 switch() 历史上只写
 * current_theme。两者不同步时，后台的切换按钮点了没反应，而删除防护
 * 会把正在用的主题当成「没人用」。
 */
class ThemeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ThemeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThemeService();
    }

    public function test_the_renderers_key_wins(): void
    {
        admin_setting(['frontend_theme' => 'Nova', 'current_theme' => 'Xboard']);

        // routes/web.php 实际渲染的是 frontend_theme，所以它说了算
        $this->assertSame('Nova', $this->service->activeTheme());
    }

    public function test_it_falls_back_to_current_theme(): void
    {
        admin_setting(['current_theme' => 'Nova']);

        $this->assertSame('Nova', $this->service->activeTheme());
    }

    /** 老站点两个键都没设过，渲染端此时回落到 Xboard，这里必须一致 */
    public function test_an_untouched_install_reports_the_default(): void
    {
        $this->assertSame('Xboard', $this->service->activeTheme());
    }

    /**
     * 防护原本读的是 current_theme。只设了 frontend_theme 的站点（也就是
     * 通过后台配置表单切主题的那些），能把正在用的主题删掉，站点当场 500。
     */
    public function test_the_active_theme_cannot_be_deleted_when_only_the_renderers_key_is_set(): void
    {
        // 用一个非系统主题，否则会先撞上「系统主题不可删」那条
        admin_setting(['frontend_theme' => 'MyTheme']);

        $this->expectExceptionMessage('Current theme cannot be deleted');
        $this->service->delete('MyTheme');
    }

    /** 随仓库发布的主题一律不可删 */
    public function test_shipped_themes_are_protected(): void
    {
        admin_setting(['frontend_theme' => 'Xboard']);

        $this->expectExceptionMessage('System theme cannot be deleted');
        $this->service->delete('Nova');
    }
}
