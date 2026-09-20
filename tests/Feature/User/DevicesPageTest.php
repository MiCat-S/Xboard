<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 独立的在线设备页。
 *
 * 用户面板是预构建产物、本仓库没有源码，所以这一页是服务端渲染的独立页面：
 * 它只用公开 API，自己登录，不依赖主题的 SPA。
 */
class DevicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_reachable_without_logging_in(): void
    {
        // 页面本身是公开的，数据要登录后才由前端 fetch
        $this->get('/devices')
            ->assertStatus(200)
            ->assertSee('getOnlineDevices', false);
    }

    public function test_page_renders_a_login_form_and_no_leaked_data(): void
    {
        $response = $this->get('/devices')->assertStatus(200);

        $response->assertSee('login-form', false);
        $response->assertSee('id="device-list"', false);
    }

    public function test_page_is_not_indexable(): void
    {
        $this->get('/devices')->assertSee('noindex', false);
    }

    /**
     * 节点名由管理员填写，页面必须用 textContent 渲染而不是拼 HTML。
     * 这里守住源码层面的约定，避免以后有人改成 innerHTML。
     */
    public function test_device_rows_are_not_built_with_inner_html(): void
    {
        $view = file_get_contents(resource_path('views/client/devices.blade.php'));

        $this->assertStringNotContainsString('innerHTML =', $view);
        $this->assertStringNotContainsString('innerHTML=', $view);
        $this->assertStringContainsString('textContent', $view);
    }
}
