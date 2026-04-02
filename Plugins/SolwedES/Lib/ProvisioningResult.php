<?php
/**
 * Plugin SolwedES - Result object for provisioning operations
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

/**
 * Value object representing the result of a provisioning operation
 */
class ProvisioningResult
{
    /** @var bool Whether the provisioning was successful */
    public bool $success;

    /** @var string|null Error message if provisioning failed */
    public ?string $error;

    /** @var string|null WordPress admin URL */
    public ?string $adminUrl;

    /** @var string|null WordPress admin username */
    public ?string $username;

    /** @var string|null WordPress admin password (only available right after creation) */
    public ?string $password;

    /** @var string|null FTP username */
    public ?string $ftpUser;

    /** @var string|null FTP password */
    public ?string $ftpPassword;

    /** @var string|null Database name */
    public ?string $dbName;

    /** @var string|null Database user */
    public ?string $dbUser;

    /** @var array Additional metadata */
    public array $metadata;

    public function __construct()
    {
        $this->success = false;
        $this->error = null;
        $this->adminUrl = null;
        $this->username = null;
        $this->password = null;
        $this->ftpUser = null;
        $this->ftpPassword = null;
        $this->dbName = null;
        $this->dbUser = null;
        $this->metadata = [];
    }

    /**
     * Creates a successful result
     *
     * @param array $data Result data
     * @return self
     */
    public static function success(array $data = []): self
    {
        $result = new self();
        $result->success = true;
        $result->adminUrl = $data['admin_url'] ?? null;
        $result->username = $data['username'] ?? null;
        $result->password = $data['password'] ?? null;
        $result->ftpUser = $data['ftp_user'] ?? null;
        $result->ftpPassword = $data['ftp_password'] ?? null;
        $result->dbName = $data['db_name'] ?? null;
        $result->dbUser = $data['db_user'] ?? null;
        $result->metadata = $data['metadata'] ?? [];

        return $result;
    }

    /**
     * Creates a failed result
     *
     * @param string $error Error message
     * @param array $metadata Additional context
     * @return self
     */
    public static function failure(string $error, array $metadata = []): self
    {
        $result = new self();
        $result->success = false;
        $result->error = $error;
        $result->metadata = $metadata;

        return $result;
    }

    /**
     * Convert to array for logging/storage
     *
     * @param bool $includeSensitive Include sensitive data (passwords)
     * @return array
     */
    public function toArray(bool $includeSensitive = false): array
    {
        $data = [
            'success' => $this->success,
            'error' => $this->error,
            'admin_url' => $this->adminUrl,
            'username' => $this->username,
            'ftp_user' => $this->ftpUser,
            'db_name' => $this->dbName,
            'db_user' => $this->dbUser,
            'metadata' => $this->metadata,
        ];

        if ($includeSensitive) {
            $data['password'] = $this->password;
            $data['ftp_password'] = $this->ftpPassword;
        }

        return $data;
    }
}
