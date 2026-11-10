<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackAuth
{
    public function resolveApiUser($api, User_model $userModel)
    {
        $token = $api->get_jwt_data();
        if (!is_array($token) || !isset($token['sub']) || !ctype_digit((string) $token['sub'])) {
            return null;
        }

        $account = $userModel->apiUserById((int) $token['sub']);
        if (!$account || ($account['status'] ?? '') !== 'active') {
            return null;
        }

        return [
            'user_id' => (int) $account['user_id'],
            'full_name' => $account['full_name'],
            'username' => $account['username'],
            'role' => $account['role'],
        ];
    }

    public function setRefreshTokenCookie($token)
    {
        setcookie('smarttrack_refresh', (string) $token, $this->refreshCookieOptions(time() + $this->refreshTokenLifetime()));
    }

    public function clearRefreshTokenCookie()
    {
        setcookie('smarttrack_refresh', '', $this->refreshCookieOptions(time() - 3600));
    }

    private function refreshCookieOptions($expires)
    {
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $secure = filter_var(getenv('SESSION_COOKIE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN)
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $forwardedProto === 'https';
        $sameSite = getenv('SESSION_COOKIE_SAMESITE') ?: ($secure ? 'None' : 'Lax');
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        return [
            'expires' => $expires,
            'path' => '/api',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    private function refreshTokenLifetime()
    {
        $expiresIn = (int) (config_item('refresh_token_expiration') ?: 604800);
        return $expiresIn > 0 ? $expiresIn : 604800;
    }
}
