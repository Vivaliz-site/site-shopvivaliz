<?php
declare(strict_types=1);

/**
 * Input Validation & Sanitization
 *
 * Provides comprehensive input validation and sanitization for common data types.
 * All user input should be validated through this class.
 */

class InputValidator
{
    private array $errors = [];
    private array $validated = [];

    /**
     * Validate and get a required string input
     *
     * @param string $key Input key name
     * @param int $minLength Minimum length
     * @param int $maxLength Maximum length
     * @param string $name Display name for errors
     * @return string|null Sanitized string or null if invalid
     */
    public function requireString(
        string $key,
        int $minLength = 1,
        int $maxLength = 255,
        string $name = ''
    ): ?string {
        $value = $this->getString($key, '', $minLength, $maxLength);

        if ($value === '') {
            $displayName = $name ?: $key;
            $this->errors[$key] = "{$displayName} is required and must be between {$minLength}-{$maxLength} characters";
            return null;
        }

        return $value;
    }

    /**
     * Validate and get an optional string input
     *
     * @param string $key Input key name
     * @param string $default Default value if not provided
     * @param int $minLength Minimum length (0 for optional)
     * @param int $maxLength Maximum length
     * @return string Sanitized string or default value
     */
    public function getString(
        string $key,
        string $default = '',
        int $minLength = 0,
        int $maxLength = 255
    ): string {
        $value = $this->getInputValue($key, '');
        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);

        // Check length if value provided
        if ($value !== '' && (strlen($value) < $minLength || strlen($value) > $maxLength)) {
            return $default;
        }

        // Sanitize: remove null bytes and control characters
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;

        $this->validated[$key] = $value;
        return $value;
    }

    /**
     * Validate and get an email address
     *
     * @param string $key Input key name
     * @param bool $required Whether email is required
     * @return string|null Valid email or null if invalid
     */
    public function getEmail(string $key, bool $required = false): ?string
    {
        $value = $this->getInputValue($key, '');
        if (!is_string($value)) {
            return $required ? null : '';
        }

        $value = trim(strtolower($value));

        if ($value === '') {
            return $required ? null : '';
        }

        // Validate email format
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$key] = 'Invalid email format';
            return null;
        }

        // Max email length per RFC 5321
        if (strlen($value) > 254) {
            $this->errors[$key] = 'Email address too long';
            return null;
        }

        $this->validated[$key] = $value;
        return $value;
    }

    /**
     * Validate and get an integer
     *
     * @param string $key Input key name
     * @param int $default Default value
     * @param int|null $min Minimum value (inclusive)
     * @param int|null $max Maximum value (inclusive)
     * @return int Validated integer or default
     */
    public function getInteger(
        string $key,
        int $default = 0,
        ?int $min = null,
        ?int $max = null
    ): int {
        $value = $this->getInputValue($key, $default);

        if (is_int($value)) {
            $intVal = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $intVal = (int)$value;
        } else {
            return $default;
        }

        if ($min !== null && $intVal < $min) {
            return $default;
        }

        if ($max !== null && $intVal > $max) {
            return $default;
        }

        $this->validated[$key] = $intVal;
        return $intVal;
    }

    /**
     * Validate and get a float/decimal
     *
     * @param string $key Input key name
     * @param float $default Default value
     * @param float|null $min Minimum value
     * @param float|null $max Maximum value
     * @return float Validated float or default
     */
    public function getFloat(
        string $key,
        float $default = 0.0,
        ?float $min = null,
        ?float $max = null
    ): float {
        $value = $this->getInputValue($key, $default);

        if (is_numeric($value)) {
            $floatVal = (float)$value;
        } else {
            return $default;
        }

        if ($min !== null && $floatVal < $min) {
            return $default;
        }

        if ($max !== null && $floatVal > $max) {
            return $default;
        }

        $this->validated[$key] = $floatVal;
        return $floatVal;
    }

    /**
     * Validate and get a boolean
     *
     * @param string $key Input key name
     * @param bool $default Default value
     * @return bool Validated boolean
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->getInputValue($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return $default;
    }

    /**
     * Validate and get a URL
     *
     * @param string $key Input key name
     * @param string $default Default URL
     * @return string Valid URL or default
     */
    public function getUrl(string $key, string $default = ''): string
    {
        $value = $this->getString($key, '');

        if ($value === '') {
            return $default;
        }

        // Basic URL validation - must be valid URL format
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$key] = 'Invalid URL format';
            return $default;
        }

        // Ensure HTTPS or HTTP only
        if (!preg_match('#^https?://#i', $value)) {
            $this->errors[$key] = 'URL must start with http:// or https://';
            return $default;
        }

        $this->validated[$key] = $value;
        return $value;
    }

    /**
     * Validate and get a phone number (basic validation)
     *
     * Accepts numbers, hyphens, spaces, and parentheses
     *
     * @param string $key Input key name
     * @param string $default Default phone
     * @return string Sanitized phone number
     */
    public function getPhone(string $key, string $default = ''): string
    {
        $value = $this->getString($key, $default, 0, 20);

        if ($value === '') {
            return $default;
        }

        // Remove common formatting characters but keep digits
        $phone = preg_replace('/[^\d\s\-().]/', '', $value) ?? $value;
        $phone = trim($phone);

        // Must have at least 10 digits
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            return $default;
        }

        $this->validated[$key] = $phone;
        return $phone;
    }

    /**
     * Validate that a value is one of allowed options
     *
     * @param string $key Input key name
     * @param array $allowed Allowed values
     * @param string $default Default value if not in allowed list
     * @return string Validated value or default
     */
    public function getEnum(string $key, array $allowed = [], string $default = ''): string
    {
        $value = $this->getString($key, $default);

        if (!in_array($value, $allowed, true)) {
            if ($default === '' && !empty($allowed)) {
                $this->errors[$key] = 'Invalid value. Must be one of: ' . implode(', ', $allowed);
            }
            return $default;
        }

        $this->validated[$key] = $value;
        return $value;
    }

    /**
     * Validate money/currency amount
     *
     * @param string $key Input key name
     * @param float $default Default amount
     * @param float $maxAmount Maximum allowed amount
     * @return float Validated amount
     */
    public function getMoney(string $key, float $default = 0.0, float $maxAmount = 999999.99): float
    {
        $value = $this->getFloat($key, $default, 0.0, $maxAmount);

        // Round to 2 decimal places
        $value = round($value, 2);

        $this->validated[$key] = $value;
        return $value;
    }

    /**
     * Check if validation has errors
     *
     * @return bool True if any validation errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get all validation errors
     *
     * @return array Errors array (key => error message)
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get error for specific field
     *
     * @param string $key Field name
     * @return string|null Error message or null
     */
    public function getError(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    /**
     * Get all validated data
     *
     * @return array Validated input data
     */
    public function getValidated(): array
    {
        return $this->validated;
    }

    /**
     * Get raw input value from GET/POST/REQUEST
     *
     * @param string $key Input key name
     * @param mixed $default Default value if not found
     * @return mixed Input value or default
     */
    private function getInputValue(string $key, mixed $default = null): mixed
    {
        // Check POST first, then GET, then REQUEST
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }
}

/**
 * Helper function: Create InputValidator instance
 *
 * @return InputValidator Validator instance
 */
function validator(): InputValidator
{
    return new InputValidator();
}
