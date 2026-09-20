<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;

/**
 * 绑定/解绑都发生在 Telegram 机器人里（/bind、/unbind），面板这边只提供
 * 机器人信息。原先这里还有一个 unbind()：查的列名是错的（user_id）、
 * 没有返回值、路由也没注册，属于死代码，已删除。
 */
class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();

        return $this->success([
            'username' => $response->result->username,
        ]);
    }
}
