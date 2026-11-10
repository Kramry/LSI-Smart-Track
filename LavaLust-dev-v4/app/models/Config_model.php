<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Config_model extends Base_model
{
    public function getConfig($key, $default = null)
    {
        $config = $this->db->table('certificate_config')
            ->select('config_value')
            ->where('config_key', $key)
            ->get();
        return $config ? $config['config_value'] : $default;
    }

    public function updateConfig($key, $value, $description = null)
    {
        $check = $this->db->table('certificate_config')
            ->select('config_id')
            ->where('config_key', $key)
            ->get();
        if ($check) {
            $this->execute('UPDATE certificate_config SET config_value = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE config_key = ?', [$value, $description, $key]);
        } else {
            $this->db->table('certificate_config')->insert([
                'config_key' => $key,
                'config_value' => $value,
                'description' => $description,
            ]);
        }
    }
}
