<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExternalApiConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SystemApiConfig.php';

class BrevoMailer
{
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
        $settings = $this->resolveSettings();
        if (!$settings['enabled']) {
            $this->logError('Brevo email was not sent because the integration is disabled in API Management.');
            return ['success' => false, 'error' => 'Transactional email is disabled by an administrator.'];
        }

        if ($settings['api_key'] === '') {
            $this->logError('Brevo email was not sent: BREVO_API_KEY is not configured in system_apis or the environment.');
            return ['success' => false, 'error' => 'Brevo API key is not configured. Add it in Admin API Management or set BREVO_API_KEY on Render.'];
        }
        if (preg_match('/[\r\n\x00]/', $settings['api_key'])) {
            $this->logError('Brevo email was not sent: the configured API key contains invalid header characters.');
            return ['success' => false, 'error' => 'Brevo API key configuration is invalid. Check the saved key or BREVO_API_KEY on Render.'];
        }
        if (!filter_var($settings['sender_email'], FILTER_VALIDATE_EMAIL) || $settings['sender_name'] === '') {
            $this->logError('Brevo email was not sent: a valid sender email and sender name are required.');
            return ['success' => false, 'error' => 'Brevo sender email or sender name is not configured. Set BREVO_SENDER_EMAIL and BREVO_SENDER_NAME on Render.'];
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logError('Brevo email was not sent: invalid recipient address.');
            return ['success' => false, 'error' => 'The email recipient is invalid.'];
        }

        $payload = [
            'sender' => [
                'name' => $settings['sender_name'],
                'email' => $settings['sender_email'],
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

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonPayload)) {
            $this->logError('Brevo email request failed: payload JSON encoding failed.');
            return ['success' => false, 'error' => 'The verification email could not be prepared. Please try again.'];
        }

        try {
            $response = SystemApiConfig::request(
                'https://api.brevo.com/v3/smtp/email',
                [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'api-key: ' . $settings['api_key'],
                ],
                15,
                'POST',
                $jsonPayload
            );
        } catch (Throwable $exception) {
            $this->logError('Brevo HTTPS request failed: ' . $exception->getMessage());
            return ['success' => false, 'error' => 'Brevo email service is temporarily unavailable. Check the backend HTTPS connection and try again.'];
        }

        if ($response['error'] !== null || !is_string($response['body'])
            || $response['status'] < 200 || $response['status'] >= 300) {
            $providerMessage = $this->providerErrorMessage($response['body']);
            $this->logError(
                'Brevo email request failed with HTTP ' . $response['status'] .
                ($providerMessage !== '' ? ': ' . $providerMessage : '') .
                ($response['error'] !== null ? ' (' . $response['error'] . ')' : '')
            );
            if (in_array((int) $response['status'], [400, 401, 403], true)) {
                return ['success' => false, 'error' => 'Brevo rejected the email. Check the API key and verified sender email in Admin API Management or Render environment variables.'];
            }
            return ['success' => false, 'error' => 'Brevo email service is temporarily unavailable. Please try again.'];
        }

        $decodedResponse = json_decode($response['body'], true);
        $messageId = is_array($decodedResponse) ? (string) ($decodedResponse['messageId'] ?? '') : '';
        $this->logInfo(
            'Brevo email queued successfully' .
            ($messageId !== '' ? ' (message ID ' . $messageId . ').' : '.')
        );

        return [
            'success' => true,
            'message_id' => $messageId,
        ];
    }

    public function sendRegistrationOtpEmail($email, $code)
    {
        $safeCode = $this->escape($code);
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<h2>Verify your BayanAlert email</h2>'
            . '<p>Enter this verification code on the registration page:</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:8px">' . $safeCode . '</p>'
            . '<p>This code expires in 10 minutes. If you did not request it, you can ignore this message.</p>'
            . '</body></html>';

        return $this->sendEmail(
            $email,
            $email,
            'Your BayanAlert verification code',
            $html,
            "Your BayanAlert verification code is {$code}. It expires in 10 minutes."
        );
    }

    private function resolveSettings()
    {
        $databaseSettings = [];
        $enabled = null;
        try {
            $row = lava_instance()->db->table('system_apis')
                ->select('credential_encrypted, custom_parameters_json, is_enabled')
                ->where('api_key', 'brevo')
                ->limit(1)
                ->get();
            if ($row) {
                $enabled = (int) $row['is_enabled'] === 1;
                if ($enabled) {
                    try {
                        $databaseKey = SystemApiConfig::decryptSecret(
                            $row['credential_encrypted'] ?? null
                        );
                        if ($databaseKey !== '') {
                            $databaseSettings['api_key'] = $databaseKey;
                        }
                    } catch (Throwable $exception) {
                        $this->logError('Unable to decrypt the Brevo API key from system_apis: ' . $exception->getMessage());
                    }
                    $parameters = json_decode((string) ($row['custom_parameters_json'] ?? '{}'), true);
                    if (is_array($parameters)) {
                        $senderEmail = trim((string) (
                            $parameters['sender_email'] ?? $parameters['BREVO_SENDER_EMAIL'] ?? ''
                        ));
                        $senderName = trim((string) (
                            $parameters['sender_name'] ?? $parameters['BREVO_SENDER_NAME'] ?? ''
                        ));
                        if ($senderEmail !== '') {
                            $databaseSettings['sender_email'] = $senderEmail;
                        }
                        if ($senderName !== '') {
                            $databaseSettings['sender_name'] = $senderName;
                        }
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->logError('Unable to load Brevo API Management settings; falling back to environment values: ' . $exception->getMessage());
        }

        $legacyEnabled = true;
        try {
            $legacyEnabled = ExternalApiConfig::isEnabled(lava_instance()->db, 'brevo');
        } catch (Throwable $exception) {
            $this->logError('Unable to load legacy Brevo enablement state: ' . $exception->getMessage());
        }

        return [
            'enabled' => $enabled !== false && $legacyEnabled,
            'api_key' => $databaseSettings['api_key'] ?? $this->environmentValue('BREVO_API_KEY'),
            'sender_email' => $databaseSettings['sender_email'] ?? $this->environmentValue('BREVO_SENDER_EMAIL'),
            'sender_name' => $databaseSettings['sender_name'] ?? $this->environmentValue('BREVO_SENDER_NAME'),
        ];
    }

    private function environmentValue($name)
    {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        $value = $_ENV[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function providerErrorMessage($body)
    {
        if (!is_string($body) || $body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return substr(strip_tags($body), 0, 300);
        }
        $message = $decoded['message'] ?? $decoded['code'] ?? '';
        if (is_array($message)) {
            $message = implode('; ', array_map('strval', $message));
        }
        return substr(trim((string) $message), 0, 300);
    }

    private function logError($message)
    {
        if (function_exists('log_message')) {
            log_message('error', $message);
            return;
        }
        if (function_exists('load_class')) {
            $logger = load_class('logger', 'kernel');
            if (is_object($logger) && method_exists($logger, 'log')) {
                $logger->log('error', 'BrevoMailer', $message, __FILE__, __LINE__);
                return;
            }
        }
        error_log($message);
    }

    private function logInfo($message)
    {
        if (function_exists('log_message')) {
            log_message('info', $message);
            return;
        }
        if (function_exists('load_class')) {
            $logger = load_class('logger', 'kernel');
            if (is_object($logger) && method_exists($logger, 'log')) {
                $logger->log('debug', 'BrevoMailer', $message, __FILE__, __LINE__);
                return;
            }
        }
        error_log($message);
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

    public function sendPasswordResetEmail($email, $fullName, $token)
    {
        $frontendUrl = rtrim(trim((string) getenv('FRONTEND_URL')), '/');
        if ($frontendUrl === '') {
            error_log('Brevo password reset email was not sent: FRONTEND_URL is not configured.');
            return ['success' => false, 'error' => 'The password reset link is not configured.'];
        }

        $resetUrl = $frontendUrl . '/reset-password?token=' . rawurlencode($token);
        $safeName = $this->escape($fullName);
        $safeUrl = $this->escape($resetUrl);
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<h2>Reset your BayanAlert password</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Use the link below to choose a new password. This link expires in one hour and can only be used once.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#059669;color:#fff;text-decoration:none;border-radius:8px">Reset password</a></p>'
            . '<p>If the button does not work, copy this link into your browser:<br><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
            . '<p>If you did not request a password reset, you can ignore this message.</p>'
            . '</body></html>';

        return $this->sendEmail(
            $email,
            $fullName,
            'Reset your BayanAlert password',
            $html,
            "Hello {$fullName},\n\nUse the link below to reset your BayanAlert password within one hour:\n{$resetUrl}\n\nIf you did not request a password reset, ignore this message."
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
        $department = $departmentCode !== null
            ? $this->getDepartmentDetails($departmentCode)
            : null;
        $recipientEmail = isset($hazardReportData['notification_email'])
            ? trim((string) $hazardReportData['notification_email'])
            : (string) ($department['email'] ?? '');

        if ($departmentCode === null && $recipientEmail === '') {
            $category = trim((string) ($hazardReportData['category_name'] ?? 'Uncategorized'));
            error_log('No emergency department email route matches hazard category: ' . $category);
            return ['success' => false, 'error' => 'No department email route matches this hazard category.'];
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'The department notification recipient is invalid.'];
        }

        $contactByEmail = $this->getStationDetailsByEmail($recipientEmail);
        if ($contactByEmail !== null) {
            $department = $contactByEmail;
        }
        if ($department === null) {
            $department = ['name' => 'Emergency response contact', 'email' => $recipientEmail];
        }
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
            $result['department'] = $department['code'] ?? $departmentCode;
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
        return $departmentCode !== null ? $this->getDepartmentDetails($departmentCode) : null;
    }

    private function getDepartmentDetails($departmentCode)
    {
        $fallback = self::DEPARTMENTS[$departmentCode] + ['code' => $departmentCode];
        try {
            $station = lava_instance()->db->table('stations')
                ->select('department_code, station_name, contact_email, address_details, contact_number, station_info')
                ->where('department_code', $departmentCode)
                ->where('is_active', 1)
                ->get();
            if ($station && filter_var(trim((string) ($station['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
                return [
                    'code' => $station['department_code'],
                    'name' => $station['station_name'],
                    'email' => $station['contact_email'],
                    'address' => $station['address_details'] ?? '',
                    'contact_number' => $station['contact_number'] ?? '',
                    'info' => $station['station_info'] ?? '',
                ];
            }
        } catch (\Throwable $error) {
            error_log('Unable to load response station contact for ' . $departmentCode . ': ' . $error->getMessage());
        }

        return $fallback;
    }

    private function getStationDetailsByEmail($email)
    {
        try {
            $station = lava_instance()->db->table('stations')
                ->select('department_code, station_name, contact_email, address_details, contact_number, station_info')
                ->where('contact_email', $email)
                ->where('is_active', 1)
                ->get();
            if ($station) {
                return [
                    'code' => $station['department_code'],
                    'name' => $station['station_name'],
                    'email' => $station['contact_email'],
                    'address' => $station['address_details'] ?? '',
                    'contact_number' => $station['contact_number'] ?? '',
                    'info' => $station['station_info'] ?? '',
                ];
            }
        } catch (\Throwable $error) {
            error_log('Unable to resolve response station email: ' . $error->getMessage());
        }

        return null;
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
        if (strpos($filePath, 'uploads/evidence/') === 0) {
            $filename = substr($filePath, strlen('uploads/evidence/'));
            return $baseUrl !== ''
                ? $baseUrl . '/api/media/evidence/' . rawurlencode($filename)
                : '';
        }

        return $baseUrl !== '' ? $baseUrl . '/' . $filePath : $filePath;
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
