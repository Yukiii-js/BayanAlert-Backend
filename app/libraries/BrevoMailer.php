<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class BrevoMailer
{
    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    private const DEPARTMENTS = [
        'DOH' => [
            'name' => 'Department of Health',
            'email' => 'doh.calapan@gmail.com',
        ],
        'BFP' => [
            'name' => 'Bureau of Fire Protection',
            'email' => 'calapan.firedepartment@gmail.com',
        ],
        'CDRRMO' => [
            'name' => 'City Disaster Risk Reduction and Management Office',
            'email' => 'calapan.disastermanagement@gmail.com',
        ],
        'PCG' => [
            'name' => 'Philippine Coast Guard',
            'email' => 'coastguard.calapan@gmail.com',
        ],
    ];

    /**
     * Send a transactional email through Brevo and return its queue result.
     */
    public function sendEmail($recipientEmail, $recipientName, $subject, $htmlContent, $textContent = '')
    {
        $apiKey = trim((string) getenv('BREVO_API_KEY'));
        $senderEmail = trim((string) getenv('BREVO_SENDER_EMAIL'));
        $senderName = trim((string) getenv('BREVO_SENDER_NAME'));

        if ($apiKey === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || $senderName === '') {
            error_log('Brevo email was not sent: BREVO_API_KEY, BREVO_SENDER_EMAIL, or BREVO_SENDER_NAME is not configured.');
            return ['success' => false, 'error' => 'Transactional email is not configured.'];
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('Brevo email was not sent: invalid recipient address.');
            return ['success' => false, 'error' => 'The email recipient is invalid.'];
        }

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [[
                'email' => $recipientEmail,
                'name' => $recipientName !== '' ? $recipientName : $recipientEmail,
            ]],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];
        if ($textContent !== '') {
            $payload['textContent'] = $textContent;
        }

        try {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('Brevo email was not sent: message content could not be encoded.');
            return ['success' => false, 'error' => 'Email content could not be prepared.'];
        }

        $curl = curl_init(self::API_URL);
        if ($curl === false) {
            error_log('Brevo email failed: unable to initialize cURL.');
            return ['success' => false, 'error' => 'Email service is temporarily unavailable.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
        ]);

        $responseBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (!is_string($responseBody) || $statusCode < 200 || $statusCode >= 300) {
            error_log(
                'Brevo email request failed: HTTP ' . $statusCode .
                ($curlError !== '' ? ' (' . $curlError . ')' : '')
            );
            return ['success' => false, 'error' => 'Email could not be queued by the provider.'];
        }

        $response = json_decode($responseBody, true);
        $messageId = is_array($response) ? (string) ($response['messageId'] ?? '') : '';
        error_log(
            'Brevo email queued successfully' .
            ($messageId !== '' ? ' (message ID ' . $messageId . ').' : '.')
        );

        return [
            'success' => true,
            'message_id' => $messageId,
        ];
    }

    /**
     * Send a time-limited account verification link.
     */
    public function sendVerificationEmail($email, $fullName, $token)
    {
        $frontendUrl = rtrim(trim((string) getenv('FRONTEND_URL')), '/');
        if ($frontendUrl === '') {
            error_log('Brevo verification email was not sent: FRONTEND_URL is not configured.');
            return ['success' => false, 'error' => 'The verification link is not configured.'];
        }

        $verificationUrl = $frontendUrl . '/verify-email?token=' . rawurlencode($token);
        $safeName = $this->escape($fullName);
        $safeUrl = $this->escape($verificationUrl);
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<h2>Verify your BayanAlert email</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Confirm this email address to complete your BayanAlert registration. This link expires in 24 hours.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#059669;color:#fff;text-decoration:none;border-radius:8px">Verify email address</a></p>'
            . '<p>If the button does not work, copy this link into your browser:<br><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
            . '<p>If you did not create this account, you can ignore this message.</p>'
            . '</body></html>';

        return $this->sendEmail(
            $email,
            $fullName,
            'Verify your BayanAlert email address',
            $html,
            "Hello {$fullName},\n\nVerify your BayanAlert email address within 24 hours:\n{$verificationUrl}\n\nIf you did not create this account, ignore this message."
        );
    }

    /**
     * Route an approved report to the appropriate emergency department.
     */
    public function sendHazardNotificationToDepartment(array $hazardReportData)
    {
        $departmentCode = $this->getDepartmentForCategory(
            (string) ($hazardReportData['category_name'] ?? '')
        );
        $recipientEmail = isset($hazardReportData['notification_email'])
            ? trim((string) $hazardReportData['notification_email'])
            : ($departmentCode !== null ? self::DEPARTMENTS[$departmentCode]['email'] : '');

        if ($departmentCode === null && $recipientEmail === '') {
            $category = trim((string) ($hazardReportData['category_name'] ?? 'Uncategorized'));
            error_log('No emergency department email route matches hazard category: ' . $category);
            return ['success' => false, 'error' => 'No department email route matches this hazard category.'];
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'The department notification recipient is invalid.'];
        }

        $department = $departmentCode !== null
            ? self::DEPARTMENTS[$departmentCode]
            : ['name' => 'Emergency response contact', 'email' => $recipientEmail];
        $escape = function ($value) {
            return $this->escape((string) ($value ?? 'Not provided'));
        };
        $coordinates = 'Not provided';
        $latitude = $hazardReportData['latitude'] ?? null;
        $longitude = $hazardReportData['longitude'] ?? null;
        if (is_numeric($latitude) && is_numeric($longitude)) {
            $coordinates = $latitude . ', ' . $longitude;
            $mapUrl = 'https://maps.google.com/?q=' . rawurlencode($latitude . ',' . $longitude);
            $coordinates = '<a href="' . $this->escape($mapUrl) . '">' . $this->escape($coordinates) . '</a>';
        } else {
            $coordinates = $this->escape($coordinates);
        }

        $rows = [
            'Report reference' => $escape($hazardReportData['reference_number'] ?? ''),
            'Report title' => $escape($hazardReportData['title'] ?? ''),
            'Category' => $escape($hazardReportData['category_name'] ?? ''),
            'Location' => $escape($hazardReportData['address_details'] ?? ''),
            'Coordinates' => $coordinates,
            'Severity level' => $escape($hazardReportData['severity_level'] ?? 'Not specified'),
            'Reporter' => $escape($hazardReportData['reporter_name'] ?? 'Not provided'),
            'Reported at' => $escape($hazardReportData['submitted_at'] ?? ''),
        ];

        $tableRows = '';
        $textRows = [];
        foreach ($rows as $label => $value) {
            $tableRows .= '<tr><th align="left" style="padding:8px;border:1px solid #dbe3e8">' .
                $this->escape($label) . '</th><td style="padding:8px;border:1px solid #dbe3e8">' .
                $value . '</td></tr>';
            $textRows[] = $label . ': ' . strip_tags($value);
        }

        $description = $escape($hazardReportData['description'] ?? '');
        $tableRows .= '<tr><th align="left" style="padding:8px;border:1px solid #dbe3e8">Description</th>'
            . '<td style="padding:8px;border:1px solid #dbe3e8;white-space:pre-wrap">' . $description . '</td></tr>';
        $textRows[] = 'Description: ' . strip_tags($description);

        $mediaLinks = [];
        foreach (($hazardReportData['media'] ?? []) as $media) {
            $url = $this->publicMediaUrl((string) ($media['file_path'] ?? ''));
            if ($url === '') {
                continue;
            }
            $label = (string) ($media['evidence_type'] ?? '') === 'video' ? 'Video evidence' : 'Photo evidence';
            $mediaLinks[] = '<li><a href="' . $this->escape($url) . '">' .
                $this->escape($label . ' ' . (count($mediaLinks) + 1)) . '</a></li>';
            $textRows[] = $label . ': ' . $url;
        }

        $mediaHtml = $mediaLinks
            ? '<h3>Attached media</h3><ul>' . implode('', $mediaLinks) . '</ul>'
            : '<p>No media was attached to this report.</p>';
        $subject = 'Approved BayanAlert hazard report: ' .
            trim((string) ($hazardReportData['reference_number'] ?? 'Report'));
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.5">'
            . '<h2>Approved hazard report for ' . $this->escape($department['name']) . '</h2>'
            . '<p>An administrator approved a hazard report for response review.</p>'
            . '<table style="border-collapse:collapse;width:100%">' . $tableRows . '</table>'
            . $mediaHtml
            . '<p style="color:#64748b;font-size:12px">BayanAlert · Calapan City, Oriental Mindoro</p>'
            . '</body></html>';

        $result = $this->sendEmail(
            $recipientEmail,
            $department['name'],
            $subject,
            $html,
            implode("\n", $textRows)
        );
        if ($result['success']) {
            $result['department'] = $departmentCode;
        }
        return $result;
    }

    public function getDepartmentForCategory($category)
    {
        $category = strtolower($category);
        $routes = [
            'DOH' => ['medical', 'health', 'injury', 'illness'],
            'BFP' => ['fire', 'smoke', 'burning'],
            'PCG' => ['maritime', 'drowning', 'coastal', 'boat', 'sea rescue'],
            'CDRRMO' => ['flood', 'typhoon', 'storm', 'landslide', 'earthquake', 'disaster', 'weather'],
        ];

        foreach ($routes as $department => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($category, $keyword) !== false) {
                    return $department;
                }
            }
        }

        return null;
    }

    public function getDepartmentDetailsForCategory($category)
    {
        $departmentCode = $this->getDepartmentForCategory($category);
        return $departmentCode !== null ? self::DEPARTMENTS[$departmentCode] + ['code' => $departmentCode] : null;
    }

    private function publicMediaUrl($filePath)
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $filePath)) {
            return $filePath;
        }

        $filePath = ltrim(str_replace('\\', '/', $filePath), '/');
        $baseUrl = rtrim(trim((string) getenv('APP_BASE_URL')), '/');
        return $baseUrl !== '' ? $baseUrl . '/' . $filePath : $filePath;
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
