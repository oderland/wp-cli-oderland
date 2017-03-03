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

    /**
     * Function that returns prefix,max_username_length,max_database_name_length
     * as an assoc array
     */
    private function getRestrictions()
    {
        return $this->runApi(
            'uapi', 'Mysql', 'get_restrictions', array(), true);
    }

    private function runApi($api, $module, $func, $kwargs, $check)
    {
        $cmdline = "/usr/bin/$api --output=json $module $func";
        foreach ($kwargs as $key => $value)
            $cmdline .= ' ' . escapeshellarg("$key=$value");

        WP_CLI::debug($cmdline);

        $output = shell_exec($cmdline);

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
}

WP_CLI::add_command('oderland', 'WP_CLI_Oderland');
