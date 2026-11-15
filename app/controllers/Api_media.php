<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/EvidenceStorage.php';

class Api_media extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function evidence($filename)
    {
        $expectedMimeType = EvidenceStorage::expectedMimeType($filename);
        if ($expectedMimeType === null) {
            api_error('Evidence file not found.', 404);
        }

        $filePath = EvidenceStorage::directory() . $filename;
        if (!is_file($filePath) || !is_readable($filePath)) {
            api_error('Evidence file not found.', 404);
        }

        $actualMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if ($actualMimeType !== $expectedMimeType) {
            api_error('Evidence file could not be served.', 415);
        }

        $fileSize = filesize($filePath);
        if (!is_int($fileSize) || $fileSize < 0) {
            api_error('Evidence file could not be read.', 500);
        }

        $start = 0;
        $end = $fileSize - 1;
        $status = 200;
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

        if ($range !== '') {
            if ($fileSize === 0 ||
                preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) !== 1 ||
                ($matches[1] === '' && $matches[2] === '')) {
                header('Content-Range: bytes */' . $fileSize);
                api_error('Requested evidence byte range is not satisfiable.', 416);
            }

            if ($matches[1] === '') {
                $suffixLength = (int) $matches[2];
                if ($suffixLength < 1) {
                    header('Content-Range: bytes */' . $fileSize);
                    api_error('Requested evidence byte range is not satisfiable.', 416);
                }
                $start = max(0, $fileSize - $suffixLength);
            } else {
                $start = (int) $matches[1];
            }

            if ($matches[1] !== '' && $matches[2] !== '') {
                $end = min((int) $matches[2], $fileSize - 1);
            }

            if ($start >= $fileSize || $start > $end) {
                header('Content-Range: bytes */' . $fileSize);
                api_error('Requested evidence byte range is not satisfiable.', 416);
            }

            $status = 206;
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        }

        $file = fopen($filePath, 'rb');
        if ($file === false) {
            api_error('Evidence file could not be opened.', 500);
        }

        if (fseek($file, $start) !== 0) {
            fclose($file);
            api_error('Evidence file could not be opened.', 500);
        }

        http_response_code($status);
        header('Content-Type: ' . $expectedMimeType);
        header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400, immutable');
        header('X-Content-Type-Options: nosniff');

        $remaining = $end - $start + 1;
        while ($remaining > 0) {
            $chunk = fread($file, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                fclose($file);
                error_log('Evidence media stream ended before the requested content was read.');
                exit;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($file);
        exit;
    }
}
