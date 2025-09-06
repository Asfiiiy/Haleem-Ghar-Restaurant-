<?php
function get_db_connection() {
    static $conn = null;

    if ($conn === null) {
        $serverName = "185.255.131.44";
        $connectionOptions = [
            "Database" => "",
            "Uid" => "",
            "PWD" => '',
            "CharacterSet" => ""
        ];

        $conn = sqlsrv_connect($serverName, $connectionOptions);

        if ($conn === false) {
            // Don't exit here, let the calling code handle the error
            return false;
        }
    }

    return $conn;
}
?>
