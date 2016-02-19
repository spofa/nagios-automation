<?php
/**
 * Created by PhpStorm.
 * User: bpeters
 * Date: 1/21/2016
 * Time: 3:30 PM
 */

# Include the classes and Config files
include('Classes.php');
include('Config.php');

###################################################################################################################################
#
# The stuff below here is the "nuts and bolts" of the script.  It is what handles the actual generation.  You shouldn't need
# to edit much of anything here... if you have questions / problems, please let me know.  I tried to comment everything as best
# I could, so that if someone does need to make changes, they know what it is doing!
#
# It essentially generates a new contact config file, and server config file.
#
# Most edits should likely be done to Config.php
#
###################################################################################################################################

# Make sure we can even connect to Lansweeper and AD.  If we can't, don't do anything, or our config files get wrecked!
if ($Servers = new LansweeperDB()) {

    if ($LDAP = new LDAP()) {

        # Get a list of all the services we're monitoring
        $Inventory = new MonitorDB();
        $ServicesToMonitor  = $Inventory->BuildMonitorsForNagios();

        ################################################################
        # Build the contact list
        ################################################################

        # Start building the team definition output
        $output =  '###########################################' . PHP_EOL;
        $output .=  '# !!!! WARNING !!!!' . PHP_EOL;
        $output .=  '###########################################' . PHP_EOL;
        $output .= PHP_EOL;
        $output .= '# This file was automatically generated by server_and_contact_generation.php.  Do not edit it manually!!!' . PHP_EOL;
        $output .= '# If you need to change this, please edit that PHP file instead.' . PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= '###########################################' . PHP_EOL;
        $output .= '# Team Contact Definitions' . PHP_EOL;
        $output .= '###########################################' . PHP_EOL;

        foreach (Config::$Groups as $InvGroup => $LDAPGroup) {

            # Get members of the group
            $userlist = '';

            # Get any sub groups within this main group
            $subgroups = $LDAP->getGroupMemberGroups('CN=' . $LDAPGroup . ',' . Config::$ldap_basedn);

            # Go through each sub group and pull out the members
            $groupnum = 0;
            while ($groupnum < $subgroups['count']) {

                # Run through all the group members, and build a comma separated list, and add the user to an array for use later
                $users = $LDAP->getGroupUsers($subgroups[$groupnum]['dn']);

                $usernum = 0;
                while ($usernum < $users['count']) {
                    $userlist .= $users[$usernum]['samaccountname'][0] . ",";
                    if ($LDAPGroup == Config::$LabUserGroup) {
                        $email = $users[$usernum]['samaccountname'][0] . '@winmon.' . Config::$EmailDomain;
                        if (!in_array($users[$usernum]['samaccountname'][0], Config::$UsersToOverrideLabRestrictions)) {
                            Config::$studentarray[$email] = $users[$usernum]['samaccountname'][0];
                            Config::$restrictedUsers .= $users[$usernum]['samaccountname'][0] . ',';
                        }
                    } else {
                        $email = $users[$usernum]['samaccountname'][0] . "@" . Config::$EmailDomain;
                        Config::$userarray[$email] = $users[$usernum]['samaccountname'][0];
                    }

                    $usernum++;
                }

                $groupnum++;
            }


            # Run through all the group members, and build a comma separated list, and add the user to an array for use later
            $users = $LDAP->getGroupUsers('CN=' . $LDAPGroup . ',CN=users,DC=ad,DC=emich,DC=edu');

            $i = 0;
            while ($i < $users['count']) {
                $userlist .= $users[$i]['samaccountname'][0] . ",";
                if ($LDAPGroup == Config::$LabUserGroup) {
                    $email = $users[$i]['samaccountname'][0] . '@winmon' . Config::$EmailDomain;
                    if (!in_array($users[$i]['samaccountname'][0], Config::$UsersToOverrideLabRestrictions)) {
                        Config::$studentarray[$email] = $users[$i]['samaccountname'][0];
                        Config::$restrictedUsers .= $users[$i]['samaccountname'][0] . ',';
                    }
                } else {
                    $email = $users[$i]['samaccountname'][0] . "@" . Config::$EmailDomain;
                    Config::$userarray[$email] = $users[$i]['samaccountname'][0];
                }

                $i++;
            }

            # Trim trailing comma from user list
            $userlist = rtrim($userlist, ",");

            # Generate the contact group definition
            $output .= PHP_EOL;
            $output .= 'define contactgroup{' . PHP_EOL;
            $output .= '        contactgroup_name       ' . $LDAPGroup . PHP_EOL;
            $output .= '        alias                   ' . $LDAPGroup . PHP_EOL;
            $output .= '        members                 ' . $userlist . PHP_EOL;
            $output .= '        }' . PHP_EOL;
            $output .= PHP_EOL;

        }

        # Trim the trailing comma off restricted user list
        Config::$restrictedUsers = rtrim(Config::$restrictedUsers, ",");

        # Get all the members of the nagios admin group
        $users = $LDAP->getGroupUsers(Config::$AdminGroup);
        $adminUsers = '';
        $i = 0;
        while ($i < $users['count']) {
            $adminUsers .= $users[$i]['samaccountname'][0] . ",";
            $email = $users[$i]['samaccountname'][0] . "@" . Config::$EmailDomain;
            Config::$userarray[$email] = $users[$i]['samaccountname'][0];
            $i++;
        }

        # Trim the trailing comma off admin user list
        $adminUsers = rtrim($adminUsers, ",");

        # Build the output for the CGI access file
        $cgiCFGOutput = PHP_EOL;
        $cgiCFGOutput .= '###########################################' . PHP_EOL;
        $cgiCFGOutput .= '# Custom CGI Access From LDAP Groups' . PHP_EOL;
        $cgiCFGOutput .= '###########################################' . PHP_EOL;
        $cgiCFGOutput .= PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_read_only=' . Config::$restrictedUsers . PHP_EOL;
        $cgiCFGOutput .= PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_system_information=' . $adminUsers . PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_configuration_information=' . $adminUsers . PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_system_commands=' . $adminUsers . PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_all_service_commands=' . $adminUsers . PHP_EOL;
        $cgiCFGOutput .= 'authorized_for_all_host_commands=' . $adminUsers . PHP_EOL;

        # Remove the last entry for the restricted users
        $lines = file(Config::$NagiosPath . 'etc/cgi.cfg');
        $lines = array_slice($lines, 0, -12, true);
        $lines = implode($lines);
        $lines = $lines . $cgiCFGOutput;

        # Place the restricted users into the file
        file_put_contents(Config::$NagiosPath . 'etc/cgi_new.cfg', $lines);

        # Get all the servers, to find the contact info
        $list = $Servers->getServersWithNagios();

        # Use this data to pull all individual contacts out, and add them to our array
        foreach ($list as $server) {

            # See if each contact is a team.  If so, ignore.  If an individual, add the user to our list of users to add to our contact list later
            $fieldsToCheck = array('Primary OS Contact', 'Secondary OS Contact', 'Primary App Contact', 'Secondary App Contact');
            foreach ($fieldsToCheck as $row) {
                if (substr($server[$row], 0, 4) != "Team" && $server[$row] != '') {
                    $username = $server[$row];
                    $email = $username . "@" . Config::$EmailDomain;
                    Config::$userarray[$email] = $username;
                }

            }
        }


        # Start building the individual contacts output
        $output .= PHP_EOL;
        $output .= '###########################################' . PHP_EOL;
        $output .= '# User Contact Definitions' . PHP_EOL;
        $output .= '###########################################' . PHP_EOL;
        $output .= PHP_EOL;

        # Now go through the list of all the individual users, and remove any duplicates
        Config::$userarray = array_unique(Config::$userarray);

        # Build a contact file for each user in the list - but omit e-mail addresses from lab students
        foreach (Config::$userarray as $key => $value) {
            $output .= 'define contact{' . PHP_EOL;
            $output .= '        contact_name            ' . $value . PHP_EOL;
            $output .= '        use                     generic-contact' . PHP_EOL;
            $output .= '        alias                   ' . $value . '-AD' . PHP_EOL;
            $output .= '        email                   ' . $key . PHP_EOL;
            $output .= '}' . PHP_EOL;
            $output .= PHP_EOL;
        }

        # Now go through the list of all the individual users, and remove any duplicates from it, or that were already in the user array
        Config::$studentarray = array_unique(Config::$studentarray);
        Config::$studentarray = array_diff(Config::$studentarray, Config::$userarray);


        # Build a contact file for each student in the list - but omit e-mail addresses from lab students
        foreach (Config::$studentarray as $key => $value) {
            $output .= 'define contact{' . PHP_EOL;
            $output .= '        contact_name            ' . $value . PHP_EOL;
            $output .= '        use                     student-contact' . PHP_EOL;
            $output .= '        alias                   ' . $value . '-AD' . PHP_EOL;
            $output .= '}' . PHP_EOL;
            $output .= PHP_EOL;
        }

        file_put_contents(Config::$NagiosPath . 'etc/objects/contacts_from_ad_new.cfg', $output);

        ################################################################
        # Build the server list
        ################################################################

        # Start building the server output
        $output =  '###########################################' . PHP_EOL;
        $output .=  '# !!!! WARNING !!!!' . PHP_EOL;
        $output .=  '###########################################' . PHP_EOL;
        $output .= PHP_EOL;
        $output .= '# This file was automatically generated by server_and_contact_generation.php.  Do not edit it manually!!!' . PHP_EOL;
        $output .= '# If you need to change this, please edit that PHP file instead.' . PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output .= PHP_EOL;
        $output =  '###########################################' . PHP_EOL;
        $output .= '# Windows Server Definitions' . PHP_EOL;
        $output .= '###########################################' . PHP_EOL;
        $output .= PHP_EOL;

        # Get a list of all DCs and imaging servers
        $DCs = $Servers->getDomainControllers();
        $Imaging = $Servers->getImagingServers();

        foreach ($list as $server) {

            # Make sure we aren't supposed to ignore this server for some reason
            if (!in_array($server['AssetName'], Config::$ServersToIgnore)) {

                # Go through each defined group.  If this server has one listed as a contact, add it to the contact group list for the server.
                $contactgroups = Config::$ContactGroupForAllServers;
                foreach (Config::$Groups as $INVGroup => $LDAPGroup) {
                    if ($server['Primary OS Contact'] == $INVGroup || $server['Secondary OS Contact'] == $INVGroup || $server['Primary App Contact'] == $INVGroup || $server['Secondary App Contact'] == $INVGroup) {
                        $contactgroups .= ',' . $LDAPGroup;
                    }
                }

                # Check to see if this server is run by an individual, add their username as a contact to the individual contact list; but only once!
                $fieldsToCheck = array('Primary OS Contact', 'Secondary OS Contact', 'Primary App Contact', 'Secondary App Contact');
                $individualContacts = '';
                $contactsArray = array();
                foreach ($fieldsToCheck as $field) {
                    if (substr($server[$field], 0, 4) != "Team" && $server[$field] != '') {
                        if (!in_array($server[$field], $contactsArray)) {
                            $individualContacts .= $server[$field] . ",";
                            array_push($contactsArray, $server[$field]);
                        }

                    }
                }

                # Strip trailing comma from the contact list
                $individualContacts = rtrim($individualContacts, ',');

                # See what special services should be monitored on this server
                $services = $server['NagiosServices'];
                $services = explode(',', $services);

                # See which customs services this server should monitor
                foreach ($services as $service) {

                    # Make sure this is a known service defined above.  Only add it to the list only if it's a legitimate service name
                    if (isset($ServicesToMonitor[$service])) {
                        $ServicesToMonitor[$service]['host_name'] .= $server['AssetName'] . ',';
                    }

                }

                # IF this is a VM, add it to the VM Tools monitor
                if (substr($server['Make'], 0, 6) == "VMware") {
                    $ServicesToMonitor['VMTools']['host_name'] .= $server['AssetName'] . ',';
                }

                # Build the host groups for this server, and append extra host groups based on lansweeper query results
                $HostGroups = "windows-servers";

                if (in_array($server['AssetName'], $DCs)) {
                    $HostGroups .= ",windows-servers-dcs";
                }

                if (in_array($server['AssetName'], $Imaging)) {
                    $HostGroups .= ",windows-servers-imaging";
                }

                # Check which downtime window for this host
                if ($server['Window'] == "Prod") {
                    $HostGroups .= ",Downtime-Prod";
                } else if ($server['Window'] == "Test") {
                    $HostGroups .= ",Downtime-Test";
                } else if ($server['Window'] == "Tier4") {
                    $HostGroups .= ",Auto-Patch-And-Reboot";
                }

                # Build the individual host output
                $output .= 'define host{' . PHP_EOL;
                $output .= '    use             windows-server' . PHP_EOL;
                $output .= '    host_name       ' . $server['AssetName'] . PHP_EOL;
                $output .= '    alias           ' . $server['AssetName'] . PHP_EOL;
                $output .= '    address         ' . $server['IPAddress'] . PHP_EOL;
                $output .= '    hostgroups      ' . $HostGroups . PHP_EOL;
                $output .= '    contact_groups  ' . $contactgroups . PHP_EOL;
                if ($individualContacts != '') {
                    $output .= '    contacts        ' . $individualContacts . PHP_EOL;
                }
                $output .= '}' . PHP_EOL;
                $output .= PHP_EOL;
            }


        }

        ################################################################
        # Build the service list
        ################################################################

        $output .= '###########################################' . PHP_EOL;
        $output .= '# Windows Service Definitions' . PHP_EOL;
        $output .= '###########################################' . PHP_EOL;
        $output .= PHP_EOL;

        # Go through each custom service defined at the top
        foreach ($ServicesToMonitor as $row) {

            # Make sure there is at least one host that uses this.  If none, don't bother including it.
            if ($row['host_name'] != '') {

                # Strip trailing comma from the host list
                $row['host_name'] = rtrim($row['host_name'], ',');

                # Build output config for this service
                $output .= 'define service{' . PHP_EOL;
                foreach ($row as $key => $value) {
                    $output .= '    ' . $key . '        ' . $value . PHP_EOL;
                }
                $output .= '}' . PHP_EOL;
                $output .= PHP_EOL;
            }
        }

        # Send the list of servers to the nagios config file
        file_put_contents(Config::$NagiosPath . 'etc/objects/servers_from_lansweeper_new.cfg', $output);
    }
}
?>