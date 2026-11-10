<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackFilePath
{
    public static function privateDocument($relativePath)
    {
        return self::within('runtime/uploads/private', $relativePath);
    }

    public static function privateCertificate($relativePath)
    {
        return self::within('runtime/uploads/certificates', $relativePath);
    }

    private static function within($relativeRoot, $relativePath)
    {
        $relativePath = (string) $relativePath;
        if ($relativePath === '' || strpos($relativePath, "\0") !== false || preg_match('#^[A-Za-z]:#', $relativePath)) {
            return null;
        }

        $root = realpath(ROOT_DIR . $relativeRoot);
        $path = realpath(ROOT_DIR . ltrim($relativePath, '/\\'));
        if (!$root || !$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedRoot = str_replace('\\', '/', $rootPrefix);
        $normalizedPath = str_replace('\\', '/', $path);
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
        $comparisonPath = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
        return strpos($comparisonPath, $comparisonRoot) === 0 ? $path : null;
    }
}
