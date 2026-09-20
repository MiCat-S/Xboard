<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReCaptcha\ReCaptcha;

class CaptchaService
{
    /**
     * 验证人机验证码
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    public function verify(Request $request): array
    {
        if (!(int) admin_setting('captcha_enable', 0)) {
            return [true, null];
        }

        $captchaType = admin_setting('captcha_type', 'recaptcha');

        return match ($captchaType) {
            'turnstile' => $this->verifyTurnstile($request),
            'recaptcha-v3' => $this->verifyRecaptchaV3($request),
            'recaptcha' => $this->verifyRecaptcha($request),
            default => [false, [400, __('Invalid captcha type')]]
        };
    }

    /**
     * 验证 Cloudflare Turnstile
     *
     * @param Request $request
     * @return array
     */
    private function verifyTurnstile(Request $request): array
    {
        $turnstileToken = $request->input('turnstile_token');
        if (!$turnstileToken) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        try {
            $response = Http::timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => admin_setting('turnstile_secret_key'),
                'response' => $turnstileToken,
                'remoteip' => $request->ip()
            ]);
        } catch (\Throwable $e) {
            // 校验服务不可达时必须判定为失败，不能放行
            Log::warning('Turnstile verification request failed: ' . $e->getMessage());
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $result = $response->json();
        if (!is_array($result) || empty($result['success'])) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v3
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptchaV3(Request $request): array
    {
        $recaptchaV3Token = $request->input('recaptcha_v3_token');
        if (!$recaptchaV3Token) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_v3_secret_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaV3Token, $request->ip());

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        // 检查分数阈值。缺少分数时按 0 分处理（转型即可），不能默认放行。
        $score = (float) $recaptchaResp->getScore();
        $threshold = (float) admin_setting('recaptcha_v3_score_threshold', 0.5);
        if ($score < $threshold) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v2
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptcha(Request $request): array
    {
        $recaptchaData = $request->input('recaptcha_data');
        if (!$recaptchaData) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaData);

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }
} 