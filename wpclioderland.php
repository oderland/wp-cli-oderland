<?php

/**
 * Implements ODERLAND Hosting Services CLI Commands
 * @author David Majchrzak <david@oderland.se>
 */
class WP_CLI_Oderland extends WP_CLI_Command
{
    private function enforceNameLength($text, $type)
    {
        $restrictions = $this->getRestrictions();

        if ($type === 'database')
            $max = $restrictions['max_database_name_length'];
        elseif ($type === 'username')
            $max = $restrictions['max_username_length'];

        // _ are stored as two characters, and the prefix always contains one.
        $max -= 1;

        if (strlen($text) <= $max)
            return $text;

        $new = substr($text, 0, $max);
        WP_CLI::warning("'$text' is too long, truncating into '$new'.");
        return $new;
    }

    private function enforceUsernamePrefix($text)
    {
        $restrictions = $this->getRestrictions();

        // Check if prefix exists in string and at what position.
        if (strpos($text, $restrictions['prefix']) === 0)
            return $text;

        $new = $restrictions['prefix'] . $text;
        WP_CLI::warning("Prepending username prefix to '$text' => '$new'.");
        return $new;
    }

    private function generatePassword($length)
    {
        if (($fh = fopen('/dev/urandom', 'r')) === false)
            die("Unable to generate password: Failed opening /dev/urandom\n");

        if (($data = fread($fh, $length)) === false)
            die("Unable to generate password: Failed reading /dev/urandom\n");

        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $pw = '';
        foreach (unpack('C*', $data) as $num)
            $pw .= $chars[$num % strlen($chars)];

        return $pw;
    }

    // Returns a list of the names of the user's current databases.
    private function getDatabaseNames()
    {
        $data = $this->runApi('cpapi2', 'MysqlFE', 'listdbs', array(), false);

        $databases = array();
        foreach ($data as $db)
            $databases[] = $db['db'];

        return $databases;
    }

    // Returns a list of the names of the user's current database users.
    private function getDatabaseUserNames()
    {
        $data = $this->runApi('cpapi2', 'MysqlFE', 'listusers', array(), false);

        $users = array();
        foreach ($data as $user)
            $users[] = $user;

        return $users;
    }

    /**
     * Returns an array of the user's domains, where each key is the domain
     * name and the value an array containing docroot+domain type.
     */
    private function getDomainData()
    {
        $data = $this->runApi(
            'uapi', 'DomainInfo', 'domains_data', array(), false);

        $domains = array(
            $data['main_domain']['domain'] => array(
                'documentroot' => $data['main_domain']['documentroot'],
                'type' => 'main'
            ),
        );

        if (isset($data['parked_domains']))
            foreach ($data['parked_domains'] as $domain)
                $domains[$domain] = array(
                    'documentroot' => $data['main_domain']['documentroot'],
                    'type' => 'parked'
                );

        if (isset($data['addon_domains']))
            foreach ($data['addon_domains'] as $domain)
                $domains[$domain['domain']] = array(
                    'documentroot' => $domain['documentroot'],
                    'type' => 'addon'
                );

        if (isset($data['sub_domains']))
            foreach ($data['sub_domains'] as $domain)
                $domains[$domain['domain']] = array(
                    'documentroot' => $domain['documentroot'],
                    'type' => 'sub'
                );

        return $domains;
    }

    /**
     * Function that returns prefix,max_username_length,max_database_name_length
     * as an assoc array
     */
    private function getRestrictions()
    {
        return $this->runApi(
            'uapi', 'Mysql', 'get_restrictions', array(), true);
    }

    private function getUriPathRelativeHome($docroot, $uripath)
    {
        // The homedir is always at three levels depth in these systems =>
        // /*/*/ is stripped, leaving us with e.g. "public_html/domain", i.e.
        // the path to the docroot relative to the homedir.
        $docroot_rh = implode('/', array_slice(explode('/', $docroot), 3));
        // The path to the dir to be migrated relative to the homedir.
        $uripath_rh = "$docroot_rh/$uripath";

        return $uripath_rh;
    }

    private function odercacheConfigAdd($domain, $uripath)
    {
        if (!is_array($this->odercache['cfg']))
            $this->odercacheConfigLoad();

        if (!isset($this->odercache['cfg'][$domain]))
            $this->odercache['cfg'][$domain] = array();

        WP_CLI::debug("Adding $domain:$uripath to odercache config.");

        $this->odercache['cfg'][$domain][$uripath] = array();

        $this->odercacheConfigSave();
    }

    private function odercacheConfigDel($domain, $uripath)
    {
        if (!is_array($this->odercache['cfg']))
            $this->odercacheConfigLoad();

        WP_CLI::debug("Deleting $domain:$uripath from odercache config.");

        unset($this->odercache['cfg'][$domain][$uripath]);

        if(empty($this->odercache['cfg'][$domain]))
            unset($this->odercache['cfg'][$domain]);

        $this->odercacheConfigSave();
    }

    private function odercacheConfigLoad()
    {
        WP_CLI::debug("Loading odercache configuration: " .
            $this->odercache['cfg_file']);

        $data = '{}';
        if (is_file($this->odercache['cfg_file']))
            $data = file_get_contents($this->odercache['cfg_file']);

        $cfg = json_decode($data, $assoc=true);
        if (json_last_error())
            WP_CLI::error("Failed loading odercache config " .
                "({$this->odercache['cfg_file']}): " . json_last_error_msg());

        $this->odercache['cfg'] = $cfg;
    }

    private function odercacheConfigSave()
    {
        WP_CLI::debug("Saving odercache configuration: " .
            $this->odercache['cfg_file']);

        file_put_contents(
            $this->odercache['cfg_file'],
            json_encode($this->odercache['cfg'], JSON_PRETTY_PRINT) . "\n"
        );

    }

    private function odercacheManage($mode, $args, $assoc_args)
    {
        $home = $_SERVER['HOME'];
        $this->odercache = array(
            'dir' => "$home/odercache",
            'cfg_dir' => "$home/.oderland/odercache/dirs",
            'cfg_file' => "$home/.oderland/odercache/conf.json"
        );

        if (!is_dir($this->odercache['dir']))
            WP_CLI::error(
                "odercache directory not found: " . $this->odercache['dir']);

        $domains = $this->getDomainData();

        if ($mode === 'list') {
            $this->odercacheManageList($domains);
            return;
        }

        $uripath = $args[1];
        // Remove any slash from start and beginning of string.
        $uripath = preg_replace("#(^/+|/+$)#", "", $uripath);
        // Remove any redundant slashes.
        $uripath = preg_replace("#/+#", "/", $uripath);
        // Make sure there's no . or .. components in the dir path.
        if (preg_match("#(^|/)\.\.?(/|$)#", $uripath))
            WP_CLI::error("Given directory contains dot path components.");

        $domain = $args[0];

        if (!isset($domains[$domain]))
            WP_CLI::error("Given domain '$domain' not found on the account.");

        // The full/absolute filesystem path to the docroot.
        $docroot = $domains[$domain]['documentroot'];
        WP_CLI::debug("Domain document root: $docroot");

        // The full/absolute path to the cachedir.
        $uripath_f = "$docroot/$uripath";

        if (is_file($uripath_f))
            WP_CLI::error("Given directory '$uripath_f' is actually a file.");

        $uripath_rh = $this->getUriPathRelativeHome($docroot, $uripath);

        if ($mode === 'enable') {
            $this->odercacheManageEnable($uripath_f, $uripath_rh);
            $this->odercacheConfigAdd($domain, $uripath);
        }
        WP_CLI::success("Successfully {$mode}d odercache for '$uripath'" .
            " on domain '$domain'.");
    }

    private function odercacheManageEnable($uripath_f, $uripath_rh)
    {
        if (is_link($uripath_f))
            WP_CLI::error("Given directory '$uripath_f' is already a symlink.");

        if (is_dir($uripath_f)) {
            WP_CLI::debug("$uripath_f exists and is a directory; migrate it.");
            $this->odercacheManageEnableMigrate($uripath_f, $uripath_rh);
        } else {
            WP_CLI::debug("'$uripath_f' does not exist, create required dirs.");
            $this->odercacheManageEnableNoMigrate($uripath_f, $uripath_rh);
        }

        WP_CLI::debug("Creating symlink '$uripath_f' -> " .
            "{$this->odercache['dir']}/$uripath_rh'.");

        if (!symlink("{$this->odercache['dir']}/$uripath_rh", "$uripath_f"))
            WP_CLI::error("Failed creating symlink '$uripath_f' -> " .
                "'{$this->odercache['dir']}/$uripath_rh'.");
    }

    private function odercacheManageEnableMigrate($uripath_f, $uripath_rh)
    {
        $homedir_esc = escapeshellarg($_SERVER['HOME']);
        $uripath_f_esc = escapeshellarg($uripath_f);
        $uripath_rh_esc = escapeshellarg($uripath_rh);
        $odercache_cfg_dir_esc = escapeshellarg($this->odercache['cfg_dir']);
        $odercache_dir_esc = escapeshellarg($this->odercache['dir']);

        $odercache_avail = end($this->runCmd(
            "df -B1 --output=avail {$this->odercache['dir']} 2>/dev/null",
            "Failed executing df for '{$this->odercache['dir']}'"
        )[1]);
        if (!ctype_digit($odercache_avail))
            WP_CLI::error("Unable to get available disk space for " .
                $this->odercache['dir'] . '.');

        $uripath_size = explode("\t", end($this->runCmd(
            "du -sb $uripath_f_esc 2>/dev/null",
            "Failed executing du for '$uripath_f'"
        )[1]))[0];

        if (($uripath_size + 1024*1024) > $odercache_avail)
            WP_CLI::error("Insufficient space in odercache for '$uripath_f'");

        $cmdline = "( cd $homedir_esc && ";
        $cmdline .= "find $uripath_rh_esc -xdev -type d ";
        $cmdline .= "-exec mkdir -pv -- $odercache_cfg_dir_esc/{} \; ";
        $cmdline .= "-exec mkdir -pv -- $odercache_dir_esc/{} \; ";
        $cmdline .= ");";
        $this->runCmd($cmdline,
            "Failed while creating odercache dir tree.");

        $cmdline = "( cd $homedir_esc && find $uripath_rh_esc -xdev -type f ";
        $cmdline .= "-exec cp -pv -- {} $odercache_dir_esc/{} \; ";
        $cmdline .= ");";
        $this->runCmd($cmdline,
            "Failed while migrating data to odercache.");

        // One path component removed from the end of $uripath_f
        $uripath_parent = implode(
            '/', array_slice(explode('/', $uripath_f), 0, -1));

        $uripath_base = end(explode('/', $uripath_f));
        $uripath_f_bak = "$uripath_parent/.bak_" . time() . "_$uripath_base";
        $cmdline = "mv -v -- $uripath_f_esc " . escapeshellarg($uripath_f_bak);
        $this->runCmd($cmdline,
            "Failed creating backup '$uripath_f' -> '$dir_bak_dst'.");
    }

    private function odercacheManageEnableNoMigrate($uripath_f, $uripath_rh)
    {
        // One path component removed from the end of $uripath_f
        $dirs = array(
            implode('/', array_slice(explode('/', $uripath_f), 0, -1)),
            $this->odercache['dir'] . "/$uripath_rh",
            $this->odercache['cfg_dir'] . "/$uripath_rh"
        );
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                WP_CLI::debug("Creating missing directory '$dir'.");
                mkdir($dir, $mode=0711, $recursive=true);
            }
            if (!is_dir($dir))
                WP_CLI::error(
                    "Unable to create parent directory '$dir'.");
        }
    }

    private function odercacheManageList($domains)
    {
        $this->odercacheConfigLoad();

        $odercaches = array();
        $max_len_domain = 0;
        foreach ($this->odercache['cfg'] as $domain => $uripaths) {
            WP_CLI::debug("Found domain in config: $domain");
            if (!isset($domains[$domain])) {
                WP_CLI::debug("Ignoring '$domain' (not added to the account).");
                continue;
            }

            $docroot = $domains[$domain]['documentroot'];

            foreach ($uripaths as $uripath => $uripath_dummy) {
                WP_CLI::debug("Found uripath in config: $uripath");
                $uripath_rh = $this->getUriPathRelativeHome($docroot, $uripath);
                $uripath_oc = $this->odercache['dir'] . "/$uripath_rh";

                if (!is_dir($uripath_oc)) {
                    WP_CLI::debug("Ignoring '$uripath' not found in odercache "
                        . "($uripath_oc)");
                    continue;
                }

                $uripath_oc_esc = escapeshellarg($uripath_oc);
                $uripath_oc_size = explode("\t", end($this->runCmd(
                    "du -sm -- $uripath_oc_esc 2>/dev/null",
                    "Failed executing du for '$uripath_oc'"
                )[1]))[0];

                $odercaches[] = array($domain, $uripath, $uripath_oc_size);
            }

            if (strlen($domain) > $max_len_domain)
                $max_len_domain = strlen($domain);
        }

        printf("megabytes | %{$max_len_domain}s | directory\n", 'domain');
        echo str_repeat('-', 10) . '|' . str_repeat('-', 1+$max_len_domain+1)
            . '|' . str_repeat('-', 1+9) . "\n";
        foreach ($odercaches as $odercache_data)
            printf(
                "%9s | %{$max_len_domain}s | %s\n",
                $odercache_data[2], $odercache_data[0], $odercache_data[1]
            );
    }

    private function runApi($api, $module, $func, $kwargs, $check)
    {
        $cmdline = "/usr/bin/$api --output=json $module $func";
        foreach ($kwargs as $key => $value)
            $cmdline .= ' ' . escapeshellarg("$key=$value");

        $output = implode("\n", $this->runCmd(
            $cmdline, "Failed running $module/$func $api API call")[1]);

        $data = json_decode($output, true);
        if (json_last_error())
            throw new Exception(
                "Failed to get decode json output: " . json_last_error_msg());

        if ($api === 'uapi') {
            if (!isset($data['result']))
                WP_CLI::error("Output did not contain cpanelresult->data");

            if ($check)
                if ($data['result']['status'] !== 1)
                    WP_CLI::error(implode("\r\n", $data['result']['errors']));

            return $data['result']['data'];

        } elseif ($api === 'cpapi2') {
            if (!isset($data['cpanelresult']['data']))
                WP_CLI::error("Output did not contain cpanelresult->data");

            if ($check)
                if ($data['cpanelresult']['data'][0]['result'] !== 1)
                    WP_CLI::error($data['cpanelresult']['error']);

            return $data['cpanelresult']['data'];

        } else {
            return $data;
        }
    }

    // Executes given commandline, and if return code is >0, die with given
    // error message. On success, return an array where [0] = the return code
    // and [1] = an array of the stdout lines.
    private function runCmd($cmdline, $errmsg)
    {
        WP_CLI::debug("Executing command: $cmdline");
        $ret = null;
        $out = null;
        exec($cmdline, $out, $ret);
        WP_CLI::debug("Executed command return code: $ret");
        WP_CLI::debug("Executed command output:\n" . implode("\n", $out) ."\n");
        if ($ret !== 0)
            WP_CLI::error($errmsg);
        return array($ret, $out);
    }

    /**
     * Add Addon Domain to the account
     *
     * ## OPTIONS
     *
     * <domain>
     * : The addon domain
     *
     * <directory>
     * : Path to public directory
     *
     * [--subdomain=<subdomain>]
     * : The addon domain needs to create a subdomain on the main domain
     *
     * ## EXAMPLES
     *
     *     wp oderland domain-add domain1.com domains/domain1.com
     *
     * @when before_wp_load
     * @subcommand domain-add
     */
    public function addonDomainCreate($args, $assoc_args)
    {
        $domain = $args[0];
        $directory = $args[1];
        $sub = ($assoc_args['subdomain'] ? $assoc_args['subdomain'] : $domain);

        $opts = array(
            'dir' => urlencode($directory),
            'newdomain' => urlencode($domain),
            'subdomain' => urlencode($sub)
        );

        $this->runApi('cpapi2', 'AddonDomain', 'addaddondomain', $opts, true);

        WP_CLI::success(
            "Addon domain was added: $domain with document root: $directory");
    }

    /**
     * Create a mysql database
     *
     * ## OPTIONS
     *
     * <dbname>
     * : The database name
     *
     * ## EXAMPLES
     *
     *     wp oderland db-create wp101
     *
     * @when before_wp_load
     * @subcommand db-create
     */
    public function dbCreate($args, $assoc_args)
    {
        $restrictions = $this->getRestrictions();

        $dbname = $this->enforceUsernamePrefix($args[0]);
        $dbname = $this->enforceNameLength($dbname, 'database');

        $opts = array('name' => $dbname);

        $this->runApi('uapi', 'Mysql', 'create_database', $opts, true);

        WP_CLI::success('Created database: ' . $dbname);
    }

    /**
     * Create a mysql database user
     *
     * ## OPTIONS
     *
     * <username>
     * : The database username
     *
     * [<password>]
     * : The database user password. Automatically generated if not given.
     *
     * ## EXAMPLES
     *
     *     wp oderland db-user-create wpadm1 k.Mfk0-2s7yewop5
     *
     * @when before_wp_load
     * @subcommand db-user-create
     */
    public function dbUserCreate($args, $assoc_args)
    {
        $restrictions = $this->getRestrictions();

        $username = $this->enforceUsernamePrefix($args[0]);
        $username = $this->enforceNameLength($username, 'username');

        if (empty($args[1]))
            $password = $this->generatePassword(20);
        else
            $password = $args[1];

        $opts = array(
            'name' => $username,
            'password' => $password
        );

        $this->runApi('uapi', 'Mysql', 'create_user', $opts, true);

        WP_CLI::success('Created database user: ' . $username);
    }

    /**
     * Set All Privileges on MySQL User and Database
     *
     * ## OPTIONS
     *
     * <username>
     * : The database username
     *
     * <database>
     * : The database
     *
     * ## EXAMPLES
     *
     *     wp oderland db-privileges-create wpadm1 wp101
     * @when before_wp_load
     * @subcommand db-privileges-create
     */
    public function dbPrivilegesCreate($args, $assoc_args)
    {
        $username = $this->enforceUsernamePrefix($args[0]);
        $database = $this->enforceUsernamePrefix($args[1]);

        $opts = array(
            'user' => $username,
            'database' => $database,
            'privileges' => 'ALL PRIVILEGES'
        );

        $this->runApi(
            'uapi', 'Mysql', 'set_privileges_on_database', $opts, true);

        WP_CLI::success(
            "Set all privileges for user: $username on database $database");
    }

    /**
     * Create a database, a database user, and set database privileges for the
     * new user. Names and passwords are automatically generated.
     *
     * ## EXAMPLES
     *
     *     wp oderland db-quick-setup
     * @when before_wp_load
     * @subcommand db-quick-setup
     */
    public function dbQuickSetup($args, $assoc_args)
    {
        do {
            $db_name = $this->enforceUsernamePrefix(
                'd' . $this->generatePassword(4));
            $db_name = $this->enforceNameLength($db_name, 'database');
        } while (in_array($db_name, $this->getDatabaseNames()));

        do {
            $db_user = $this->enforceUsernamePrefix(
                'u' . $this->generatePassword(4));
            $db_user = $this->enforceNameLength($db_user, 'username');
        } while (in_array($db_user, $this->getDatabaseUserNames()));

        $this->dbCreate(array($db_name), array());
        $this->dbUserCreate(array($db_user), array());
        $this->dbPrivilegesCreate(array($db_user, $db_name), array());
    }

    /**
     * List domains linked to the account.
     *
     * ## EXAMPLES
     *
     *     wp oderland domain-list
     *
     * @when before_wp_load
     * @subcommand domain-list
     */
    public function domainList($args, $assoc_args)
    {
        $domains = $this->getDomainData();

        $max_len_domain = 0;
        foreach ($domains as $domain_name => $domain_data)
            if (strlen($domain_name) > $max_len_domain)
                $max_len_domain = strlen($domain_name);

        printf(
            "%7s | %{$max_len_domain}s | %s\n",
            'type', 'name', 'document_root'
        );
        echo str_repeat('-', 8) . '|' . str_repeat('-', 1+$max_len_domain+1)
            . '|' . str_repeat('-', 1+13) . "\n";
        foreach ($domains as $domain_name => $domain_data)
            printf(
                "%7s | %{$max_len_domain}s | %s\n",
                $domain_data['type'],
                $domain_name,
                $domain_data['documentroot']
            );
    }

    /**
     * Enables odercache for the given domain's directory. The data found in the
     * directory will be migrated to odercache, and the original directory will
     * be converted into a symlink into odercache.
     *
     * DO NOTE that the migrated data will become volatile and might be erased
     * in the future. There are no guarantees in regards to persistency. You
     * must only migrate data that will be re-created in case it would perish.
     *
     * ## OPTIONS
     *
     * <domain>
     * : The site's domain.
     *
     * <directory>
     * : The directory (relative to the domain's root) to migrate to odercache.
     *
     * ## EXAMPLES
     *
     *     wp oderland odercache-enable
     * @when before_wp_load
     * @subcommand odercache-enable
     */
    public function odercacheEnable($args, $assoc_args)
    {
        $this->odercacheManage('enable', $args, $assoc_args);
    }

    /**
     * Lists enabled odercaches.
     *
     * ## EXAMPLES
     *
     *     wp oderland odercache-list
     * @when before_wp_load
     * @subcommand odercache-list
     */
    public function odercacheList($args, $assoc_args)
    {
        $this->odercacheManage('list', $args, $assoc_args);
    }

}

WP_CLI::add_command('oderland', 'WP_CLI_Oderland');
