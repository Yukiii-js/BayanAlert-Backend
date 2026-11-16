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
        $range = trim((string) ($this->request->header('Range') ?? ''));

        if ($range !== '') {
            if ($fileSize === 0 ||
                preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) !== 1 ||
                ($matches[1] === '' && $matches[2] === '')) {
                $this->response->add_header('Content-Range', 'bytes */' . $fileSize);
                api_error('Requested evidence byte range is not satisfiable.', 416);
            }

            if ($matches[1] === '') {
                $suffixLength = (int) $matches[2];
                if ($suffixLength < 1) {
                    $this->response->add_header('Content-Range', 'bytes */' . $fileSize);
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
                $this->response->add_header('Content-Range', 'bytes */' . $fileSize);
                api_error('Requested evidence byte range is not satisfiable.', 416);
            }

            $status = 206;
            $this->response->add_header('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize);
        }

        $file = fopen($filePath, 'rb');
        if ($file === false) {
            api_error('Evidence file could not be opened.', 500);
        }

        if (fseek($file, $start) !== 0) {
            fclose($file);
            api_error('Evidence file could not be opened.', 500);
        }

        $this->response->set_status_code($status);
        $this->response->add_header([
            'Content-Type' => $expectedMimeType,
            'Content-Length' => $end - $start + 1,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff'
        ]);
        $this->response->send_headers();

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
