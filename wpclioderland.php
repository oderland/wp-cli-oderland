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

        $dbname = $this->enforceUsernamePrefix($args[0]);
        $dbname = $this->enforceNameLength($dbname, 'database');
        $dbname = escapeshellcmd($dbname);

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

        $username = $this->enforceUsernamePrefix($args[0]);
        $username = $this->enforceNameLength($username, 'username');
        $username = escapeshellcmd($username);

        $password = escapeshellcmd($args[1]);

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
        $username = escapeshellcmd($this->enforceUsernamePrefix($args[0]));
        $database = escapeshellcmd($this->enforceUsernamePrefix($args[1]));
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
