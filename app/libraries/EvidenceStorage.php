<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class EvidenceStorage
{
    public static function directory()
    {
        $configuredDirectory = getenv('BAYANALERT_EVIDENCE_DIR');
        $directory = is_string($configuredDirectory) && trim($configuredDirectory) !== ''
            ? trim($configuredDirectory)
            : ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence';

        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function expectedMimeType($filename)
    {
        if (!is_string($filename) ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp|mp4|pdf)\z/i', $filename) !== 1) {
            return null;
        }

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
        ];

        return $mimeTypes[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
    }
}
