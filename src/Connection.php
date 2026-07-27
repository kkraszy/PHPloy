<?php

namespace Banago\PHPloy;

use League\Flysystem\Ftp\FtpAdapter as FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions as FtpConnectionOptions;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter as SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoKeyLoadedException;

/**
 * Class Connection.
 */
class Connection
{
    /**
     * @var Filesystem
     */
    public $server;
    /**
     * The modes uploads are actually chmodded to, for reporting.
     *
     * @var array
     */
    public $permissions = [];
    /**
     * @var ConnectionProvider
     */
    private $provider;

    /**
     * Connection constructor.
     *
     * @param string $server
     *
     * @throws \Exception
     *
     * @return Connection
     */
    public function __construct($server)
    {
        if (!isset($server['scheme'])) {
            throw new \Exception("Please provide a connection protocol such as 'ftp' or 'sftp'.");
        }

        if ($server['scheme'] === 'ftp' or $server['scheme'] === 'ftps') {
            $this->server = $this->connectToFtp($server);
        } elseif ($server['scheme'] === 'sftp') {
            $this->server = $this->connectToSftp($server);
        } else {
            throw new \Exception("Please provide a known connection protocol such as 'ftp' or 'sftp'.");
        }
    }

    private function getCommonOptions($server)
    {
        $options = [
            'host' => $server['host'],
            'username' => $server['user'],
            'password' => $server['pass'],
            'root' => $server['path'],
            'timeout' => ($server['timeout'] ?: 300),
            'permPrivate' => $this->getPermission($server, 'permPrivate', 0640),
            'permPublic' => $this->getPermission($server, 'permPublic', 0644),
            'directoryPerm' => $this->getPermission($server, 'directoryPerm', 0755),
        ];

        // "permissions" is the documented (deploy.ini) knob for file permissions:
        // it applies to every uploaded file, so it overrides both file modes and
        // the visibility converter chmods uploads to it whatever the visibility is.
        if ($server['permissions'] !== null && $server['permissions'] !== '') {
            $options['permPublic'] = $this->getPermission($server, 'permissions', 0644);
            $options['permPrivate'] = $options['permPublic'];
        }

        $this->permissions = [
            'file' => ($server['visibility'] ?? 'public') === 'private'
                ? $options['permPrivate']
                : $options['permPublic'],
            'directory' => $options['directoryPerm'],
        ];

        return $options;
    }

    /**
     * Reads a permission from the configuration as a chmod mode.
     *
     * @param array  $server
     * @param string $key
     * @param int    $default
     *
     * @throws \Exception if the configured value is not a permission
     *
     * @return int
     */
    private function getPermission($server, $key, $default)
    {
        $value = isset($server[$key]) ? $server[$key] : null;

        if ($value === null || $value === '') {
            return $default;
        }

        $mode = parse_permission($value);

        if ($mode === null) {
            throw new \Exception(
                "Invalid '{$key}' value in the configuration: '{$value}'. ".
                'Please use an octal mode such as 0644.'
            );
        }

        return $mode;
    }

    /**
     * Builds the default Flysystem config so that visibility (and therefore the
     * configured permissions) is applied on every write/createDirectory call.
     *
     * Without this, the FTP/SFTP adapters skip chmod entirely and uploaded files
     * keep whatever permissions the server's umask (or the local files) provide.
     *
     * @param array $server
     *
     * @return array
     */
    private function getDefaultConfig($server)
    {
        $visibility = $server['visibility'] ?: 'public';

        return [
            'visibility' => $visibility,
            'directory_visibility' => $visibility,
        ];
    }

    /**
     * Builds the visibility converter that maps the configured deploy.ini
     * permissions onto uploaded files and created directories.
     *
     * @param array $options
     *
     * @return PortableVisibilityConverter
     */
    private function getVisibilityConverter($options)
    {
        return PortableVisibilityConverter::fromArray([
            'file' => [
                'public' => $options['permPublic'] ?? 0644,
                'private' => $options['permPrivate'] ?? 0640,
            ],
            'dir' => [
                'public' => $options['directoryPerm'] ?? 0755,
                'private' => $options['directoryPerm'] ?? 0755,
            ],
        ]);
    }

    /**
     * Connects to the FTP Server.
     *
     * @param string $server
     *
     * @throws \Exception if it can't connect to FTP server
     *
     * @return Filesystem
     */
    protected function connectToFtp($server)
    {
        try {
            $options = $this->getCommonOptions($server);
            // parse_ini_file() turns "false"/"no"/"off" into an empty string, so
            // booleans have to go through filter_var() to be read correctly.
            $options['passive'] = $server['passive'] === null
              ? true
              : filter_var($server['passive'], FILTER_VALIDATE_BOOLEAN);

            $options['ssl'] = filter_var($server['ssl'], FILTER_VALIDATE_BOOLEAN);

            $options['port'] = (intval($server['port'] ?: 21));


            $ftp_options = FtpConnectionOptions::fromArray($options);

            $ftpAdapter = new FtpAdapter(
                $ftp_options,
                null,
                null,
                $this->getVisibilityConverter($options)
            );

            return new Filesystem($ftpAdapter, $this->getDefaultConfig($server));
        } catch (\Exception $e) {
            throw new \Exception("Could not connect to FTP server '{$server['host']}': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Connects to the SFTP Server.
     *
     * @param string $server
     *
     * @throws \Exception if it can't connect to FTP server
     *
     * @return Filesystem
     */
    protected function connectToSftp($server)
    {
        if (!empty($server['privkey']) && '~' === $server['privkey'][0] && getenv('HOME') !== null) {
            $server['privkey'] = substr_replace($server['privkey'], getenv('HOME'), 0, 1);
        }

        if (!empty($server['privkey']) && !is_file($server['privkey']) && "---" !== substr($server['privkey'], 0, 3)) {
            throw new \Exception("Private key {$server['privkey']} doesn't exists.");
        }

        $options = $this->getCommonOptions($server);
        $options['privateKey'] = $server['privkey'];
        $options['port'] = ($server['port'] ?: 22);

        $useAgent = filter_var($server['agent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // The passphrase is resolved before connecting so that a locked key is
        // reported as such instead of failing later as an unexplained
        // "unable to authenticate".
        $passphrase = null;
        if (!empty($options['privateKey']) && !$useAgent) {
            $passphrase = $this->resolvePassphrase($options['privateKey'], $options['password']);
        }

        try {
            $this->provider = new SftpConnectionProvider(
                $options['host'],
                $options['username'],
                (empty($options['privateKey']) && !$useAgent) ? $options['password'] : null, // password
                (!empty($options['privateKey']) && !$useAgent) ? $options['privateKey'] : null, // key
                $passphrase,
                $options['port'],
                $useAgent
            );

            // Connect eagerly: the provider is lazy, so without this an
            // authentication failure would only surface much later as a
            // confusing "unable to check existence for .revision".
            $this->provider->provideConnection();

            $visibilityConverter = $this->getVisibilityConverter($options);

            return new Filesystem(
                new SftpAdapter($this->provider, $options['root'], $visibilityConverter),
                $this->getDefaultConfig($server)
            );
        } catch (\Throwable $e) {
            throw new \Exception("Could not connect to SFTP server '{$server['host']}': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Returns a passphrase that actually unlocks the given private key.
     *
     * Passphrases are deliberately never read from - nor written to - a file:
     * the configured one is tried first, then PHPLOY_PRIVKEY_PASS (for CI), and
     * finally the user is asked interactively. It only lives in memory.
     *
     * @param string $privkey  path to, or contents of, the private key
     * @param string $password the 'pass' value configured for the server
     *
     * @throws \Exception if the key cannot be loaded
     *
     * @return string|null the working passphrase, null if the key is not encrypted
     */
    private function resolvePassphrase($privkey, $password)
    {
        $key = $this->readPrivateKey($privkey);

        // An unencrypted key loads with no passphrase at all; passing one anyway
        // is harmless but pointless, so report it as "none needed".
        if ($this->keyUnlocks($key, null)) {
            return null;
        }

        foreach ([$password, getenv('PHPLOY_PRIVKEY_PASS')] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->keyUnlocks($key, $candidate)) {
                return $candidate;
            }
        }

        if (!$this->isEncryptedKey($key)) {
            throw new \Exception("Private key '{$privkey}' could not be read. Is it a valid OpenSSH/PEM private key?");
        }

        if (!$this->canPrompt()) {
            throw new \Exception(
                "Private key '{$privkey}' is protected with a passphrase and there is no terminal to ask for it. ".
                'Set the PHPLOY_PRIVKEY_PASS environment variable or use an ssh-agent (agent = true).'
            );
        }

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            fwrite(STDOUT, "Enter passphrase for private key '{$privkey}': ");
            $passphrase = input_password();
            fwrite(STDOUT, "\r\n");

            if ($this->keyUnlocks($key, $passphrase)) {
                return $passphrase;
            }

            fwrite(STDOUT, "Wrong passphrase, please try again.\r\n");
        }

        throw new \Exception("Could not unlock private key '{$privkey}': wrong passphrase.");
    }

    /**
     * Reads the private key, which may be given as a path or as its contents.
     *
     * @param string $privkey
     *
     * @return string the key contents
     */
    private function readPrivateKey($privkey)
    {
        if ('---' !== substr($privkey, 0, 3) && is_file($privkey)) {
            return (string) file_get_contents($privkey);
        }

        return $privkey;
    }

    /**
     * @param string      $key        the private key contents
     * @param string|null $passphrase
     *
     * @return bool true if the key can be loaded with this passphrase
     */
    private function keyUnlocks($key, $passphrase)
    {
        try {
            PublicKeyLoader::load($key, $passphrase === null ? false : $passphrase);

            return true;
        } catch (NoKeyLoadedException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tells an encrypted key apart from an unreadable one, so that the user is
     * only asked for a passphrase when one can actually help.
     *
     * @param string $key the private key contents
     *
     * @return bool
     */
    private function isEncryptedKey($key)
    {
        // PKCS#1 ("BEGIN RSA PRIVATE KEY") and PKCS#8 announce encryption in
        // plain text.
        if (false !== strpos($key, 'ENCRYPTED')) {
            return true;
        }

        // The OpenSSH format keeps the cipher name inside the base64 body.
        if (!preg_match('#-----BEGIN OPENSSH PRIVATE KEY-----(.+)-----END#s', $key, $matches)) {
            return false;
        }

        $body = base64_decode(preg_replace('#\s+#', '', $matches[1]), true);
        if ($body === false || 0 !== strpos($body, "openssh-key-v1\0")) {
            return false;
        }

        // magic + 4 byte length, then the cipher name; "none" means unencrypted.
        $cipher = substr($body, 19, unpack('N', substr($body, 15, 4))[1]);

        return $cipher !== 'none';
    }

    /**
     * @return bool true if a passphrase can be asked for interactively
     */
    private function canPrompt()
    {
        if (!defined('STDIN') || !defined('STDOUT')) {
            return false;
        }

        return function_exists('stream_isatty') ? stream_isatty(STDIN) : true;
    }

    /**
     * @return ConnectionProvider
     */
    public function getConnectionProvider()
    {
        return $this->provider;
    }
}
