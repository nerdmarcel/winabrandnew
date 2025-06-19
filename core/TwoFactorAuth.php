<?php
declare(strict_types=1);

/**
 * File: core/TwoFactorAuth.php
 * Location: core/TwoFactorAuth.php
 *
 * WinABN Two-Factor Authentication Implementation
 *
 * Provides TOTP (Time-based One-Time Password) functionality for admin 2FA.
 * Compatible with Google Authenticator, Authy, and other TOTP apps.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class TwoFactorAuth
{
    /**
     * Base32 alphabet for secret encoding
     *
     * @var string
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * TOTP time step in seconds (standard is 30 seconds)
     *
     * @var int
     */
    private const TIME_STEP = 30;

    /**
     * Code length (6 digits is standard)
     *
     * @var int
     */
    private const CODE_LENGTH = 6;

    /**
     * Number of time windows to check for clock skew tolerance
     *
     * @var int
     */
    private const WINDOW_SIZE = 1;

    /**
     * Generate a new TOTP secret
     *
     * @param int $length Secret length (recommended: 32 characters)
     * @return string Base32 encoded secret
     */
    public static function generateSecret(int $length = 32): string
    {
        if ($length % 8 !== 0) {
            throw new Exception('Secret length must be divisible by 8');
        }

        $secret = '';
        $alphabetLength = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    /**
     * Generate TOTP code for current time
     *
     * @param string $secret Base32 encoded secret
     * @param int|null $timestamp Unix timestamp (null for current time)
     * @return string 6-digit TOTP code
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $timeCounter = intval($timestamp / self::TIME_STEP);
        return self::generateTotpCode($secret, $timeCounter);
    }

    /**
     * Verify TOTP code
     *
     * @param string $secret Base32 encoded secret
     * @param string $code User provided code
     * @param int|null $timestamp Unix timestamp (null for current time)
     * @param int $window Time window tolerance (default: 1 = ±30 seconds)
     * @return bool True if code is valid
     */
    public static function verifyCode(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW_SIZE): bool
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        // Remove any spaces or formatting from the code
        $code = preg_replace('/\s+/', '', $code);

        if (strlen($code) !== self::CODE_LENGTH || !ctype_digit($code)) {
            return false;
        }

        $currentTimeCounter = intval($timestamp / self::TIME_STEP);

        // Check current time window and adjacent windows for clock skew
        for ($i = -$window; $i <= $window; $i++) {
            $timeCounter = $currentTimeCounter + $i;
            $expectedCode = self::generateTotpCode($secret, $timeCounter);

            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate QR code URL for TOTP setup
     *
     * @param string $secret Base32 encoded secret
     * @param string $accountName Account identifier (usually email)
     * @param string $issuer Service name
     * @param string|null $qrProvider QR code provider URL
     * @return string QR code image URL
     */
    public static function generateQrCodeUrl(string $secret, string $accountName, string $issuer = 'WinABN', ?string $qrProvider = null): string
    {
        $otpAuthUrl = self::generateOtpAuthUrl($secret, $accountName, $issuer);

        if ($qrProvider === null) {
            $qrProvider = 'https://api.qrserver.com/v1/create-qr-code/';
        }

        return $qrProvider . '?' . http_build_query([
            'size' => '200x200',
            'data' => $otpAuthUrl,
            'ecc' => 'M',
            'margin' => '0'
        ]);
    }

    /**
     * Generate OTP Auth URL for authenticator apps
     *
     * @param string $secret Base32 encoded secret
     * @param string $accountName Account identifier
     * @param string $issuer Service name
     * @return string OTP Auth URL
     */
    public static function generateOtpAuthUrl(string $secret, string $accountName, string $issuer = 'WinABN'): string
    {
        $parameters = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::CODE_LENGTH,
            'period' => self::TIME_STEP
        ];

        $parameterString = http_build_query($parameters);

        return sprintf(
            'otpauth://totp/%s:%s?%s',
            urlencode($issuer),
            urlencode($accountName),
            $parameterString
        );
    }

    /**
     * Generate backup codes for account recovery
     *
     * @param int $count Number of backup codes to generate
     * @param int $length Length of each code
     * @return array<string> Array of backup codes
     */
    public static function generateBackupCodes(int $count = 10, int $length = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';

            // Generate alphanumeric backup codes
            for ($j = 0; $j < $length; $j++) {
                if ($j > 0 && $j % 4 === 0) {
                    $code .= '-'; // Add separator every 4 characters
                }

                // Use numbers and uppercase letters (excluding confusing characters)
                $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Verify backup code
     *
     * @param string $providedCode User provided backup code
     * @param array<string> $validCodes Array of valid backup codes
     * @return string|false Used backup code if valid, false otherwise
     */
    public static function verifyBackupCode(string $providedCode, array $validCodes)
    {
        // Normalize the provided code (remove spaces, convert to uppercase)
        $providedCode = strtoupper(preg_replace('/[\s-]/', '', $providedCode));

        foreach ($validCodes as $validCode) {
            $normalizedValidCode = strtoupper(preg_replace('/[\s-]/', '', $validCode));

            if (hash_equals($normalizedValidCode, $providedCode)) {
                return $validCode; // Return the original format
            }
        }

        return false;
    }

    /**
     * Generate TOTP code for specific time counter
     *
     * @param string $secret Base32 encoded secret
     * @param int $timeCounter Time counter value
     * @return string 6-digit TOTP code
     */
    private static function generateTotpCode(string $secret, int $timeCounter): string
    {
        // Decode the secret from base32
        $secretBytes = self::base32Decode($secret);

        // Convert time counter to 8-byte big-endian format
        $timeBytes = pack('N*', 0, $timeCounter);

        // Generate HMAC-SHA1 hash
        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0xf;

        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::CODE_LENGTH);

        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Decode base32 string
     *
     * @param string $input Base32 encoded string
     * @return string Decoded binary data
     */
    private static function base32Decode(string $input): string
    {
        if (empty($input)) {
            return '';
        }

        $input = strtoupper($input);
        $alphabet = self::BASE32_ALPHABET;
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];

            // Skip padding characters
            if ($char === '=') {
                break;
            }

            $val = strpos($alphabet, $char);
            if ($val === false) {
                throw new Exception("Invalid character in base32 string: $char");
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $output .= chr(($buffer >> ($bitsLeft - 8)) & 255);
                $bitsLeft -= 8;
            }
        }

        return $output;
    }

    /**
     * Encode binary data to base32
     *
     * @param string $input Binary data
     * @return string Base32 encoded string
     */
    public static function base32Encode(string $input): string
    {
        if (empty($input)) {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $buffer = ($buffer << 8) | ord($input[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $output .= $alphabet[($buffer >> ($bitsLeft - 5)) & 31];
                $bitsLeft -= 5;
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
        }

        // Add padding
        $padLength = (8 - (strlen($output) % 8)) % 8;
        $output .= str_repeat('=', $padLength);

        return $output;
    }

    /**
     * Get remaining time until next code generation
     *
     * @param int|null $timestamp Current timestamp
     * @return int Seconds until next code
     */
    public static function getRemainingTime(?int $timestamp = null): int
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        return self::TIME_STEP - ($timestamp % self::TIME_STEP);
    }

    /**
     * Validate secret format
     *
     * @param string $secret Base32 encoded secret
     * @return bool True if secret is valid
     */
    public static function isValidSecret(string $secret): bool
    {
        if (empty($secret)) {
            return false;
        }

        // Check if secret contains only valid base32 characters
        return preg_match('/^[A-Z2-7=]+$/', strtoupper($secret)) === 1;
    }

    /**
     * Create a secure random secret using system entropy
     *
     * @param int $byteLength Number of random bytes (will be base32 encoded)
     * @return string Base32 encoded secret
     */
    public static function createSecureSecret(int $byteLength = 20): string
    {
        if ($byteLength < 10) {
            throw new Exception('Secret must be at least 10 bytes for security');
        }

        $randomBytes = random_bytes($byteLength);
        return self::base32Encode($randomBytes);
    }

    /**
     * Get TOTP configuration info
     *
     * @return array<string, mixed> Configuration details
     */
    public static function getConfiguration(): array
    {
        return [
            'time_step' => self::TIME_STEP,
            'code_length' => self::CODE_LENGTH,
            'window_size' => self::WINDOW_SIZE,
            'algorithm' => 'SHA1',
            'base32_alphabet' => self::BASE32_ALPHABET
        ];
    }

    /**
     * Generate multiple codes for testing (development only)
     *
     * @param string $secret Base32 encoded secret
     * @param int $count Number of codes to generate
     * @param int $startOffset Time offset in seconds from current time
     * @return array<array<string, mixed>> Array of codes with timestamps
     */
    public static function generateTestCodes(string $secret, int $count = 5, int $startOffset = -60): array
    {
        if (!is_debug()) {
            throw new Exception('Test code generation only available in debug mode');
        }

        $codes = [];
        $currentTime = time();

        for ($i = 0; $i < $count; $i++) {
            $timestamp = $currentTime + $startOffset + ($i * self::TIME_STEP);
            $code = self::generateCode($secret, $timestamp);

            $codes[] = [
                'code' => $code,
                'timestamp' => $timestamp,
                'time_formatted' => date('Y-m-d H:i:s', $timestamp),
                'valid_until' => $timestamp + self::TIME_STEP,
                'is_current' => $timestamp <= $currentTime && $currentTime < ($timestamp + self::TIME_STEP)
            ];
        }

        return $codes;
    }
}
