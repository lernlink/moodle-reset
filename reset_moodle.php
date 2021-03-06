<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../config.php');

$backup = false;
$restore = false;
$dir = null;
$fulldir = null;
$moodledata = "moodledata.tar.gz"; // Default File
$database = "dump.sql"; // Default File
$update = false;

$home = getcwd();
// Get current directory for things later on... This needs improved.

// TODO: Handle moodledata backup & restores without using shell_exec()

// Messages
$helpmsg =
"\nBy default, ensure ".$database." and ".$moodledata." exists in the current directory.
--backup :  Runs backup script, dumping Database and backing up Moodledata into the ".$home." directory.
            Run this first to generate backup files for restoring later.
            Example:
            reset_moodle.php --backup
--restore : Runs restore script using the dumped database and Moodledata archive supplied.
            --moodledata= : Location of your moodledata backup file (in .tar.gz format & extension)
            --database=   : Location of your database backup file (in .sql format & extension)
            Example:
            reset_moodle.php --restore (Restore using dump.sql and moodledata.tar.gz in this folder)
            reset_moodle.php --restore --moodledata=/path/of/moodledata.tar.gz --database=/path/of/database.sql (Restore using specified backup files)
\n";

$introRestore =
"\nThis script will reset the current Moodle site back to it's original state with the provided backup files.
\n";

$introBackup =
"\nThis script will backup the current Moodle site suitably for restoring at a later date.
\n";

$htmlText = "
<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <title>Site is currently resetting...</title>
</head>
<body>
    <div style=\"height:200px; width:400px; position:fixed; top:50%; left:50%; margin-top:-100px; margin-left:-200px; text-align:center;\">
    <img src=\"http://www.howtomoodle.com/wp-content/themes/htm/images/howtomoodle.png\">
    <p>
    This site is currently being reset.
    <p>
    Please try again in 1 minute.
    </div>
</body>
</html>
";

// FUNCTIONS
// TODO: Fix Dump database function - causing issues with NULLed statements
// dumpDatabase($CFG->dbhost,$CFG->dbuser,$CFG->dbpass,$CFG->dbname,$tables=false,$database);
function dumpDatabase($host,$user,$pass,$name,$database ) {
    $mysqli = new mysqli($host,$user,$pass,$name);
    $mysqli->select_db($name);
    $mysqli->query("SET NAMES 'utf8'");

    $queryTables    = $mysqli->query('SHOW TABLES');
    while($row = $queryTables->fetch_row()) {
        $target_tables[] = $row[0];
    }
    if($tables !== false) {
        $target_tables = array_intersect( $target_tables, $tables);
    }
    foreach($target_tables as $table) {
        $result         =   $mysqli->query('SELECT * FROM '.$table);
        $fields_amount  =   $result->field_count;
        $rows_num=$mysqli->affected_rows;
        $res            =   $mysqli->query('SHOW CREATE TABLE '.$table);
        $TableMLine     =   $res->fetch_row();
        $content        = (!isset($content) ?  '' : $content) . "\n\n".$TableMLine[1].";\n\n";

        for ($i = 0, $st_counter = 0; $i < $fields_amount;   $i++, $st_counter=0) {
            while($row = $result->fetch_row()) {
                // When started (and every after 100 command cycle):
                    if ($st_counter%100 == 0 || $st_counter == 0 ) {
                    $content .= "\nINSERT INTO ".$table." VALUES";
                }
                $content .= "\n(";
                for ($j=0; $j<$fields_amount; $j++) {
                    $row[$j] = str_replace("\n","\\n", addslashes($row[$j]) );
                    if (isset($row[$j])) {
                        $content .= '"'.$row[$j].'"' ;
                    }
                    else {
                        $content .= '""';
                    }
                    if ($j<($fields_amount-1)) {
                        $content.= ',';
                    }
                }
                $content .=")";
                // Every after 100 command cycle [or at last line] ....p.s. but should be inserted 1 cycle eariler
                if ( (($st_counter+1)%100==0 && $st_counter!=0) || $st_counter+1==$rows_num) {
                    $content .= ";";
                }
                else {
                    $content .= ",";
                }
                $st_counter=$st_counter+1;
            }
        }
        $content .="\n";
    }
    $tmpdb = fopen($database, "w") or die("Unable to open file!");
    fwrite($tmpdb, $content);
    fclose($tmpdb);
}

// Import database function
// importDatabase($CFG->dbhost,$CFG->dbuser,$CFG->dbpass,$CFG->dbname,"dump.sql");
function importDatabase($host,$user,$pass,$dbname,$sqlfile) {
    set_time_limit(3000);
    $SQL_CONTENT = (strlen($sqlfile) > 200 ?  $sqlfile : file_get_contents($sqlfile));
    $allLines = explode("\n",$SQL_CONTENT);
    $mysqli = new mysqli($host, $user, $pass, $dbname);
    if (mysqli_connect_errno()) {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
    }
    $foreignKey = $mysqli->query('SET foreign_key_checks = 0');
    preg_match_all("/\nCREATE TABLE(.*?)\`(.*?)\`/si", "\n". $SQL_CONTENT, $target_tables);
    foreach ($target_tables[2] as $table) {
        $mysqli->query('DROP TABLE IF EXISTS '.$table);
    }
    $foreignKey = $mysqli->query('SET foreign_key_checks = 1');
    $mysqli->query("SET NAMES 'utf8'");
    $templine = '';	// Temporary variable, used to store current query
    foreach ($allLines as $line) { // Loop through each line
        if (substr($line, 0, 2) != '--' && $line != '') {
            $templine .= $line; // (if it is not a comment..) Add this line to the current segment
            if (substr(trim($line), -1, 1) == ';') { // If it has a semicolon at the end, it's the end of the query
                $mysqli->query($templine) or print('Error performing query \'<strong>' . $templine . '\': ' . $mysqli->error . '<br /><br />');  $templine = ''; // set variable to empty, to start picking up the lines after ";"
            }
        }
    }
}

// Create temporary index.php for maintenance notice...
$moodleIndex = __DIR__ . "/../index.php";
$backupIndex = __DIR__ . "/../index.backup.tmp";

if (file_exists($backupIndex) && file_exists($moodleIndex)) {
    // 	If both $moodleIndex and $backupIndex exist, halt. We cannot guess what should be done with existing files
    echo "Both ".$moodleIndex." and ".$backupIndex." exist! Clean before continuing!\n";
    die();
}
elseif (file_exists($moodleIndex)) {
    // 	echo "index.php exists";
}
else {
    echo "Cannot find ". $moodleIndex . " Or " . $backupIndex . "\n";
}


// Check the first argument exists...
if (isset($argv[1])) {
    $options = $argv[1];
    // 	Get only the argument we want out of the array
}
else {
    $options = null;
    // 	If null, that means there was no argument
}

// Check for second and third arguments which can be either way round...
$optMoodledata = "--moodledata=";
$optDatabase = "--database=";

if (isset($argv[2])) {
    $arg2 = $argv[2];
}
else {
    $arg2 = null;
}
if (isset($argv[3])) {
    $arg3 = $argv[3];
}
else {
    $arg3 = null;
}
if (isset($argv[4])) {
    $arg4 = $argv[4];
}
else {
    $arg4 = null;
}

// Check if it's the argument we want
if ($options == '--backup') {
    $backup = true;
    $restore = false;
} elseif ($options == '--restore') {
    $restore = true;
    $backup = false;
} elseif ($options == '-h' OR $options == '--help') {
    echo $helpmsg;
    die();
} elseif ($options != null) {
    echo "Unsuppoted Option. See --help for information.\n";
} else {
    echo "\nMissing argument! Run --help for information.\n";
}
// Check second and thirds are specified...
if ($backup == false && $restore == false) {
    if ($options != "--backup") {
        echo "No backup files specified. Using default location.\n";
        $moodledata = $moodledata;
        $database = $database;
    }
} elseif (strpos($arg2, '--database=') !== false && $arg3 == null) {
    echo "Moodledata backup file not specified! Please specify with \"--moodledata=\"\n";
    die();
} elseif (strpos($arg2, '--moodledata=') !== false && $arg3 == null) {
    echo "Database backup file not specified! Please specify with \"--database=\"\n";
    die();
} elseif (strpos($arg2, '--update') !== false && $arg3 == null) {
    $update = true;
} elseif (strpos($arg4, '--update') !== false && $arg2 != null && $arg3 != null) {
    $update = true;
}
// Sort arguments into the right variables...
if ($arg2 != null) {
    if (strpos($arg2, '--database=') !== false) {
        $database = str_replace('--database=','',$arg2);
        if (strpos($arg3, '--moodledata=') !== false) {
            $moodledata = str_replace('--moodledata=','',$arg3);
        }
    } elseif (strpos($arg2, '--moodledata=') !== false) {
        $moodledata = str_replace('--moodledata=','',$arg2);
        if (strpos($arg3, '--database=') !== false) {
            $database = str_replace('--database=','',$arg3);
        }
    }
}

// Check $CFG->dataroot can be found...
if (!file_exists($CFG->dataroot)) {
    if ($backup == true) {
        $backup = false;
        die("Cannot perform backup, $CFG->dataroot not found");
    }
} else {
    $fulldir = $CFG->dataroot;
    $datadir = substr($fulldir, strrpos($fulldir, '/') + 1); //Get actual name of the data folder...
    $dir = str_replace($datadir,'',$fulldir); //Strip name of folder from dir
    $moodledata = $datadir.".tar.gz";
}

// Check format of files
if ($moodledata != null) {
    if (strpos($moodledata, '.tar.gz') == false) {
        echo "Moodledata must be in .tar.gz format and extension! \n";
        die();
    }
}
if ($database != null) {
    if (strpos($database, '.sql') == false) {
        echo "Database must be in .sql format and extension! \n";
        die();
    }
}
// Check the backup files exist...
if ($options != "--backup") {
    if (!file_exists($moodledata)) {
        die("Cannot find ".$moodledata."! Please check the path and try again. \n");
    }
    if (!file_exists($database)) {
        die("Cannot find ".$database."! Please check the path and try again. \n");
    }
}

if ($restore === true) { // Restore!
    echo $introRestore;
    // Setting Maintenance Mode on
    shell_exec("php ../admin/cli/maintenance.php --enable");
    // Set temp index.php
    rename($moodleIndex, $backupIndex); // Rename index.php temporarily
    $tmpIndex = fopen($moodleIndex, "w") or die("Unable to open file!");
    fwrite($tmpIndex, $htmlText);
    fclose($tmpIndex);
    // Check files exist
    if ($database != null) {
        $database = $database;
    } elseif (file_exists($database)) {
        echo $database . " found!\n";
    } else {
        echo $database . " not found!\n";
        die();
    }
    if ($moodledata != null) {
        $moodledata = $moodledata;
    } elseif (file_exists($moodledata)) {
        echo $moodledata . " found!\n";
    } else {
        echo $moodledata . " not found!\n";
        die();
    }
    // Connect to MySQL Database
    $dbconnect = new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
    if ($dbconnect) {
        echo "\nConnected to Database\n";
    } else {
        echo "Couldn't connect to database!\n" . mysqli_get_host_info($dbconnect) . PHP_EOL;
    }
    // Dump all tables of database
    echo "Dropping all tables from Database!";
    $dbconnect->query('SET foreign_key_checks = 0');
    if ($result = $dbconnect->query("SHOW TABLES")) {
        while($row = $result->fetch_array(MYSQLI_NUM)) {
            $dbconnect->query('DROP TABLE IF EXISTS '.$row[0]);
        }
    }
    $dbconnect->query('SET foreign_key_checks = 1');
    echo "\nDrop done!\n";

    echo "\nImporting backup database...\n";
    importDatabase($CFG->dbhost,$CFG->dbuser,$CFG->dbpass,$CFG->dbname,$database);
    echo "\nDatabase Imported!\n";

    // Remove current Moodledata
    if ($fulldir != null) { // Check $dir isn't still null
        echo "\nRemoving ".$fulldir,"\n";
        shell_exec ("rm -Rf ".$fulldir);
    } else {
        echo "\nCannot find ".$fulldir."! This shouldn't happen...\n";
    }

    // Untar Moodledata backup to $CFG->dataroot location
    echo "Extracting backed up Data\n";
    shell_exec("tar -xzf ".$moodledata); // Extract to backup folder
    echo "Moving Data directory to correct location\n";
    shell_exec("mv ".$datadir." ".$fulldir); // Move into place...;
    echo "Fixing permissions of ".$datadir."...\n";
    shell_exec ("chmod -R 777 ".$fulldir);
    echo "Fixing SELinux permissions on ".$datadir."...\n";
    shell_exec ("semanage fcontext -a -t httpd_sys_content_t ".$fulldir);
    shell_exec ("restorecon -R ".$fulldir);

    // If update flag exists, Update the site
    if ($update == true) {
        shell_exec("cd ".$dir);
        echo "Updating site...";
        shell_exec("git pull");
        shell_exec("php ../admin/cli/upgrade.php --non-interactive");
        echo "Updated!";
    }

    // Remove temp index.php
    unlink($moodleIndex);
    rename($backupIndex, $moodleIndex); // Rename index.php temporarily

    // Take out of Maintenance Mode
    shell_exec("php ../admin/cli/maintenance.php --disable");
    echo "\nDone!\n";
}
if ($backup == true) { // Backups!
    echo $introBackup;
    // Setting Maintenance Mode on
    shell_exec("php ../admin/cli/maintenance.php --enable");
    echo "Backing up site...\n";
    if (file_exists($database)) {
        unlink($database);
    }
    if (file_exists($moodledata)) {
        unlink($moodledata);
    }
    echo "Dumping Database...\n";
    //dumpDatabase($CFG->dbhost,$CFG->dbuser,$CFG->dbpass,$CFG->dbname,$tables=false,$database);
    shell_exec("mysqldump -u".$CFG->dbuser." -p".$CFG->dbpass." ".$CFG->dbname." > dump.sql");
    echo "Backing up ".$datadir."...\n";
    shell_exec ("cd ".$dir." && tar -czf /tmp/".$moodledata." ".$datadir." --exclude '".$datadir."/sessions' --exclude '".$datadir."/trashdir'");
    shell_exec ("cd ".$dir." && mv /tmp/".$moodledata." ".$home);
    shell_exec ("cd ".$home);
    // Take out of Maintenance Mode
    shell_exec("php ../admin/cli/maintenance.php --disable");
    echo "Done!\n";
}
