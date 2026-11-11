<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackViewContext
{
    public static function prepare($page, array $data, $user, $session, $userCount, $appUrl, SmartTrackPermissions $permissions)
    {
        if ($page === 'module') {
            $section = $data['section'] ?? '';
            $definition = SmartTrackModuleCatalog::definition($section);
            $role = $user['role'] ?? '';
            $data['can_manage'] = $data['can_manage'] ?? $permissions->canManage($role, $section);
            $data['can_create'] = $data['can_create'] ?? false;
            $data['primary_key'] = $data['primary_key'] ?? ($definition['key'] ?? '');
            $data['fields'] = $data['fields'] ?? [];
            $data['editing'] = $data['editing'] ?? null;
            $data['records'] = $data['records'] ?? [];
            $data['columns'] = $data['columns'] ?? [];
            $data['options'] = $data['options'] ?? [];
        }

        $data['page'] = $page;
        $data['user'] = $user;
        $data['csrf'] = $session->userdata('csrf');
        $data['setup_required'] = $userCount === 0;
        $data['app_url'] = $appUrl;
        $data['notice'] = $session->flashdata('notice');
        $data['flash_error'] = $session->flashdata('error');
        $data['navigation'] = $permissions->navigation($user['role'] ?? '');

        return $data;
    }

    public static function viewName($page)
    {
        return in_array($page, ['classes', 'class_detail', 'ai_management'], true) ? $page : 'smarttrack';
    }
}
