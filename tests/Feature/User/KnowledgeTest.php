<?php

namespace Tests\Feature\User;

use App\Models\Knowledge;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->email = 'reader@example.com';
        $this->user->password = bcrypt('secret123');
        $this->user->uuid = Helper::guid(true);
        $this->user->token = Helper::guid();
        $this->user->save();

        Knowledge::create([
            'language' => 'zh-CN', 'category' => '使用教程', 'title' => 'Clash 配置',
            'body' => '订阅地址：{{subscribeUrl}}', 'show' => 1, 'sort' => 1,
        ]);
        Knowledge::create([
            'language' => 'zh-CN', 'category' => '常见问题', 'title' => '无法连接',
            'body' => '请检查网络', 'show' => 1, 'sort' => 2,
        ]);
        // 隐藏的文章，其分类不应出现
        Knowledge::create([
            'language' => 'zh-CN', 'category' => '内部文档', 'title' => '草稿',
            'body' => '未发布', 'show' => 0, 'sort' => 3,
        ]);
        // 其它语言，指定语言时不应出现
        Knowledge::create([
            'language' => 'en-US', 'category' => 'Guides', 'title' => 'Clash setup',
            'body' => 'Your link: {{subscribeUrl}}', 'show' => 1, 'sort' => 1,
        ]);
    }

    /**
     * 这条路由一直存在，但用户端控制器此前没有对应方法，调用会直接抛异常。
     */
    public function test_get_category_returns_visible_categories(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/knowledge/getCategory?language=zh-CN')
            ->assertStatus(200);

        $categories = $response->json('data');

        $this->assertContains('使用教程', $categories);
        $this->assertContains('常见问题', $categories);
        // show=0 的文章不该暴露分类
        $this->assertNotContains('内部文档', $categories);
        // 指定语言后不混入其它语言
        $this->assertNotContains('Guides', $categories);
    }

    public function test_get_category_without_language_spans_all_languages(): void
    {
        $categories = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/knowledge/getCategory')
            ->assertStatus(200)
            ->json('data');

        $this->assertContains('使用教程', $categories);
        $this->assertContains('Guides', $categories);
        $this->assertNotContains('内部文档', $categories);
    }

    public function test_list_is_grouped_by_category(): void
    {
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/knowledge/fetch?language=zh-CN')
            ->assertStatus(200)
            ->json('data');

        $this->assertArrayHasKey('使用教程', $data);
        $this->assertArrayHasKey('常见问题', $data);
        $this->assertArrayNotHasKey('内部文档', $data);
    }

    public function test_article_body_has_placeholders_replaced(): void
    {
        $id = Knowledge::where('title', 'Clash 配置')->value('id');

        $body = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/user/knowledge/fetch?id={$id}")
            ->assertStatus(200)
            ->json('data.body');

        $this->assertStringNotContainsString('{{subscribeUrl}}', $body);
        $this->assertStringContainsString($this->user->token, $body);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/knowledge/getCategory')->assertStatus(403);
    }
}
