<?php

/**
 * Implements ODERLAND Hosting Services CLI Commands
 * @author David Majchrzak <david@oderland.se>
 */
class WP_CLI_Oderland extends WP_CLI_Command
{
    /**
     * Function that returns prefix,max_username_length,max_database_name_length
     * as an assoc array
     */
    private function getRestrictions()
    {
        $command = "/usr/bin/uapi Mysql get_restrictions --output=json";
        $output = shell_exec($command);

        try {
            $data = $this->parseShellOutput($output);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

        // return array containing:
        // prefix,max_username_length,max_database_name_length
        return $data['result']['data'];
    }

    private function parseCpapi2Output($output)
    {
        if (!$data = json_decode($output, true))
            throw new Exception("Failed to get decode json output: "
                . json_last_error());

        if ($data['cpanelresult']['data'][0]['result'] !== 1) {
            $errors = $data['cpanelresult']['error'];
            throw new Exception ($errors);
        }

        return $data;
    }

    private function parseShellOutput($output)
    {
        if (!$data = json_decode($output, true))
            throw new Exception("Failed to get decode json output: "
                . $json_last_error());

        if ($data['result']['status'] !== 1) {
            $errors = implode("\r\n", $data['result']['errors']);
            throw new Exception ($errors);
        }

        return $data;
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
     *     wp oderland addAddonDomain domain1.com "domains/domain1.com" domain1
     *
     * @when before_wp_load
     */
    public function addAddonDomain($args, $assoc_args)
    {
        $domain = urlencode(escapeshellcmd($args[0]));
        $directory = urlencode(escapeshellcmd($args[1]));
        $subdomain = urlencode(escapeshellcmd($assoc_args['subdomain']));
        if (!$subdomain) {
            $subdomain = $domain;
        }
        $command = "/usr/bin/cpapi2 AddonDomain addaddondomain dir=$directory newdomain=$domain subdomain=$subdomain --output=json 2> /dev/null";
        $output = shell_exec($command);

        try {
            $data = $this->parseCpapi2Output($output);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success('Addon domain was added: ' . $domain . ' with document root: ' .urldecode($directory));
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
     *     wp oderland createdatabase wp101
     *
     * @when before_wp_load
     */
    public function createDatabase($args, $assoc_args)
    {
        $restrictions = $this->getRestrictions();

        $dbname = $args[0];
        $dbname = escapeshellcmd($dbname);

        // check if prefix exists and dbname length is valid.
        $pos = strpos($dbname, $restrictions['prefix']);

        if ($pos === false || $pos !== 0) {
            $dbname = $restrictions['prefix'] . $dbname;
            WP_CLI::warning('Database name has to be prefixed with: '
                . $restrictions['prefix']);
        }

        // if dbname is too long, shorted it to max.
        if (strlen($dbname) > $restrictions['max_database_name_length']) {
            $dbname = substr($dbname, 0, $restrictions['max_database_name_length']);
            WP_CLI::warning('Database name max length is '
                . $restrictions['max_database_name_length']);
        }
        $command = "/usr/bin/uapi Mysql create_database name=$dbname --output=json";
        $output = shell_exec($command);

        try {
            $data = $this->parseShellOutput($output);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

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
     * <password>
     * : The database user password
     *
     * ## EXAMPLES
     *
     *     wp oderland createDatabaseUser wpadm1 k.Mfk0-2s7yewop5
     *
     * @when before_wp_load
     */
    public function createDatabaseUser($args, $assoc_args)
    {
        $restrictions = $this->getRestrictions();

        $username = escapeshellcmd($args[0]);
        $password = escapeshellcmd($args[1]);

        // check if prefix exists and username length is valid.
        $pos = strpos($username, $restrictions['prefix']);

        if ($pos === false || $pos !== 0) {
            $username = $restrictions['prefix'] . $username;
            WP_CLI::warning('Database username has to be prefixed with: '
                . $restrictions['prefix']);
        }

        // if username is too long, shorted it to max.
        if (strlen($username) > $restrictions['max_username_length']) {
            $username = substr($username, 0, $restrictions['max_username_length']);
            WP_CLI::warning('Database username max length is '
                . $restrictions['max_username_length']);
        }
        $command = "/usr/bin/uapi Mysql create_user name=$username password=$password --output=json";
        $output = shell_exec($command);

        try {
            $data = $this->parseShellOutput($output);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

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
     *     wp oderland setDatabasePrivileges wpadm1 wp101
     * @when before_wp_load
     */
    public function setDatabasePrivileges($args, $assoc_args)
    {
        $username = escapeshellcmd($args[0]);
        $database = escapeshellcmd($args[1]);
        $command = "/usr/bin/uapi Mysql set_privileges_on_database user=$username database=$database privileges='ALL PRIVILEGES' --output=json";
        $output = shell_exec($command);

        try {
            $data = $this->parseShellOutput($output);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success('Set all privileges for user: ' . $username .' on database ' . $database);
    }
}

WP_CLI::add_command('oderland', 'WP_CLI_Oderland');
