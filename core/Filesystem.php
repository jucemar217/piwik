<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik;

use Exception;
use Piwik\Tracker\Cache;

/**
 * Contains helper functions that involve the filesystem.
 * 
 * @package Piwik
 */
class Filesystem
{
    /**
     * Called on Core install, update, plugin enable/disable
     * Will clear all cache that could be affected by the change in configuration being made
     */
    public static function deleteAllCacheOnUpdate()
    {
        AssetManager::removeMergedAssets();
        View::clearCompiledTemplates();
        Cache::deleteTrackerCache();
    }

    /**
     * ending WITHOUT slash
     *
     * @return string
     */
    public static function getPathToPiwikRoot()
    {
        return realpath(dirname(__FILE__) . "/..");
    }

    /**
     * Create .htaccess file in specified directory
     *
     * Apache-specific; for IIS @see web.config
     *
     * @param string $path without trailing slash
     * @param bool $overwrite whether to overwrite an existing file or not
     * @param string $content
     */
    public static function createHtAccess($path, $overwrite = true, $content = "<Files \"*\">\n<IfModule mod_access.c>\nDeny from all\n</IfModule>\n<IfModule !mod_access_compat>\n<IfModule mod_authz_host.c>\nDeny from all\n</IfModule>\n</IfModule>\n<IfModule mod_access_compat>\nDeny from all\n</IfModule>\n</Files>\n")
    {
        if (SettingsServer::isApache()) {
            $file = $path . '/.htaccess';
            if ($overwrite || !file_exists($file)) {
                @file_put_contents($file, $content);
            }
        }
    }

    /**
     * Returns true if the string is a valid filename
     * File names that start with a-Z or 0-9 and contain a-Z, 0-9, underscore(_), dash(-), and dot(.) will be accepted.
     * File names beginning with anything but a-Z or 0-9 will be rejected (including .htaccess for example).
     * File names containing anything other than above mentioned will also be rejected (file names with spaces won't be accepted).
     *
     * @param string $filename
     * @return bool
     */
    public static function isValidFilename($filename)
    {
        return (0 !== preg_match('/(^[a-zA-Z0-9]+([a-zA-Z_0-9.-]*))$/D', $filename));
    }

    /**
     * Get canonicalized absolute path
     * See http://php.net/realpath
     *
     * @param string $path
     * @return string  canonicalized absolute path
     */
    public static function realpath($path)
    {
        if (file_exists($path)) {
            return realpath($path);
        }
        return $path;
    }

    /**
     * Attempts to create a new directory. All errors are silenced.
     * 
     * Note: This function does not create directories recursively.
     *
     * @param string $path The path of the directory to create.
     * @param bool $denyAccess Whether to deny browser access to this new folder by
     *                         creating a .htaccess file.
     * @api
     */
    public static function mkdir($path, $denyAccess = true)
    {
        if (!is_dir($path)) {
            // the mode in mkdir is modified by the current umask
            @mkdir($path, $mode = 0755, $recursive = true);
        }

        // try to overcome restrictive umask (mis-)configuration
        if (!is_writable($path)) {
            @chmod($path, 0755);
            if (!is_writable($path)) {
                @chmod($path, 0775);
                // enough! we're not going to make the directory world-writeable
            }
        }

        if ($denyAccess) {
            self::createHtAccess($path, $overwrite = false);
        }
    }

    /**
     * Checks if the filesystem Piwik stores sessions in is NFS or not. This
     * check is done in order to avoid using file based sessions on NFS system,
     * since on such a filesystem file locking can make file based sessions
     * incredibly slow.
     *
     * Note: In order to figure this out, we try to run the 'df' program. If
     * the 'exec' or 'shell_exec' functions are not available, we can't do
     * the check.
     *
     * @return bool True if on an NFS filesystem, false if otherwise or if we
     *              can't use shell_exec or exec.
     */
    public static function checkIfFileSystemIsNFS()
    {
        $sessionsPath = Session::getSessionsDirectory();

        // this command will display details for the filesystem that holds the $sessionsPath
        // path, but only if its type is NFS. if not NFS, df will return one or less lines
        // and the return code 1. if NFS, it will return 0 and at least 2 lines of text.
        $command = "df -T -t nfs \"$sessionsPath\" 2>&1";

        if (function_exists('exec')) // use exec
        {
            $output = $returnCode = null;
            @exec($command, $output, $returnCode);

            // check if filesystem is NFS
            if ($returnCode == 0
                && count($output) > 1
            ) {
                return true;
            }
        } else if (function_exists('shell_exec')) // use shell_exec
        {
            $output = @shell_exec($command);
            if ($output) {
                $output = explode("\n", $output);
                if (count($output) > 1) // check if filesystem is NFS
                {
                    return true;
                }
            }
        }

        return false; // not NFS, or we can't run a program to find out
    }

    /**
     * Recursively find pathnames that match a pattern.
     * See [glob](#http://php.net/manual/en/function.glob.php) for more info.
     *
     * @param string $sDir directory The directory to glob in.
     * @param string $sPattern pattern The pattern to match paths against.
     * @param int $nFlags `glob()` flags. See [http://php.net/manual/en/function.glob.php](#http://php.net/manual/en/function.glob.php).
     * @return array The list of paths that match the pattern.
     * @api
     */
    public static function globr($sDir, $sPattern, $nFlags = null)
    {
        if (($aFiles = \_glob("$sDir/$sPattern", $nFlags)) == false) {
            $aFiles = array();
        }
        if (($aDirs = \_glob("$sDir/*", GLOB_ONLYDIR)) != false) {
            foreach ($aDirs as $sSubDir) {
                if (is_link($sSubDir)) {
                    continue;
                }

                $aSubFiles = self::globr($sSubDir, $sPattern, $nFlags);
                $aFiles = array_merge($aFiles, $aSubFiles);
            }
        }
        return $aFiles;
    }

    /**
     * Recursively deletes a directory.
     *
     * @param string $dir Path of the directory to delete.
     * @param boolean $deleteRootToo Whether to delete `$dir` or just its contents.
     * @param \Closure|false $beforeUnlink An optional closure to execute on a file path before unlinking.
     * @api
     */
    public static function unlinkRecursive($dir, $deleteRootToo, \Closure $beforeUnlink = null)
    {
        if (!$dh = @opendir($dir)) {
            return;
        }
        while (false !== ($obj = readdir($dh))) {
            if ($obj == '.' || $obj == '..') {
                continue;
            }

            $path = $dir . '/' . $obj;
            if ($beforeUnlink) {
                $beforeUnlink($path);
            }

            if (!@unlink($path)) {
                self::unlinkRecursive($path, true);
            }
        }
        closedir($dh);
        if ($deleteRootToo) {
            @rmdir($dir);
        }
        return;
    }

    /**
     * Copies a file from `$source` to `$dest`.
     *
     * @param string $source A path to a file, eg. './tmp/latest/index.php'. The file must exist.
     * @param string $dest A path to a file, eg. './index.php'. The file does not have to exist.
     * @param bool $excludePhp Whether to avoid copying files if the file is related to PHP
     *                         (includes .php, .tpl, .twig files).
     * @throws Exception If the file cannot be copied.
     * @return bool
     * @api
     */
    public static function copy($source, $dest, $excludePhp = false)
    {
        static $phpExtensions = array('php', 'tpl', 'twig');

        if ($excludePhp) {
            $path_parts = pathinfo($source);
            if (in_array($path_parts['extension'], $phpExtensions)) {
                return true;
            }
        }

        if (!@copy($source, $dest)) {
            @chmod($dest, 0755);
            if (!@copy($source, $dest)) {
                $message = "Error while creating/copying file to <code>$dest</code>. <br />"
                    . Filechecks::getErrorMessageMissingPermissions(self::getPathToPiwikRoot());
                throw new Exception($message);
            }
        }
        return true;
    }

    /**
     * Copies the contents of a directory recursively from `$source` to `$target`.
     * 
     * @param string $source A directory or file to copy, eg. './tmp/latest'.
     * @param string $target A directory to copy to, eg. '.'.
     * @param bool $excludePhp Whether to avoid copying files if the file is related to PHP
     *                         (includes .php, .tpl, .twig files).
     * @throws Exception If a file cannot be copied.
     * @api
     */
    public static function copyRecursive($source, $target, $excludePhp = false)
    {
        if (is_dir($source)) {
            self::mkdir($target, false);
            $d = dir($source);
            while (false !== ($entry = $d->read())) {
                if ($entry == '.' || $entry == '..') {
                    continue;
                }

                $sourcePath = $source . '/' . $entry;
                if (is_dir($sourcePath)) {
                    self::copyRecursive($sourcePath, $target . '/' . $entry, $excludePhp);
                    continue;
                }
                $destPath = $target . '/' . $entry;
                self::copy($sourcePath, $destPath, $excludePhp);
            }
            $d->close();
        } else {
            self::copy($source, $target, $excludePhp);
        }
    }
}