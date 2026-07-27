<?php

/**
 * Strip Absolute Path.
 *
 * @param string $el
 *
 * @return string
 */
function rel_path($el)
{
    $abs = getcwd().'/';

    return str_replace($abs, '', $el);
}

/**
 * Create an array that represents directory tree
 * Credit: http://php.net/manual/en/function.scandir.php#109140
 *
 * @param string $directory Directory path
 * @param bool   $recursive Include sub directories
 * @param bool   $listDirs  Include directories on listing
 * @param bool   $listFiles Include files on listing
 * @param string $exclude   Exclude paths that matches this regex
 *
 * @return array
 */
function dir_tree($directory, $recursive = true, $listDirs = false, $listFiles = true, $exclude = '')
{
    $arrayItems = [];
    $skipByExclude = false;
    $handle = opendir($directory);
    if ($handle) {
        while (false !== ($file = readdir($handle))) {
            preg_match("/(^(([\.]){1,2})$|(\.(svn|git|md))|(Thumbs\.db|\.DS_STORE))$/iu", $file, $skip);
            if ($exclude) {
                preg_match($exclude, $file, $skipByExclude);
            }
            if (!$skip && !$skipByExclude) {
                if (is_dir($directory.'/'.$file)) {
                    if ($recursive) {
                        $arrayItems = array_merge($arrayItems, dir_tree($directory.'/'.$file, $recursive, $listDirs, $listFiles, $exclude));
                    }
                    if ($listDirs) {
                        $file = $directory.'/'.$file;
                        $arrayItems[] = $file;
                    }
                } else {
                    if ($listFiles) {
                        $file = $directory.'/'.$file;
                        $arrayItems[] = $file;
                    }
                }
            }
        }
        closedir($handle);
    }

    return $arrayItems;
}

/**
 * Gets the password from user input, hiding password and 
 * replaces it with stars (*) if user users Unix / Mac.
 *
 * @return string
 */
function input_password()
{
    if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
        return trim(fgets(STDIN));
    }

    $original = shell_exec('stty -g');
    $pass = '';

    shell_exec('stty -icanon -echo min 1 time 0');
    
    while (true) {
        $char = fgetc(STDIN);
        // false means EOF, e.g. stdin redirected from /dev/null. Without this
        // the loop would spin forever instead of returning what it has.
        if ($char === false || $char === "\n") {
            break;
        } elseif (ord($char) === 127) {
            if (strlen($pass) > 0) {
                fwrite(STDOUT, "\x08 \x08");
                $pass = substr($pass, 0, -1);
            }
        } else {
            fwrite(STDOUT, '*');
            $pass .= $char;
        }
    }

    shell_exec('stty '.$original);

    return $pass;
}

/**
 * Turns a permission value from the ini file into a chmod mode.
 *
 * Everybody writes permissions the way chmod takes them, so "644" has to mean
 * the same as "0644". Reading it as a decimal number instead would chmod the
 * file to 01204 (-w----r--), which is exactly how uploaded files end up
 * unreadable for the web server.
 *
 * @param mixed $value the configured value, as string or int
 *
 * @return int|null the mode, or null if the value can't be understood
 */
function parse_permission($value)
{
    // A value coming from PHP code (0644) already is a mode.
    if (is_int($value)) {
        return $value;
    }

    if (!is_string($value)) {
        return null;
    }

    if (!preg_match('#^0?o?([0-7]{3,4})$#i', trim($value), $matches)) {
        return null;
    }

    return octdec($matches[1]);
}

/**
 * Return a human readable filesize.
 *
 * @param int $bytes
 * @param int $decimals
 *
 * @return string
 */
function human_filesize($bytes, $decimals = 2)
{
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);

    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)).@$sz[$factor];
}

/**
 * Glob the file path.
 *
 * @param string $pattern
 * @param string $string
 *
 * @return string
 */
function pattern_match($pattern, $string)
{
    return preg_match('#^'.strtr(preg_quote($pattern, '#'), ['\*' => '.*', '\?' => '.']).'$#i', $string);
}
