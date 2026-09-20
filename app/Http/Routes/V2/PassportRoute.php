<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            // 这些都是未认证入口，按 IP 限流，防止撞库、邮件轰炸与验证码枚举。
            // 业务层原有的按邮箱计数只挡得住单账号，挡不住换账号横扫。
            $router->post('/auth/register', [AuthController::class, 'register'])
                ->middleware('throttle:10,1');
            $router->post('/auth/login', [AuthController::class, 'login'])
                ->middleware('throttle:10,1');
            $router->get('/auth/token2Login', [AuthController::class, 'token2Login'])
                ->middleware('throttle:30,1');
            $router->post('/auth/forget', [AuthController::class, 'forget'])
                ->middleware('throttle:10,1');
            $router->post('/auth/getQuickLoginUrl', [AuthController::class, 'getQuickLoginUrl'])
                ->middleware('throttle:20,1');
            $router->post('/auth/loginWithMailLink', [AuthController::class, 'loginWithMailLink'])
                ->middleware('throttle:5,1');
            // Comm
            $router->post('/comm/sendEmailVerify', [CommController::class, 'sendEmailVerify'])
                ->middleware('throttle:5,1');
            $router->post('/comm/pv', [CommController::class, 'pv'])
                ->middleware('throttle:30,1');
        });
    }
}
