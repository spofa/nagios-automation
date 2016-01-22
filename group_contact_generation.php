<?php
/**
 * Created by PhpStorm.
 * User: bpeters
 * Date: 1/21/2016
 * Time: 3:30 PM
 */

# Define the group names we'll be using to create config files
$Groups = array('doit-sit-team', 'doit-dba-team', 'doit-pss-team', 'doit_lab_attendants');

class LDAP {

    # Enter your LDAP connection details here
    public static $ldap_host = 'ad.emich.edu';
    public static $ldap_port = '389';
    public static $ldap_basedn = 'CN=users,DC=ad,DC=emich,DC=edu';
    public static $ldap_user = 'bpeters';
    public static $ldap_pass =  'unit7oscodaisfun';

    protected $AD;

    function __construct() {
        $this->AD = @ldap_connect(LDAP::$ldap_host, LDAP::$ldap_port) or die( "LDAP Service is not available at this time");
        ldap_set_option($this->AD, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($this->AD, LDAP_OPT_REFERRALS, 0);
        $ldapbind = @ldap_bind($this->AD, LDAP::$ldap_user . "@" . LDAP::$ldap_host, LDAP::$ldap_pass);
        if(!$ldapbind){ die("Bind failed"); }
    }

    function getGroupusers($group) {
        $filter = "(&(objectClass=user)(memberOf=$group))";
        $justthese = array("samaccountname");
        $results = ldap_search($this->AD, LDAP::$ldap_basedn, $filter, $justthese);
        $users = ldap_get_entries($this->AD, $results);
        return $users;
    }

    function getGroupMemberGroups($group) {
        $filter = "(&(objectClass=group)(memberOf=$group))";
        $justthese = array("samaccountname");
        $results = ldap_search($this->AD, LDAP::$ldap_basedn, $filter, $justthese);
        $groups = ldap_get_entries($this->AD, $results);
        return $groups;
    }


}

class LansweeperDB
{
    protected $db;

    public static $LansweeperHost = 'lansweeper';
    public static $LansweeperUser = 'AD\bpeters';
    public static $LansweeperPassword = 'unit7oscodaisfun';

    function __construct()
    {
        $this->db = mssql_connect(LansweeperDB::$LansweeperHost, LansweeperDB::$LansweeperUser, LansweeperDB::$LansweeperPassword);
        mssql_select_db('lansweeperdb', $this->db);
    }

    function getServersWithSplunk() {
        $sql = "Select Top 1000000 tblAssets.AssetID,
                  tblAssets.AssetName,
                  tblAssets.Description,
                  tsysOS.Image As icon,
                  tblAssetCustom.Custom1 As [Primary OS Contact],
                  tblAssetCustom.Custom2 As [Secondary OS Contact],
                  tblAssetCustom.Custom3 As [Primary App Contact],
                  tblAssetCustom.Custom4 As [Secondary App Contact],
                  tblAssets.IPAddress
                From tblAssets
                  Inner Join tblAssetCustom On tblAssets.AssetID = tblAssetCustom.AssetID
                  Inner Join tsysOS On tblAssets.OScode = tsysOS.OScode
                  Inner Join tblComputersystem On tblAssets.AssetID = tblComputersystem.AssetID
                Where tblAssets.AssetID In (Select tblSoftware.AssetID
                  From tblSoftware Inner Join tblSoftwareUni On tblSoftwareUni.SoftID =
            tblSoftware.softID
                  Where dbo.tblsoftwareuni.softwareName Like '%NSClient%') And
                  tsysOS.OSname Like '%Win 2%' And tblAssetCustom.State = 1";
        $result = array();
        $query=mssql_query($sql);
        if (mssql_num_rows($query)) {
            while ($row = mssql_fetch_assoc($query)) {
                $result[] = $row;
            }
        }
        return $result;
    }
}


################################################################
# Build the contact list
################################################################

# Set an array of users in all the groups, so we can use it later to build individual contacts
$userarray = array();

# Start building the team definition output
$output =  '###########################################' . PHP_EOL;
$output .= '# Team Contact Definitions' . PHP_EOL;
$output .= '###########################################' . PHP_EOL;

foreach ($Groups as $group) {

    # Get members of the group
    $LDAP = new LDAP();
    $userlist = '';

    # Get any sub groups within this main group
    $subgroups = $LDAP->getGroupMemberGroups('CN=' . $group . ',CN=users,DC=ad,DC=emich,DC=edu');

    # Go through each sub group and pull out the members
    $groupnum = 0;
    while ($groupnum < $subgroups['count']) {

        # Run through all the group members, and build a comma separated list, and add the user to an array for use later
        $users = $LDAP->getGroupUsers($subgroups[$groupnum]['dn']);

        $usernum = 0;
        while ($usernum < $users['count']) {
            $userlist .= $users[$usernum]['samaccountname'][0] . ",";
            array_push($userarray, $users[$usernum]['samaccountname'][0] );
            $usernum++;
        }

        $groupnum++;
    }


    # Run through all the group members, and build a comma separated list, and add the user to an array for use later
    $users = $LDAP->getGroupUsers('CN=' . $group . ',CN=users,DC=ad,DC=emich,DC=edu');

    $i = 0;
    while ($i < $users['count']) {
        $userlist .= $users[$i]['samaccountname'][0] . ",";
        array_push($userarray, $users[$i]['samaccountname'][0] );
        $i++;
    }

    # Trim trailing comma from user list
    $userlist = rtrim($userlist, ",");

    # Generate the contact group definition
    $output .= PHP_EOL;
    $output .= 'define contactgroup{' . PHP_EOL;
    $output .= '        contactgroup_name       ' . $group . PHP_EOL;
    $output .= '        alias                   ' . $group . PHP_EOL;
    $output .= '        members                 ' . $userlist . PHP_EOL;
    $output .= '        }' . PHP_EOL;
    $output .= PHP_EOL;

}

# Start building the individual contacts output
$output .= PHP_EOL;
$output .= '###########################################' . PHP_EOL;
$output .= '# User Contact Definitions' . PHP_EOL;
$output .= '###########################################' . PHP_EOL;
$output .= PHP_EOL;

# Now go through the list of all the individual users, and remove any duplicates
$userarray = array_unique($userarray);

# Build a contact file for each user in the list
foreach ($userarray as $user) {
    $output .= 'define contact{' . PHP_EOL;
    $output .= '        contact_name            ' . $user . PHP_EOL;
    $output .= '        use                     generic-contact' . PHP_EOL;
    $output .= '        alias                   ' . $user . '-AD' . PHP_EOL;
    $output .= '        email                   ' . $user . '@emich.edu' . PHP_EOL;
    $output .= '}' . PHP_EOL;
    $output .= PHP_EOL;
}

file_put_contents('/usr/local/nagios/etc/objects/contacts_from_ad.cfg', $output);

################################################################
# Build the server list
################################################################

# Run a SQL search against lansweeper to find all active windows servers with Nagios installed
$Servers = new LansweeperDB();
$list = $Servers->getServersWithSplunk();

# Start building the server output
$output =  '###########################################' . PHP_EOL;
$output .= '# Windows Server Definitions' . PHP_EOL;
$output .= '###########################################' . PHP_EOL;
$output .= PHP_EOL;

foreach ($list as $server) {

    # Check who the contact people are, and build our host group list accordingly. Add it to basic windows servers by default.
    $contactgroups = 'WindowsTeam,';
    if ($server['Primary OS Contact'] == 'Team - SIT' || $server['Secondary OS Contact'] == 'Team - SIT' || $server['Primary App Contact'] == 'Team - SIT' || $server['Secondary App Contact'] == 'Team - SIT') {
        $contactgroups .= 'doit-sit-team,';
    }
    if ($server['Primary OS Contact'] == 'Team - DBA' || $server['Secondary OS Contact'] == 'Team - DBA' || $server['Primary App Contact'] == 'Team - DBA' || $server['Secondary App Contact'] == 'Team - DBA') {
        $contactgroups .= 'doit-dba-team,';
    }
    if ($server['Primary OS Contact'] == 'Team - PSS' || $server['Secondary OS Contact'] == 'Team - PSS' || $server['Primary App Contact'] == 'Team - PSS' || $server['Secondary App Contact'] == 'Team - PSS') {
        $contactgroups .= 'doit-pss-team,';
    }

    # Trim trailing comma from contact group list
    $contactgroups = rtrim($contactgroups, ",");

    $output .= 'define host{' . PHP_EOL;
    $output .= '    use             windows-server' . PHP_EOL;
    $output .= '    host_name       ' . $server['AssetName'] . PHP_EOL;
    $output .= '    alias           ' . $server['AssetName'] . PHP_EOL;
    $output .= '    address         ' . $server['IPAddress'] . PHP_EOL;
    $output .= '    hostgroups      windows-servers' . PHP_EOL;
    $output .= '    contact_groups  ' . $contactgroups . PHP_EOL;
    $output .= '}' . PHP_EOL;
    $output .= PHP_EOL;
}

# Send the list of servers to the nagios config file
file_put_contents('/usr/local/nagios/etc/objects/servers_from_lansweeper.cfg', $output);

?>