<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Config_model extends Base_model
{
    public function getConfig($key, $default = null)
    {
        $config = $this->row('SELECT config_value FROM certificate_config WHERE config_key = ?', [$key]);
        return $config ? $config['config_value'] : $default;
    }

    public function updateConfig($key, $value, $description = null)
    {
        $check = $this->row('SELECT config_id FROM certificate_config WHERE config_key = ?', [$key]);
        if ($check) {
            $this->execute('UPDATE certificate_config SET config_value = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE config_key = ?', [$value, $description, $key]);
        } else {
            $this->execute('INSERT INTO certificate_config (config_key, config_value, description) VALUES (?, ?, ?)', [$key, $value, $description]);
        }
    }
}
