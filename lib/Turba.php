<?php
/**
 * Copyright 2000-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (ASL).  If you did
 * did not receive this file, see http://www.horde.org/licenses/apache.
 *
 * @category  Horde
 * @copyright 2000-2017 Horde LLC
 * @license   http://www.horde.org/licenses/apache ASL
 * @package   Turba
 */

/**
 * Turba Base Class.
 *
 * @author    Chuck Hagenbuch <chuck@horde.org>
 * @author    Jon Parise <jon@horde.org>
 * @category  Horde
 * @copyright 2000-2017 Horde LLC
 * @license   http://www.horde.org/licenses/apache ASL
 * @package   Turba
 */
class Turba
{
    /**
     * The virtual path to use for VFS data.
     */
    const VFS_PATH = '.horde/turba/documents';

    /**
     * The current source.
     *
     * @var string
     */
    public static $source;

    /**
     * Cached data.
     *
     * @var array
     */
    protected static $_cache = array();

    /**
     * Returns the source entries from config/backends.php that have been
     * configured as available sources in the main Turba configuration.
     *
     * @return array  List of available sources.
     * @throws Horde_Exception
     */
    public static function availableSources()
    {
        global $registry;

        $s = $registry->loadConfigFile('backends.php', 'cfgSources', 'turba')->config['cfgSources'];

        $sources = array();
        foreach ($s as $key => $source) {
            if (empty($source['disabled'])) {
                $sources[$key] = $source;
            }
        }

        return $sources;
    }

    /**
     * Get all the address books the user has the requested permissions to and
     * return them in the user's preferred order.
     *
     * @param integer $permission  The Horde_Perms::* constant to filter on.
     * @param array $options       Any additional options.
     *
     * @return array  The filtered, ordered $cfgSources entries.
     */
    public static function getAddressBooks($permission = Horde_Perms::READ,
                                           array $options = array())
    {
        return self::permissionsFilter(
            $GLOBALS['cfgSources'],
            $permission,
            $options
        );
    }

    /**
     * Returns the current user's default address book.
     *
     * @return string  The default address book name.
     */
    public static function getDefaultAddressbook()
    {
        // Actually try reading from prefs first
        global $prefs;
        $originalScope = $prefs->getScope();
        $prefs->changeScope('turba');
        $prefs->retrieve('turba');
        $defaultAddressbook = $prefs->getValue('default_dir');
        $prefs->changeScope($originalScope);
        if (!empty($defaultAddressbook)) {
            return $defaultAddressbook;
        }
        /* In case of shares select first user owned address book as default */
        if (!empty($_SESSION['turba']['has_share'])) {
            try {
                $owned_shares = self::listShares(true);
                if (count($owned_shares)) {
                    return key($owned_shares);
                }
            } catch (Exception $e) {}
        }

        reset($GLOBALS['cfgSources']);
        return key($GLOBALS['cfgSources']);
    }

    /**
     * Returns the sort order selected by the user.
     *
     * @return array  TODO
     */
    public static function getPreferredSortOrder()
    {
        return @unserialize($GLOBALS['prefs']->getValue('sortorder'));
    }

    /**
     * Saves the sort order to the preferences backend.
     *
     * @param Horde_Variables $vars  Variables object.
     * @param string $source         Source.
     */
    public static function setPreferredSortOrder(Horde_Variables $vars,
                                                 $source)
    {
        if (!strlen($sortby = $vars->get('sortby'))) {
            return;
        }

        $sources = self::getColumns();
        $columns = isset($sources[$source])
            ? $sources[$source]
            : array();
        $column_name = self::getColumnName($sortby, $columns);

        $append = true;
        $ascending = ($vars->get('sortdir') == 0);

        if ($vars->get('sortadd')) {
            $sortorder = self::getPreferredSortOrder();
            foreach ($sortorder as $i => $elt) {
                if ($elt['field'] == $column_name) {
                    $sortorder[$i]['ascending'] = $ascending;
                    $append = false;
                }
            }
        } else {
            $sortorder = array();
        }

        if ($append) {
            $sortorder[] = array(
                'ascending' => $ascending,
                'field' => $column_name
            );
        }

        $GLOBALS['prefs']->setValue('sortorder', serialize($sortorder));
    }

    /**
     * Retrieves a column's field name.
     *
     * @param integer $i      TODO
     * @param array $columns  TODO
     *
     * @return string  TODO
     */
    public static function getColumnName($i, $columns)
    {
        return (($i == 0) || !isset($columns[$i - 1]))
            ? 'name'
            : $columns[$i - 1];
    }

    /**
     * TODO
     */
    public static function getColumns()
    {
        $columns = array();
        $lines = explode("\n", $GLOBALS['prefs']->getValue('columns'));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line) {
                $cols = explode("\t", $line);
                if (count($cols) > 1) {
                    $source = array_splice($cols, 0, 1);
                    $columns[$source[0]] = array();
                    foreach ($cols as $col) {
                        if ($col == '__tags' ||
                            isset($GLOBALS['cfgSources'][$source[0]]['map'][$col])) {
                            $columns[$source[0]][] = $col;
                        }
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Builds and cleans up a composite field.
     *
     * @param string $format  The sprintf field format.
     * @param array $fields   The fields that compose the composite field.
     *
     * @return string  The formatted composite field.
     */
    public static function formatCompositeField($format, $fields)
    {
        return preg_replace('/ +/', ' ', trim(vsprintf($format, $fields), " \t\n\r\0\x0B,"));
    }

    /**
     * Returns a best guess at the lastname in a string.
     *
     * @param string $name  String contain the full name.
     *
     * @return string  String containing the last name.
     */
    public static function guessLastname($name)
    {
        $name = trim(preg_replace('|\s|', ' ', $name));
        if (!empty($name)) {
            /* Assume that last names are always before any commas. */
            if (is_int(strpos($name, ','))) {
                $name = Horde_String::substr($name, 0, strpos($name, ','));
            }

            /* Take out anything in parentheses. */
            $name = trim(preg_replace('|\(.*\)|', '', $name));

            $namelist = explode(' ', $name);
            $name = $namelist[($nameindex = (count($namelist) - 1))];

            while (!empty($name) &&
                   (($nlength = Horde_String::length($name)) < 5) &&
                   strspn($name[($nlength - 1)], '.:-') &&
                   !empty($namelist[($nameindex - 1)])) {
                $name = $namelist[--$nameindex];
            }
        }

        return strlen($name)
            ? $name
            : null;
    }

    /**
     * Formats the name according to the user's preference.
     *
     * If the format is 'none', the full name with all parts is returned. If
     * the format is 'last_first' or 'first_last', only the first name and
     * last name are returned.
     *
     * @param Turba_Object $ob     The object to get a name from.
     * @param string $name_format  The formatting. One of 'none', 'last_first'
     *                             or 'first_last'. Defaults to the user
     *                             preference.
     *
     * @return string  The formatted name, either "Firstname Lastname"
     *                 or "Lastname, Firstname" depending on $name_format or
     *                 the user's preference.
     */
    public static function formatName(Turba_Object $ob, $name_format = null)
    {
        if (!$name_format) {
            if (!isset(self::$_cache['defaultFormat'])) {
                self::$_cache['defaultFormat'] = $GLOBALS['prefs']->getValue('name_format');
            }
            $name_format = self::$_cache['defaultFormat'];
        }

        /* If no formatting, return original name. */
        if (!in_array($name_format, array('first_last', 'last_first'))) {
            return $ob->getValue('name');
        }

        /* See if we have the name fields split out explicitly. */
        if ($ob->hasValue('firstname') && $ob->hasValue('lastname')) {
            return ($name_format == 'last_first')
                ? $ob->getValue('lastname') . ', ' . $ob->getValue('firstname')
                : $ob->getValue('firstname') . ' ' . $ob->getValue('lastname');
        }

        /* One field, we'll have to guess. */
        $name = $ob->getValue('name');
        $lastname = self::guessLastname($name);
        if (($name_format == 'last_first') &&
            !is_int(strpos($name, ',')) &&
            (Horde_String::length($name) > Horde_String::length($lastname))) {
            return $lastname . ', ' . preg_replace('/\s+' . preg_quote($lastname, '/') . '/', '', $name);
        }

        if (($name_format == 'first_last') &&
            is_int(strpos($name, ',')) &&
            (Horde_String::length($name) > Horde_String::length($lastname))) {
            return preg_replace('/' . preg_quote($lastname, '/') . ',\s*/', '', $name) . ' ' . $lastname;
        }

        return $name;
    }

    /**
     * TODO
     *
     * @param mixed $data   Either a single email address or an array of email
     *                      addresses to format.
     * @param string $name  The personal name phrase.
     *
     * @return mixed  Either the formatted address or an array of formatted
     *                addresses.
     */
    public static function formatEmailAddresses($data, $name)
    {
        if (!isset(self::$_cache['useRegistry'])) {
            self::$_cache['useRegistry'] = $GLOBALS['registry']->hasMethod('mail/batchCompose');
        }

        $out = array();
        $rfc822 = $GLOBALS['injector']->getInstance('Horde_Mail_Rfc822');

        if (!is_array($data)) {
            $data = array($data);
        }

        foreach ($data as $email_vals) {
            foreach ($rfc822->parseAddressList($email_vals) as $ob) {
                $addr = strval($ob);
                $tmp = null;

                if (self::$_cache['useRegistry']) {
                    try {
                        $tmp = $GLOBALS['registry']->call('mail/batchCompose', array(array($addr)));
                    } catch (Horde_Exception $e) {
                        self::$_cache['useRegistry'] = false;
                    }
                }

                $tmp = empty($tmp)
                    ? 'mailto:' . urlencode($addr)
                    : reset($tmp);

                $out[] = Horde::link($tmp) . htmlspecialchars($addr) . '</a>';
            }
        }

        return implode(', ', $out);
    }

    /**
     * Returns the real name, if available, of a user.
     *
     * @param string $uid  The uid of the name to return.
     *
     * @return string  The user's full, real name.
     */
    public static function getUserName($uid)
    {
        if (!isset(self::$_cache['names'])) {
            self::$_cache['names'] = array();
        }

        if (!isset(self::$_cache['names'][$uid])) {
            $ident = $GLOBALS['injector']->getInstance('Horde_Core_Factory_Identity')->create($uid);
            $ident->setDefault($ident->getDefault());
            $name = $ident->getValue('fullname');
            self::$_cache['names'][$uid] = empty($name)
                ? $uid
                : $name;
        }

        return self::$_cache['names'][$uid];
    }

    /**
     * Gets extended permissions on an address book.
     *
     * @param Turba_Driver $addressBook  The address book to get extended
     *                                   permissions for.
     * @param string $permission         What extended permission to get.
     *
     * @return mixed  The requested extended permissions value, or true if it
     *                doesn't exist.
     */
    public static function getExtendedPermission(Turba_Driver $addressBook,
                                                 $permission)
    {
        // We want to check the base source as extended permissions
        // are enforced per backend, not per share.
        $key = $addressBook->getName() . ':' . $permission;

        $perms = $GLOBALS['injector']->getInstance('Horde_Perms');
        if (!$perms->exists('turba:sources:' . $key)) {
            return true;
        }

        $allowed = $perms->getPermissions('turba:sources:' . $key, $GLOBALS['registry']->getAuth());
        if (is_array($allowed)) {
            switch ($permission) {
            case 'max_contacts':
                $allowed = max($allowed);
                break;
            }
        }
        return $allowed;
    }

    /**
     * Checks the max_contacts permission.
     *
     * @param Turba_Driver $driver  The address book to check.
     * @param boolean $notify       If true, outputs error to notification.
     *
     * @return string  Error message if maximum contacts have been reached.
     *                 False otherwise.
     */
    public static function hasMaxContacts(Turba_Driver $driver, $notify = true)
    {
        $error = false;
        $max_contacts = Turba::getExtendedPermission($driver, 'max_contacts');

        if (($max_contacts !== true) && ($max_contacts <= count($driver))) {
            $error = sprintf(
                _("You are not allowed to create more than %d contacts in \"%s\"."),
                $max_contacts,
                $driver->title
            );

            Horde::permissionDeniedError(
                'turba',
                'max_contacts',
                $notify ? $error : null
            );
        }

        return $error;
    }

    /**
     * Filters sources based on permissions.
     *
     * @param array $in            The source list we want filtered.
     * @param integer $permission  The Horde_Perms::* constant we will filter
     *                             on.
     * @param array $options       Additional options:
     *                             - require_add: (boolean) Only return sources
     *                               that can be added to.
     *
     * @return array  The filtered data.
     */
    public static function permissionsFilter(array $in,
                                             $permission = Horde_Perms::READ,
                                             array $options = array())
    {
        $factory = $GLOBALS['injector']->getInstance('Turba_Factory_Driver');
        $out = array();

        foreach ($in as $sourceId => $source) {
            if (!isset($source['type'])) {
                continue;
            }
            try {
                $driver = $factory->createTrusted($source, $sourceId);
                if ($driver->hasPermission($permission) &&
                    (empty($options['require_add']) || $driver->canAdd())) {
                    $out[$sourceId] = $source;
                }
            } catch (Turba_Exception $e) {
                Horde::log($e, 'ERR');
            }
        }

        return $out;
    }

    /**
     * Replaces all share-enabled sources in a source list with all shares
     * from this source that the current user has access to.
     *
     * This will only sync shares that are unique to Horde (such as a SQL or
     * Kolab sources).  Any backend that supports ACLs or similar mechanism
     * should be configured from within backends.local.php or via Horde's
     * share_* hooks.
     *
     * @param array $sources  The default $cfgSources array.
     * @param boolean $owner  Only return shares that the current user owns?
     * @param array $options  An array of options:
     *        - shares: Use this list of provided shares. Default is to get the
     *                  list from the share system using the current user.
     *        - auth_user: Use this as the authenticated user name.
     *
     * @return array  The $cfgSources array.
     */
    public static function getConfigFromShares(array $sources, $owner = false, $options = array())
    {
        global $notification, $registry, $conf, $injector, $prefs;

        if (empty($options['shares'])) {
            try {
                $shares = self::listShares($owner);
            } catch (Horde_Share_Exception $e) {
                // Notify the user if we failed, but still return the $cfgSource
                // array.
                $notification->push($e, 'horde.error');
                return $sources;
            }
        } else {
            $shares = $options['shares'];
        }

        /* See if any of our sources are configured to handle all otherwise
         * unassigned Horde_Share objects. */
        $all_shares = null;
        foreach ($sources as $key => $cfg) {
            if (!empty($cfg['all_shares'])) {
                // Indicate the source handler that catches unassigned shares.
                $all_shares = $key;
            }
        }

        if (empty($options['auth_user'])) {
            $auth_user = $registry->getAuth();
        } else {
            $auth_user = $options['auth_user'];
        }

        $sortedSources = $vbooks = array();
        $personal = false;

        foreach ($shares as $name => &$share) {
            if (isset($sources[$name])) {
                continue;
            }

            $personal |= ($share->get('owner') == $auth_user);

            $params = @unserialize($share->get('params'));
            if (empty($params['source']) && !empty($all_shares)) {
                $params['source'] = $all_shares;
            }

            if (isset($params['type']) && $params['type'] == 'vbook') {
                // We load vbooks last in case they're based on other shares.
                $params['share'] = $share;
                $vbooks[$name] = $params;
            } elseif (!empty($params['source']) &&
                      !empty($sources[$params['source']]['use_shares'])) {
                if (empty($params['name'])) {
                    $params['name'] = $name;
                    $share->set('params', serialize($params));
                    try {
                        $share->save();
                    } catch (Horde_Share_Exception $e) {
                        Horde::log($e, 'ERR');
                    }
                }

                $info = $sources[$params['source']];
                $info['params']['config'] = $sources[$params['source']];
                $info['params']['config']['params']['share'] = $share;
                $info['params']['config']['params']['name'] = $params['name'];
                $info['title'] = $share->get('name');
                if ($share->get('owner') != $auth_user) {
                    $info['title'] .= ' [' . $registry->convertUsername($share->get('owner'), false) . ']';
                }
                $info['type'] = 'share';
                $info['use_shares'] = false;
                $sortedSources[$params['source']][$name] = $info;
            }
        }

        // Check for the user's default share and built new source list.
        $newSources = array();
        foreach (array_keys($sources) as $source) {
            if (empty($sources[$source]['use_shares'])) {
                $newSources[$source] = $sources[$source];
                continue;
            }

            if (isset($sortedSources[$source])) {
                $newSources = array_merge($newSources, $sortedSources[$source]);
            }

            if (!empty($conf['share']['auto_create']) &&
                $auth_user &&
                !$personal) {
                // User's default share is missing.
                try {
                    $driver = $injector
                        ->getInstance('Turba_Factory_Driver')
                        ->create($source);
                } catch (Turba_Exception $e) {
                    $notification->push($e->getMessage(), 'horde.error');
                    continue;
                }

                $sourceKey = strval(new Horde_Support_Randomid());
                try {
                    $share = $driver->createShare(
                        $sourceKey,
                        array(
                            'params' => array(
                                'source' => $source,
                                'default' => true,
                                'name' => $auth_user
                            )
                        )
                    );

                    $source_config = $sources[$source];
                    $source_config['params']['share'] = $share;
                    $newSources[$sourceKey] = $source_config;
                    $personal = true;
                    $prefs->setValue('default_dir', $share->getName());
                } catch (Horde_Share_Exception $e) {
                    Horde::log($e, 'ERR');
                }
            }
        }

        // Add vbooks now that all available address books are loaded.
        foreach ($vbooks as $name => $params) {
            if (isset($newSources[$params['source']])) {
                $newSources[$name] = array(
                    'title' => $shares[$name]->get('name'),
                    'type' => 'vbook',
                    'params' => $params,
                    'export' => true,
                    'browse' => true,
                    'map' => $newSources[$params['source']]['map'],
                    'search' => $newSources[$params['source']]['search'],
                    'strict' => $newSources[$params['source']]['strict'],
                    'use_shares' => false,
                );
            } else {
                $notification->push(sprintf(
                    _("Removing the virtual address book \"%s\" because the parent source has disappeared."),
                    $shares[$name]->get('name')), 'horde.message'
                );
                try {
                    $injector->getInstance('Turba_Shares')->removeShare($shares[$name]);
                } catch (Horde_Share_Exception $e) {
                    Horde::log($e, 'ERR');
                }
            }
        }

        return $newSources;
    }

    /**
     * Retrieve a new source config entry based on a Turba share.
     *
     * @param Horde_Share_Object object  The share to base config on.
     *
     * @return array  The $cfgSource entry for this share source.
     */
    public static function getSourceFromShare(Horde_Share_Object $share)
    {
        // Require a fresh config file.
        $cfgSources = self::availableSources();

        $params = @unserialize($share->get('params'));
        $newConfig = $cfgSources[$params['source']];
        $newConfig['params']['config'] = $cfgSources[$params['source']];
        $newConfig['params']['config']['params']['share'] = $share;
        $newConfig['params']['config']['params']['name'] = $params['name'];
        $newConfig['title'] = $share->get('name');
        $newConfig['type'] = 'share';
        $newConfig['use_shares'] = false;

        return $newConfig;
    }

    /**
     * Returns all shares the current user has specified permissions to.
     *
     * @param boolean $owneronly   Only return address books owned by the user?
     *                             Defaults to false.
     * @param integer $permission  Permissions to filter by.
     *
     * @return array  Shares the user has the requested permissions to.
     */
    public static function listShares($owneronly = false,
                                      $permission = Horde_Perms::READ)
    {
        if (!$GLOBALS['session']->get('turba', 'has_share') ||
            ($owneronly && !$GLOBALS['registry']->getAuth())) {
            return array();
        }

        try {
            return $GLOBALS['injector']->getInstance('Turba_Shares')->listShares(
                $GLOBALS['registry']->getAuth(),
                array(
                    'attributes' => $owneronly ? $GLOBALS['registry']->getAuth() : null,
                    'perm' => $permission
                )
            );
        } catch (Horde_Share_Exception $e) {
            Horde::log($e, 'ERR');
            return array();
        }
    }

    /**
     * Create a new Turba share.
     *
     * @param string $share_name  The id for the new share.
     * @param array  $params      Parameters for the new share.
     *
     * @return Horde_Share  The new share object.
     * @throws Turba_Exception
     */
    public static function createShare($share_name, $params)
    {
        if (isset($params['name'])) {
            $name = $params['name'];
            unset($params['name']);
        } else {
            /* Sensible default for empty display names */
            $name = sprintf(_("Address book of %s"), $GLOBALS['injector']->getInstance('Horde_Core_Factory_Identity')->create()->getName());
        }

        /* Generate the new share. */
        try {
            $turba_shares = $GLOBALS['injector']->getInstance('Turba_Shares');

            $share = $turba_shares->newShare($GLOBALS['registry']->getAuth(), $share_name, $name);

            /* Now any other params. */
            foreach ($params as $key => $value) {
                if (!is_scalar($value)) {
                    $value = serialize($value);
                }
                $share->set($key, $value);
            }
            $turba_shares->addShare($share);
            $share->save();
        } catch (Horde_Share_Exception $e) {
            Horde::log($e, 'ERR');
            throw new Turba_Exception($e);
        }

        return $share;
    }

    /**
     * Add browse.js javascript to page.
     */
    public static function addBrowseJs()
    {
        global $page_output;

        $page_output->addScriptFile('browse.js');
        $page_output->addInlineJsVars(array(
            'TurbaBrowse.confirmdelete' => _("Are you sure that you want to delete %s?"),
            'TurbaBrowse.contact1' => _("You must select at least one contact first."),
            'TurbaBrowse.contact2' => _("You must select a target contact list."),
            'TurbaBrowse.contact3' => _("Please name the new contact list:"),
            'TurbaBrowse.copymove' => _("You must select a target address book."),
            'TurbaBrowse.submit' => _("Are you sure that you want to delete the selected contacts?")
        ));
    }

    /**
     * Return an array of all available attributes of type 'email'. Optionally,
     * ensure the field is defined in the specified $source.
     *
     * @param $source string       An optional source identifier.
     * @param $searchable boolean  If true, and $source is provided, ensure that
     *                             the email field is a configured searchable
     *                             field.
     *
     * @return array  An array of email fields.
     * @since  4.2.9
     */
    public static function getAvailableEmailFields($source = null, $searchable = true)
    {
        global $attributes, $injector, $cfgSources;

        if (!empty($source)) {
            $driver = $injector->getInstance('Turba_Factory_Driver')
                ->create($source);
        }

        $emailFields = array();
        foreach ($attributes as $field => $data) {
            if ($data['type'] == 'email') {
                if (empty($source) || (!empty($source) &&
                    in_array($field, array_keys($driver->map)) &&
                    (!$searchable || ($searchable && in_array($field, $cfgSources[$source]['search'])))))

                    $emailFields[] = $field;
            }
        }

        return $emailFields;
    }

}
